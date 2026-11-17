// Makes the help videos of the admin panel: short, silent screen recordings of the real panel with the steps written across the top.
//
//   1. Run the backend on a SCRATCH database with the demo data (never on a real shop: the videos add a product, a category, a discount, a coupon and
//      change a few settings):   php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve --port=8012
//   2. Build the panel:          cd admin-frontend && npm run build
//   3. Make the videos:          HELP_BASE=http://127.0.0.1:8012 FFMPEG=/path/to/ffmpeg node tools/help-videos/make.mjs [slug ...]
//
// It writes admin-frontend/public/help/<slug>.mp4 + <slug>.jpg (the poster) and videos.json (titles, written steps, lengths: the Help screen reads it).
// Build the panel again afterwards so the files are copied to backend/public/admin-assets/help. A video is about a minute and about a megabyte.
import fs from 'node:fs'
import path from 'node:path'
import { launch, Recorder, ROOT, samplePictures, sleep } from './lib.mjs'
import { FLOWS } from './flows.mjs'
import { startSink } from './smtp-sink.mjs'

const BASE = (process.env.HELP_BASE || 'http://127.0.0.1:8012').replace(/\/$/, '')
const OUT = process.env.HELP_OUT || path.join(ROOT, 'public', 'help')
// the catalogue (products, categories) belongs to the developer account; the owner's account does the rest, so each video is recorded as the right one
const ACCOUNTS = { developer: [process.env.HELP_DEV_USER || 'developer', process.env.HELP_DEV_PASS || 'developer'], admin: [process.env.HELP_USER || 'admin', process.env.HELP_PASS || 'admin'] }
const only = process.argv.slice(2)
const url = (p) => `${BASE}/admin/${p}`

// ---------------------------------------------------------------------------------------------------------------------------------- main
async function main() {
  const browser = await launch()
  const pictures = await samplePictures(browser, path.join(ROOT, 'tools', 'help-videos', '.tmp'))

  // one signed-in browser session per account (not recorded)
  const sessions = {}
  const session = async (who) => {
    if (sessions[who]) return sessions[who]
    const ctx = await browser.createBrowserContext()
    const page = await ctx.newPage()
    await page.goto(url('login'), { waitUntil: 'networkidle0' })
    await page.evaluate(() => { localStorage.setItem('admin_sound_muted', '1'); localStorage.removeItem('admin_theme') })
    await page.type('#username', ACCOUNTS[who][0])
    await page.type('#password', ACCOUNTS[who][1])
    await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
    await page.close()
    return (sessions[who] = ctx)
  }

  const listFile = path.join(OUT, 'videos.json')
  const known = fs.existsSync(listFile) ? JSON.parse(fs.readFileSync(listFile, 'utf8')).videos || [] : []
  const made = new Map(known.map((v) => [v.slug, v]))

  for (const flow of FLOWS) {
    if (only.length && !only.includes(flow.slug)) continue
    console.log(`recording ${flow.slug} ...`)
    const sink = flow.needs === 'smtp' ? await startSink(Number(process.env.HELP_SMTP_PORT || 2525)) : null
    const r = await Recorder.open(await session(flow.as || 'admin'))
    await r.page.goto(url(flow.start || ''), { waitUntil: 'networkidle0' })
    await sleep(600)
    await r.start()
    try {
      await flow.run(r, { pictures, smtpPort: sink?.port })
    } catch (e) {
      console.log(`  FAILED: ${e.message}`)
      await r.page.screenshot({ path: path.join(ROOT, 'tools', 'help-videos', `.failed-${flow.slug}.png`) }).catch(() => {})
      await r.page.close()
      await sink?.close()
      continue
    }
    const meta = await r.finish({ slug: flow.slug, outDir: OUT })
    await r.page.close()
    await sink?.close()
    made.set(flow.slug, { slug: flow.slug, title: flow.title, summary: flow.summary, topic: flow.topic || null, can: flow.can || null, open: flow.open || null, duration: meta.duration, bytes: meta.bytes, steps: meta.steps })
    console.log(`  ${flow.slug}: ${meta.duration}s, ${(meta.bytes / 1048576).toFixed(2)} MB, ${meta.steps.length} steps`)
  }

  const ordered = FLOWS.map((f) => made.get(f.slug)).filter(Boolean)
  fs.mkdirSync(OUT, { recursive: true })
  fs.writeFileSync(listFile, JSON.stringify({ videos: ordered }, null, 2) + '\n')
  fs.rmSync(path.join(ROOT, 'tools', 'help-videos', '.tmp'), { recursive: true, force: true })
  await browser.close()
}

main().catch((e) => { console.error(e); process.exit(1) })

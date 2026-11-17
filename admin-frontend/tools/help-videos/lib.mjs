// A tiny screen recorder for the admin panel's help videos. It drives the REAL panel in headless Chrome (a visible mouse pointer, a pulse where it
// clicks, a ring round what it is about to use) and writes a step banner across the top of the picture, then turns the frames into a small, silent MP4.
//
// Needs: Chrome (CHROME_PATH), ffmpeg (FFMPEG, or `ffmpeg` on the PATH), and puppeteer-core (taken from admin-frontend/node_modules).
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = path.dirname(fileURLToPath(import.meta.url))
export const ROOT = path.resolve(here, '..', '..')   // the admin panel repository (this folder is tools/help-videos)
const pptr = path.join(ROOT, 'node_modules', 'puppeteer-core', 'lib', 'puppeteer', 'puppeteer-core.js')
export const puppeteer = (await import(pathToFileURL(pptr).href)).default

export const W = 1280
export const H = 760          // 60 px of banner + the panel at 1280 x 700
export const BANNER = 60
export const FPS = 15
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
export const FFMPEG = process.env.FFMPEG || 'ffmpeg'

// ---------------------------------------------------------------------------------------------------------------------------------- the overlay
// Injected into every page: a pointer, a click pulse, a ring, the step banner (it pushes the panel down by 60 px) and a title card.
const OVERLAY = `(() => {
  if (window.__hv) return
  window.__hv = true
  const css = document.createElement('style')
  css.textContent = \`
    body { margin-top: ${BANNER}px !important; }
    .sidebar { top: ${BANNER}px !important; }
    .topbar { top: ${BANNER}px !important; }
    #hv-banner { position: fixed; left: 0; right: 0; top: 0; height: ${BANNER}px; z-index: 2147483600; display: flex; align-items: center; gap: 16px; padding: 0 28px; background: #0f172a; color: #fff; font: 600 20px/1.2 Poppins, system-ui, sans-serif; box-shadow: 0 2px 10px rgba(0,0,0,.25); }
    #hv-banner b { flex: none; min-width: 34px; height: 34px; border-radius: 17px; padding: 0 10px; display: grid; place-items: center; background: #38bdf8; color: #082f49; font-size: 15px; }
    #hv-banner span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; transition: opacity .25s; }
    #hv-cursor { position: fixed; left: 0; top: 0; width: 26px; height: 26px; z-index: 2147483640; pointer-events: none; transform: translate(-100px, -100px); filter: drop-shadow(0 2px 3px rgba(0,0,0,.45)); }
    .hv-pulse { position: fixed; z-index: 2147483639; width: 14px; height: 14px; margin: -7px 0 0 -7px; border-radius: 50%; border: 3px solid #38bdf8; pointer-events: none; animation: hv-pulse .55s ease-out forwards; }
    @keyframes hv-pulse { to { transform: scale(4.2); opacity: 0; } }
    #hv-ring { position: fixed; z-index: 2147483638; pointer-events: none; border: 3px solid #f59e0b; border-radius: 10px; box-shadow: 0 0 0 5px rgba(245,158,11,.25); opacity: 0; transition: opacity .2s; }
    #hv-title { position: fixed; inset: 0; z-index: 2147483647; display: grid; place-content: center; text-align: center; gap: 14px; padding: 40px; background: linear-gradient(135deg, #0f172a, #1e3a5f); color: #fff; font-family: Poppins, system-ui, sans-serif; transition: opacity .5s; }
    #hv-title h1 { margin: 0; font-size: 54px; line-height: 1.12; font-weight: 700; }
    #hv-title p { margin: 0; font-size: 22px; color: #94a3b8; }
  \`
  // the elements are made as soon as the page has a root element (this script also runs before it exists)
  let banner, cursor, ring
  const boot = () => {
    if (banner || !document.documentElement) return
    document.documentElement.appendChild(css)
    banner = document.createElement('div'); banner.id = 'hv-banner'; banner.innerHTML = '<b></b><span></span>'; banner.style.display = 'none'
    cursor = document.createElement('div'); cursor.id = 'hv-cursor'
    cursor.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26"><path d="M4 2l16 9-7 2 4 7-3 1-4-7-5 5z" fill="#fff" stroke="#0f172a" stroke-width="1.6" stroke-linejoin="round"/></svg>'
    ring = document.createElement('div'); ring.id = 'hv-ring'
    document.documentElement.append(banner, cursor, ring)
  }
  boot()
  document.addEventListener('DOMContentLoaded', boot)
  addEventListener('mousemove', (e) => { boot(); if (cursor) cursor.style.transform = 'translate(' + (e.clientX - 5) + 'px,' + (e.clientY - 3) + 'px)' }, true)
  addEventListener('mousedown', (e) => { boot(); const p = document.createElement('div'); p.className = 'hv-pulse'; p.style.left = e.clientX + 'px'; p.style.top = e.clientY + 'px'; document.documentElement.appendChild(p); setTimeout(() => p.remove(), 700) }, true)
  window.__hvCaption = (n, text) => { boot(); banner.style.display = text ? 'flex' : 'none'; banner.firstChild.textContent = n; banner.lastChild.textContent = text }
  window.__hvRing = (r) => { boot(); if (!r) { ring.style.opacity = 0; return } ring.style.left = (r.x - 6) + 'px'; ring.style.top = (r.y - 6) + 'px'; ring.style.width = (r.w + 12) + 'px'; ring.style.height = (r.h + 12) + 'px'; ring.style.opacity = 1 }
  window.__hvTitle = (title, sub) => { boot(); document.getElementById('hv-title')?.remove(); if (title === null) return; const t = document.createElement('div'); t.id = 'hv-title'; t.innerHTML = '<h1></h1><p></p>'; t.firstChild.textContent = title; t.lastChild.textContent = sub || ''; document.documentElement.appendChild(t) }
})()`

// ---------------------------------------------------------------------------------------------------------------------------------- the recorder
export class Recorder {
  constructor(page, client) {
    this.page = page
    this.client = client
    this.frames = []
    this.x = 40
    this.y = 120
    this.steps = []
    this.times = []   // when each step began (seconds on the wall clock); turned into a time in the video at the end
    this.n = 0
  }

  static async open(browser) {
    const page = await browser.newPage()
    await page.setViewport({ width: W, height: H, deviceScaleFactor: 1 })
    await page.evaluateOnNewDocument(OVERLAY)
    const client = await page.createCDPSession()
    return new Recorder(page, client)
  }

  async overlay() { await this.page.evaluate(OVERLAY) }

  /** Start capturing the screen. */
  async start() {
    await this.overlay()
    this.client.on('Page.screencastFrame', async ({ data, metadata, sessionId }) => {
      this.frames.push({ data: Buffer.from(data, 'base64'), ts: metadata.timestamp })
      try { await this.client.send('Page.screencastFrameAck', { sessionId }) } catch { /* the page is closing */ }
    })
    await this.client.send('Page.startScreencast', { format: 'jpeg', quality: 82, maxWidth: W, maxHeight: H, everyNthFrame: 1 })
    await this.page.evaluate(() => window.scrollBy(0, 0))
    await sleep(300)
  }

  /** A title card, shown for a moment and faded away. */
  async title(title, sub = '') {
    await this.page.evaluate((t, s) => window.__hvTitle(t, s), title, sub)
    await sleep(2300)
    await this.page.evaluate(() => { const t = document.getElementById('hv-title'); if (t) t.style.opacity = 0 })
    await sleep(550)
    await this.page.evaluate(() => window.__hvTitle(null))
  }

  /** The banner text of the next actions; it is also the written step under the video. */
  async step(text, { pause = 1100 } = {}) {
    this.n += 1
    this.steps.push(text)
    this.times.push(Date.now() / 1000)
    await this.page.evaluate((n, t) => window.__hvCaption(n, t), String(this.n), text)
    await sleep(pause)
  }

  hold(ms = 800) { return sleep(ms) }

  async goto(url) {
    await this.page.goto(url, { waitUntil: 'networkidle0' })
    await this.overlay()
    await this.page.evaluate((n, t) => { if (t) window.__hvCaption(n, t) }, String(this.n), this.steps[this.steps.length - 1] || '')
    await sleep(500)
  }

  // ---- finding things: a CSS selector, or { text: 'Add product', in: 'button, a' } (the first visible one that has this text)
  async locate(spec) {
    const handle = typeof spec === 'string'
      ? await this.page.waitForSelector(spec, { visible: true, timeout: 15000 })
      : await this.page.waitForFunction((t, within, nth) => {
        const list = [...document.querySelectorAll(within || 'button, a, [role="tab"], label, summary')].filter((e) => {
          const r = e.getBoundingClientRect()
          return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden' && (e.innerText || e.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase().includes(t.toLowerCase())
        })
        return list[nth || 0] || false
      }, { timeout: 15000 }, spec.text, spec.in, spec.nth)
    return handle.asElement()
  }

  async glide(x, y) {
    const dist = Math.hypot(x - this.x, y - this.y)
    const n = Math.max(14, Math.min(46, Math.round(dist / 22)))
    const sx = this.x
    const sy = this.y
    for (let i = 1; i <= n; i++) {
      const t = i / n
      const e = t < 0.5 ? 2 * t * t : 1 - ((-2 * t + 2) ** 2) / 2   // ease in and out
      await this.page.mouse.move(sx + (x - sx) * e, sy + (y - sy) * e)
      await sleep(14)
    }
    this.x = x
    this.y = y
  }

  async reveal(el) {
    await el.evaluate((e) => e.scrollIntoView({ block: 'center', behavior: 'smooth' }))
    await sleep(650)
  }

  async ring(el, ms = 650) {
    const r = await el.evaluate((e) => { const b = e.getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height } })
    await this.page.evaluate((box) => window.__hvRing(box), r)
    await sleep(ms)
    return r
  }

  /** Move to it, ring it, click it. */
  async click(spec, { pause = 700, offset } = {}) {
    const el = await this.locate(spec)
    await this.reveal(el)
    const box = await el.boundingBox()
    await this.glide(box.x + (offset?.x ?? box.width / 2), box.y + (offset?.y ?? box.height / 2))
    await this.ring(el, 450)
    await this.page.mouse.down()
    await sleep(80)
    await this.page.mouse.up()
    await this.page.evaluate(() => window.__hvRing(null))
    await sleep(pause)
  }

  async type(spec, text, { clear = false, pause = 600 } = {}) {
    const el = await this.locate(spec)
    await this.reveal(el)
    const box = await el.boundingBox()
    await this.glide(box.x + Math.min(box.width / 2, 120), box.y + box.height / 2)
    await this.page.mouse.down(); await sleep(60); await this.page.mouse.up()
    if (clear) { await el.evaluate((e) => { e.select?.() }); await this.page.keyboard.press('Backspace') }
    await this.page.keyboard.type(text, { delay: 55 })
    await sleep(pause)
  }

  /** The panel's own drop-down list: open it, then choose the entry with this text. */
  async choose(selectSpec, optionText, { pause = 650 } = {}) {
    await this.click(selectSpec, { pause: 450 })
    await this.page.waitForSelector('.pop--list', { visible: true, timeout: 8000 })
    await sleep(350)
    await this.click({ text: optionText, in: '.pop__opt' }, { pause })
  }

  async upload(inputSelector, files, { pause = 900 } = {}) {
    const input = await this.page.waitForSelector(inputSelector, { timeout: 15000 })
    await input.uploadFile(...files)
    await sleep(pause)
  }

  async scroll(y, pause = 700) {
    await this.page.evaluate((v) => window.scrollTo({ top: v, behavior: 'smooth' }), y)
    await sleep(pause)
  }

  // ---- finishing
  async finish({ slug, outDir }) {
    await sleep(900)
    await this.client.send('Page.stopScreencast')
    await sleep(200)
    fs.mkdirSync(outDir, { recursive: true })
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), `hv-${slug}-`))
    const frames = this.frames
    if (frames.length < 2) throw new Error(`${slug}: nothing was recorded`)
    const lines = []
    frames.forEach((f, i) => {
      const name = `f${String(i).padStart(6, '0')}.jpg`
      fs.writeFileSync(path.join(tmp, name), f.data)
      const next = frames[i + 1]?.ts ?? f.ts + 1.2
      lines.push(`file '${name}'`, `duration ${Math.max(0.02, next - f.ts).toFixed(4)}`)
    })
    lines.push(`file 'f${String(frames.length - 1).padStart(6, '0')}.jpg'`)
    fs.writeFileSync(path.join(tmp, 'list.txt'), lines.join('\n'))
    const mp4 = path.join(outDir, `${slug}.mp4`)
    const run = (args) => { const r = spawnSync(FFMPEG, args, { cwd: tmp, encoding: 'utf8' }); if (r.status !== 0) throw new Error(`ffmpeg failed: ${(r.stderr || '').slice(-600)}`); return r }
    run(['-y', '-hide_banner', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', 'list.txt', '-vf', `fps=${FPS},scale=${W}:${H}:flags=lanczos,format=yuv420p`, '-c:v', 'libx264', '-preset', 'slow', '-crf', '31', '-r', String(FPS), '-movflags', '+faststart', '-an', mp4])
    const duration = frames[frames.length - 1].ts - frames[0].ts + 1.2
    // the picture shown before play: a frame from the middle of the video (the panel with its step banner), not the title card
    run(['-y', '-hide_banner', '-loglevel', 'error', '-ss', (duration * 0.4).toFixed(1), '-i', mp4, '-frames:v', '1', '-vf', 'scale=640:-2', '-q:v', '5', path.join(outDir, `${slug}.jpg`)])
    fs.rmSync(tmp, { recursive: true, force: true })
    const steps = this.steps.map((text, i) => ({ text, at: Math.max(0, Math.round((this.times[i] - frames[0].ts) * 10) / 10) }))
    return { slug, duration: Math.round(duration * 10) / 10, bytes: fs.statSync(mp4).size, steps }
  }
}

// ---------------------------------------------------------------------------------------------------------------------------------- sample pictures
/** Two colourful square product pictures drawn by Chrome itself (no files needed). Returns their paths. */
export async function samplePictures(browser, dir) {
  fs.mkdirSync(dir, { recursive: true })
  const page = await browser.newPage()
  await page.setViewport({ width: 800, height: 800 })
  const designs = [
    ['train-1', 'linear-gradient(160deg,#fde68a,#fb923c)', '<div style="position:absolute;left:120px;top:330px;width:560px;height:200px;background:#ef4444;border-radius:26px"></div><div style="position:absolute;left:160px;top:260px;width:200px;height:130px;background:#3b82f6;border-radius:18px"></div><div style="position:absolute;left:210px;top:490px;width:110px;height:110px;background:#1e293b;border-radius:50%"></div><div style="position:absolute;left:480px;top:490px;width:110px;height:110px;background:#1e293b;border-radius:50%"></div><div style="position:absolute;left:430px;top:210px;width:150px;height:150px;background:#facc15;border-radius:20px"></div>'],
    ['train-2', 'linear-gradient(160deg,#bae6fd,#a5b4fc)', '<div style="position:absolute;left:100px;top:300px;width:260px;height:180px;background:#22c55e;border-radius:24px"></div><div style="position:absolute;left:380px;top:300px;width:260px;height:180px;background:#f43f5e;border-radius:24px"></div><div style="position:absolute;left:150px;top:460px;width:100px;height:100px;background:#0f172a;border-radius:50%"></div><div style="position:absolute;left:500px;top:460px;width:100px;height:100px;background:#0f172a;border-radius:50%"></div><div style="position:absolute;left:300px;top:200px;width:180px;height:180px;background:#fff;border-radius:50%;opacity:.85"></div>'],
  ]
  const out = []
  for (const [name, bg, body] of designs) {
    await page.setContent(`<body style="margin:0"><div style="position:relative;width:800px;height:800px;background:${bg};overflow:hidden">${body}</div></body>`)
    const file = path.join(dir, `${name}.png`)
    await page.screenshot({ path: file })
    out.push(file)
  }
  await page.close()
  return out
}

export async function launch() {
  if (!CHROME) throw new Error('Chrome not found: set CHROME_PATH')
  return puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox', '--hide-scrollbars', '--force-device-scale-factor=1', '--autoplay-policy=no-user-gesture-required'], defaultViewport: null })
}

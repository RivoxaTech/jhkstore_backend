// Headless-Chrome screenshots of the admin panel for a visual check (Dashboard, Settings tab by tab as the developer and as the admin, Notifications,
// Access Control, Orders, a collapsed sidebar and a phone width). Nothing is asserted: look at the pictures.
//
//   cd backend && php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve --port=8002
//   E2E_BASE=http://127.0.0.1:8002 SHOTS_DIR=some/folder node e2e/screenshots.mjs
//
// It places a few orders through the storefront API (so the dashboard has numbers), uploads a logo with the developer, and writes PNG files.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'
import path from 'node:path'
import zlib from 'node:zlib'

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8002'
const OUT = process.env.SHOTS_DIR || path.join(process.cwd(), 'shots')
const ADMIN = [process.env.E2E_ADMIN_USER || 'admin', process.env.E2E_ADMIN_PASSWORD || 'admin']
const DEV = [process.env.E2E_DEV_USER || 'developer', process.env.E2E_DEV_PASSWORD || 'developer']
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
if (!CHROME) { console.error('Chrome not found: set CHROME_PATH'); process.exit(2) }
fs.mkdirSync(OUT, { recursive: true })

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const json = async (method, url, body, token, extra = {}) => {
  for (let attempt = 0; attempt < 3; attempt++) {
    try { return await jsonOnce(method, url, body, token, extra) } catch (e) { await sleep(400) }
  }
  return { status: 0, json: null }
}
const jsonOnce = async (method, url, body, token, extra = {}) => {
  const r = await fetch(`${BASE}/api/v1/${url}`, { method, headers: { Accept: 'application/json', Connection: 'close', ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...extra }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, json: await r.json().catch(() => null) }
}

// a logo for the test: a 160 x 48 PNG (a deep-blue rounded bar with a lighter stripe), built here so no file is needed
function makePng(w, h) {
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
  const crc = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0 }
  const chunk = (type, data) => { const t = Buffer.from(type); const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const c = Buffer.alloc(4); c.writeUInt32BE(crc(Buffer.concat([t, data]))); return Buffer.concat([len, t, data, c]) }
  const raw = Buffer.alloc((w * 4 + 1) * h)
  for (let y = 0; y < h; y++) {
    raw[y * (w * 4 + 1)] = 0
    for (let x = 0; x < w; x++) {
      const i = y * (w * 4 + 1) + 1 + x * 4
      const inside = Math.hypot(Math.max(0, Math.abs(x - w / 2) - (w / 2 - 14)), Math.max(0, Math.abs(y - h / 2) - (h / 2 - 14))) <= 14
      const stripe = y > h * 0.62 && y < h * 0.74 && x > 24 && x < w - 24
      raw.set(inside ? (stripe ? [147, 197, 253, 255] : [30, 64, 175, 255]) : [0, 0, 0, 0], i)
    }
  }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 6
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}
const LOGO = makePng(160, 48)
async function seedOrders() {
  const list = (await json('GET', 'store/products?per_page=12')).json
  const variants = []
  for (const p of list?.data || []) {
    const detail = (await json('GET', `store/products/${p.slug}`)).json
    if (detail?.data?.variants?.[0]?.id) variants.push(detail.data.variants[0].id)
  }
  if (!variants.length) { console.log('no products to order (run the DemoSeeder)'); return }
  const names = ['Amina Khan', 'Bilal Ahmed', 'Sara Malik', 'Omar Farooq', 'Hira Noor', 'Zain Ali', 'Maryam Iqbal', 'Daniyal Shah']
  for (let i = 0; i < 8; i++) {
    await json('POST', 'store/orders', { customer: { name: names[i], phone: `+92 300 55500${i}${i}`, email: `buyer${i}@example.test` }, address: { line1: `${i + 1} Test Road`, city: 'Lahore' }, items: [{ variant_id: variants[i % variants.length], qty: 1 + (i % 3) }], payment_method: i % 3 === 0 ? 'cod' : 'cod' }, null, { 'Idempotency-Key': `shots-${Date.now()}-${i}-xxxxxxxx` })
  }
}

async function shot(page, name, { full = true, wait = 700 } = {}) {
  await sleep(wait)
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: full })
  console.log('shot', name)
}

async function login(page, [user, pass]) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await page.type('#username', user)
  await page.type('#password', pass)
  await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
}

async function go(page, route, ms = 900) {
  await page.goto(`${BASE}/admin${route}`, { waitUntil: 'networkidle0' })
  await sleep(ms)
}

async function tabsOf(page) {
  return page.$$eval('[role="tab"]', (els) => els.map((e) => e.id.replace('tab-', '')).filter(Boolean))
}

async function run(who, creds, width = 1366) {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width, height: 900 } })
  const page = await browser.newPage()
  const problems = []
  page.on('pageerror', (e) => problems.push(`page error: ${e.message}`))
  page.on('console', (m) => { if (m.type() === 'error' && !/401|403|422/.test(m.text())) problems.push(`console: ${m.text()}`) })
  await login(page, creds)
  const prefix = `${who}${width < 700 ? '-phone' : ''}`

  await go(page, '/')
  await shot(page, `${prefix}-dashboard`)
  if (who === 'admin' && width >= 700) {
    await go(page, '/?range=7d')
    await shot(page, `${prefix}-dashboard-7d`)
    await go(page, '/orders')
    await shot(page, `${prefix}-orders`)
  }

  await go(page, '/settings')
  const tabs = await tabsOf(page)
  console.log(`${who} tabs:`, tabs.join(', '))
  for (const tab of tabs) {
    await go(page, `/settings?tab=${tab}`, 1100)
    await shot(page, `${prefix}-settings-${tab}`)
  }
  if (who === 'developer' && width >= 700) {
    await go(page, '/access-control')
    await shot(page, `${prefix}-access-control`)
    await page.$$eval('.ac__fields summary', (els) => els.slice(0, 3).forEach((e) => e.click()))
    await shot(page, `${prefix}-access-control-open`)
  }
  if (width >= 700) {
    await page.click('[data-testid="collapse-toggle"]')
    await go(page, '/', 700)
    await shot(page, `${prefix}-collapsed`, { full: false })
  }
  console.log(`${who} problems:`, problems.length ? problems : 'none')
  await browser.close()
}

if (process.env.SKIP_SEED !== '1') await seedOrders()

// the developer uploads a logo (so the sidebar and the login page show it)
const login1 = await json('POST', 'admin/auth/login', { login: DEV[0], password: DEV[1] })
const token = login1.json?.token || login1.json?.data?.token
if (token) {
  const fd = new FormData()
  fd.append('logo', new Blob([LOGO], { type: 'image/png' }), 'logo.png')
  const r = await fetch(`${BASE}/api/v1/admin/settings/logo`, { method: 'POST', headers: { Accept: 'application/json', Connection: 'close', Authorization: `Bearer ${token}` }, body: fd })
  await r.arrayBuffer()   // read the body: an unread response makes undici assert when the server closes the socket
  console.log('logo upload', r.status)
}

await run('developer', DEV)
await run('admin', ADMIN)
await run('admin', ADMIN, 390)

// the login page with a logo
{
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width: 1366, height: 800 } })
  const page = await browser.newPage()
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await shot(page, 'login', { full: false })
  console.log('title:', await page.title())
  await browser.close()
}

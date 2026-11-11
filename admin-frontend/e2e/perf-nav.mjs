// How fast does the admin panel feel? Drives the BUILT panel in headless Chrome (puppeteer-core) like a person: login, then click through the screens.
//
//   cd admin-frontend && npm run build && E2E_BASE=http://127.0.0.1:8012 node e2e/perf-nav.mjs [--json out.json]
//
// For every screen it reports, for the FIRST visit and the SECOND visit (clicking away and back):
//   shown   ms from the click until the screen has its heading and no skeleton / spinner (what the person experiences)
//   settled ms from the click until the last data call of the screen finished (the fresh answer has arrived)
//   calls   how many API calls the screen made (the 5-second badge polls are not counted)
// Nothing is changed in the shop: it only reads. Environment: E2E_BASE, E2E_ADMIN_USER / E2E_ADMIN_PASSWORD (default admin / admin), CHROME_PATH.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8002'
const ADMIN = [process.env.E2E_ADMIN_USER || 'admin', process.env.E2E_ADMIN_PASSWORD || 'admin']
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium'].find((p) => fs.existsSync(p))
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const POLL = /badges|pending-count|new-count|store\/settings|auth\/me/

const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width: 1366, height: 900 } })
const page = await browser.newPage()
const calls = []
page.on('request', (r) => { if (r.url().includes('/api/v1/')) r.__t0 = performance.now(), calls.push({ url: r.url().replace(/^.*\/api\/v1\//, ''), method: r.method(), t0: performance.now(), t1: null, status: null, st: null, req: r }) })
page.on('response', (r) => { const c = calls.find((x) => x.req === r.request()); if (c) { c.status = r.status(); c.st = r.headers()['server-timing'] || null } })
page.on('requestfinished', (r) => { const c = calls.find((x) => x.req === r); if (c) c.t1 = performance.now() })

await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
await page.$eval('#username', (el, v) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, v); el.dispatchEvent(new Event('input', { bubbles: true })) }, ADMIN[0])
await page.$eval('#password', (el, v) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, v); el.dispatchEvent(new Event('input', { bubbles: true })) }, ADMIN[1])
const tLogin = performance.now()
await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
console.log(`login -> sidebar: ${(performance.now() - tLogin).toFixed(0)} ms`)
await sleep(1500)

// a step = something to click, and the path it should end on
const SIDEBAR = (href) => async () => { await page.evaluate((h) => document.querySelector(`.sidebar__nav a[href="${h}"]`)?.click(), href) }
const steps = [
  ['Dashboard', '/admin', SIDEBAR('/admin/')],
  ['Orders', '/admin/orders', SIDEBAR('/admin/orders')],
  ['Order detail', /\/admin\/orders\/\d+$/, async () => { await page.evaluate(() => document.querySelector('table a[href*="/admin/orders/"], tbody a[href*="/orders/"]')?.click()) }],
  ['Customers', '/admin/customers', SIDEBAR('/admin/customers')],
  ['Wholesale', '/admin/wholesale', SIDEBAR('/admin/wholesale')],
  ['Discounts', '/admin/discounts', SIDEBAR('/admin/discounts')],
  ['Coupons', '/admin/coupons', SIDEBAR('/admin/coupons')],
  ['Settings', '/admin/settings', SIDEBAR('/admin/settings')],
]
steps.splice(3, 0, ['Products', '/admin/products', SIDEBAR('/admin/products')], ['Categories', '/admin/categories', SIDEBAR('/admin/categories')])
// E2E_STEPS=Dashboard,Products,Categories,Discounts,Coupons,Settings is the developer account's sidebar (E2E_ADMIN_USER=developer); the admin account has no Products
const ONLY = process.env.E2E_STEPS ? process.env.E2E_STEPS.split(',') : ['Dashboard', 'Orders', 'Order detail', 'Customers', 'Wholesale', 'Discounts', 'Coupons', 'Settings']
steps.splice(0, steps.length, ...steps.filter(([n]) => ONLY.includes(n)))

async function measure(name, target, click) {
  const mark = performance.now()
  const before = calls.length
  await click()
  // ready = heading present, URL is the target, no skeleton / spinner on the screen
  const shown = await page.evaluate((t, src) => new Promise((resolve) => {
    const t0 = performance.now()
    const ok = () => {
      const path = location.pathname
      const at = src ? new RegExp(src).test(path) : path.replace(/\/$/, '') === t.replace(/\/$/, '')
      return at && document.querySelector('h1') && !document.querySelector('.skeleton, .spinner, .loading')
    }
    const tick = () => { if (ok()) resolve(performance.now() - t0); else if (performance.now() - t0 > 15000) resolve(-1); else requestAnimationFrame(tick) }
    tick()
  }), typeof target === 'string' ? target : '', typeof target === 'string' ? '' : target.source)
  await sleep(900)   // let the fresh answers arrive
  const mine = calls.slice(before).filter((c) => !POLL.test(c.url))
  const settled = mine.length ? Math.max(...mine.filter((c) => c.t1).map((c) => c.t1 - mark)) : 0
  return { name, shown, settled, calls: mine.length, list: mine.map((c) => `${c.method} ${c.url.split('?')[0]} ${c.status} ${c.st ? c.st.replace(/;dur=/g, '=').replace(/;desc=/g, ' ') : ''}`) }
}

const out = []
const header = (s) => console.log(`\n${s}`)
for (const pass of ['FIRST visit', 'SECOND visit']) {
  header(pass)
  for (const [name, target, click] of steps) {
    // the detail page needs the list in front of it
    const r = await measure(name, target, click)
    out.push({ pass, ...r })
    console.log(`${name.padEnd(14)} shown ${String(Math.round(r.shown)).padStart(5)} ms   settled ${String(Math.round(r.settled)).padStart(5)} ms   calls ${r.calls}${process.env.E2E_VERBOSE ? '\n      ' + r.list.join('\n      ') : ''}`)
  }
}
const j = process.argv.indexOf('--json')
if (j > 0) fs.writeFileSync(process.argv[j + 1], JSON.stringify(out, null, 1))
await browser.close()

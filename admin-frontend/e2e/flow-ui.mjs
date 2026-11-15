// Browser side of the flow check (headless Chrome through puppeteer-core): the admin screens driven like a person would, on the REAL app.
//
//   cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8002   (and run `node scripts/flow-check.mjs` first: it leaves orders to work on;
//                                                                    without them this script places a few itself)
//   cd admin-frontend && npm run build                             (admin panel -> backend/public/admin-assets)
//   npm run flow-ui
//
// Environment: E2E_BASE (default http://127.0.0.1:8000), E2E_ADMIN_USER / E2E_ADMIN_PASSWORD, E2E_DEV_USER / E2E_DEV_PASSWORD, CHROME_PATH.
// Phase B screens (UI-G1 to UI-G7): dashboard, badges, wholesale, products and the compact form, exports, Notifications and the push bell, time zone.
// For UI-G7 start a second server with APP_TIMEZONE=Pacific/Auckland (php artisan serve --port=8004) and set E2E_BASE_TZ=http://127.0.0.1:8004.
// Checks (ids as in docs/QA_REPORT.md): UI-A2 developer sees no orders, UI-A3 Access Control switches change the admin's screens, UI-B4/B5 a category,
// a sub-category and a product made in the forms, UI-D12..D14 order screens (note, every status step, shipping validation, cancel with stock return,
// invoice and packing slip in print view), UI-D15 customers search and CSV export, UI-E16 Settings General and a banner made in the panel.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import zlib from 'node:zlib'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const HERE = path.dirname(fileURLToPath(import.meta.url))
const REPO = path.resolve(HERE, '..', '..')
const ROOT = path.join(REPO, 'backend')   // the Laravel app: php artisan runs here
const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8002'
const ADMIN = [process.env.E2E_ADMIN_USER || 'admin', process.env.E2E_ADMIN_PASSWORD || 'admin']
const DEV = [process.env.E2E_DEV_USER || 'developer', process.env.E2E_DEV_PASSWORD || 'developer']
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
if (!CHROME) { console.error('Chrome not found: set CHROME_PATH'); process.exit(2) }
const OUT = fs.mkdtempSync(path.join(os.tmpdir(), 'flow-ui-'))
const ADMIN_DEFAULTS = ['dashboard.view', 'orders.view', 'orders.manage', 'orders.export', 'customers.view', 'customers.export', 'settings.general', 'settings.store', 'settings.delivery', 'settings.payments', 'settings.notifications', 'settings.website', 'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage', 'wholesale.view', 'wholesale.manage']

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
let failures = 0
const check = (ok, label, actual) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${actual === undefined ? '' : `  -> ${typeof actual === 'string' ? actual : JSON.stringify(actual)}`}`); if (!ok) failures++ }
const db = (sql) => JSON.parse(execFileSync('php', [path.join(REPO, 'scripts', 'flow-db.php'), sql], { cwd: ROOT, encoding: 'utf8' }))

// a flat PNG for the file inputs
function crc32(buf) { let c = 0xffffffff; for (const b of buf) { c ^= b; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1 } return (c ^ 0xffffffff) >>> 0 }
function chunk(type, data) { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const body = Buffer.concat([Buffer.from(type), data]); const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body)); return Buffer.concat([len, body, crc]) }
function writePng(file, w, h, [r, g, b]) {
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => [r, g, b]).flat())])
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2
  fs.writeFileSync(file, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.concat(Array.from({ length: h }, () => row)))), chunk('IEND', Buffer.alloc(0))]))
  return file
}

const api = async (method, route, body, token) => {
  const r = await fetch(`${BASE}/api/v1/${route}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined })
  const text = await r.text()
  let json = null
  try { json = JSON.parse(text) } catch { /* csv */ }
  return { status: r.status, json, text }
}
const tokenOf = async ([login, password]) => (await api('POST', 'admin/auth/login', { login, password })).json?.token

async function open() {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width: 1366, height: 900 } })
  const page = await browser.newPage()
  const problems = []
  page.on('pageerror', (e) => problems.push(`page error: ${e.message}`))
  page.on('console', (m) => { if (m.type() === 'error' && !/40[0-9]|422|429/.test(m.text())) problems.push(`console: ${m.text()}`) })
  page.on('dialog', (d) => d.accept().catch(() => { /* already handled */ }))
  page.__pending = new Set()   // requests still in flight: printed when a navigation times out
  page.on('request', (r) => page.__pending.add(r.url()))
  page.on('requestfinished', (r) => page.__pending.delete(r.url()))
  page.on('requestfailed', (r) => page.__pending.delete(r.url()))
  return { browser, page, problems }
}
async function login(page, [user, pass]) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await setValue(page, '#username', user)
  await setValue(page, '#password', pass)
  await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
}
const nav = (page) => page.$$eval('.sidebar__nav a', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').replace(/\d+$/, '').trim()))
const tabsOf = (page) => page.$$eval('[role="tab"]', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').replace(/[•!]$/, '').trim()))
const goto = async (page, p) => {
  for (let attempt = 1; ; attempt++) {
    try { await page.goto(`${BASE}/admin/${p}`, { waitUntil: 'networkidle0' }); break } catch (e) {
      console.log(`  (navigation to /admin/${p} did not go idle; requests in flight: ${JSON.stringify([...(page.__pending || [])])})`)
      // Chrome now and then swaps the page's frame during a full reload ("Navigating frame was detached"): the page itself is fine, so the
      // navigation is tried once more. Any other error, or a second failure, still fails the run.
      if (attempt === 1 && /frame was detached/i.test(String(e?.message))) { await sleep(1000); continue }
      throw e
    }
  }
  await sleep(400)
}
// toasts that are already on the screen are marked as seen before every click, so a wait for "saved" only matches the toast of THIS action
const markToasts = (page) => page.evaluate(() => document.querySelectorAll('.toast').forEach((t) => t.setAttribute('data-seen', '1')))
const tap = async (page, selector) => { await markToasts(page); await page.$eval(selector, (el) => el.click()) }
const tapText = async (page, selector, text) => { await markToasts(page); await page.evaluate((s, t) => { const el = [...document.querySelectorAll(s)].find((e) => e.textContent.trim().includes(t)); if (!el) throw new Error(`no ${s} with "${t}"`); el.click() }, selector, text) }
// Fills a field the way the browser does for Vue's v-model (native value + an `input` event). Real key presses (page.type) are not used on purpose:
// headless Chrome can drop key events after a page navigation when the machine's window focus is elsewhere, which made the script flaky.
const setValue = (page, selector, value) => page.$eval(selector, (el, v) => {
  const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
  Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, v)
  el.dispatchEvent(new Event('input', { bubbles: true }))
  el.dispatchEvent(new Event('change', { bubbles: true }))
}, String(value))
const toast = async (page, re, timeout = 15000) => { await page.waitForFunction((src) => [...document.querySelectorAll('.toast:not([data-seen])')].some((t) => new RegExp(src, 'i').test(t.innerText)), { timeout }, re.source) }
const bodyHas = (page, re) => page.evaluate((src) => new RegExp(src, 'i').test(document.body.innerText), re.source)

const devToken = await tokenOf(DEV)
const admToken = await tokenOf(ADMIN)
if (!devToken || !admToken) { console.error('Cannot log in: check the E2E_* credentials'); process.exit(2) }
await api('PUT', 'admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, devToken)

// Phase A flows change settings and make test records: remember what to put back
const SNAP_ADMIN_KEYS = ['pay_cod_enabled', 'pay_bank_enabled', 'pay_bank_accounts', 'pay_bank_instructions', 'delivery_method', 'delivery_unmatched', 'delivery_fee']
const snapValues = (await api('GET', 'admin/settings', null, admToken)).json?.values || {}
const snapAdmin = Object.fromEntries(SNAP_ADMIN_KEYS.filter((k) => k in snapValues).map((k) => [k, snapValues[k]]))
const devValues = (await api('GET', 'admin/settings', null, devToken)).json?.values || {}
const snapThemePrimary = devValues.theme_primary
const created = { discounts: [], coupons: [], orders: [] }
const RUN = Date.now().toString(36).toUpperCase()

const a = await open()   // client admin
const d = await open()   // developer
try {
  // ============================================================ A2: the developer sees no orders
  await login(d.page, DEV)
  check(JSON.stringify(await nav(d.page)) === JSON.stringify(['Dashboard', 'Products', 'Categories', 'Discounts', 'Coupons', 'Settings', 'Access Control', 'Help']), 'UI-A2 developer sidebar: no Orders, no Customers', await nav(d.page))
  check(!(await bodyHas(d.page, /Orders today|Revenue today|Pending orders|JHK-|ZED-/)), 'UI-A2 the developer dashboard shows no order figure, revenue or order number')
  await goto(d.page, 'orders')
  check(d.page.url().endsWith('/forbidden'), 'UI-A2 /admin/orders shows "no access" to the developer')

  // ============================================================ A3: switches change the admin's screens
  await login(a.page, ADMIN)
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Wholesale', 'Customers', 'Discounts', 'Coupons', 'Settings', 'Help']), 'UI-A3 admin starts with Dashboard, Orders, Customers, Wholesale, Discounts, Coupons, Settings', await nav(a.page))
  await goto(a.page, 'products')
  check(a.page.url().endsWith('/forbidden'), 'UI-A3 admin cannot open Products by default')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-testid="save-admin"]')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Wholesale', 'Customers', 'Products', 'Categories', 'Discounts', 'Coupons', 'Settings', 'Help']), 'UI-A3 after the developer switches them on: Products and Categories appear in the admin sidebar', await nav(a.page))
  await goto(a.page, 'categories')
  check(await a.page.evaluate(() => [...document.querySelectorAll('button')].some((b) => b.textContent.includes('Add category'))), 'UI-A3 the admin can use the Categories screen (Add category button)')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-testid="save-admin"]')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Wholesale', 'Customers', 'Discounts', 'Coupons', 'Settings', 'Help']), 'UI-A3 switched off again: they disappear from the sidebar', await nav(a.page))
  const audits = db("SELECT changes FROM audit_logs WHERE action = 'access.updated' ORDER BY id DESC LIMIT 2")
  check(audits.length === 2 && /products\.manage/.test(audits[0].changes) && /products\.manage/.test(audits[1].changes), 'UI-A3 both switch changes are in the audit log', audits.map((x) => x.changes.slice(0, 90)))

  // ============================================================ B4 / B5: forms
  await goto(d.page, 'categories')
  await tapText(d.page, 'button', 'Add category')
  await d.page.waitForSelector('#cname', { timeout: 10000 }); await sleep(600)
  await setValue(d.page, '#cname', 'UI Furniture')
  await setValue(d.page, '#cdesc', 'Made in the admin panel')
  await tap(d.page, 'button[form="category-form"]')
  await d.page.waitForFunction(() => !document.querySelector('#cname'), { timeout: 15000 })
  const uiCat = (await api('GET', 'admin/categories?q=UI Furniture', null, devToken)).json?.data?.find((c) => c.name === 'UI Furniture')
  check(!!uiCat, 'UI-B4 a category created in the form exists', uiCat && { id: uiCat.id, slug: uiCat.slug })
  await d.page.waitForSelector(`[aria-label="Add sub-category to UI Furniture"]`, { timeout: 10000 })
  await tap(d.page, `[aria-label="Add sub-category to UI Furniture"]`)
  await d.page.waitForSelector('#cname', { timeout: 10000 }); await sleep(600)
  await setValue(d.page, '#cname', 'UI Tables')
  await tap(d.page, 'button[form="category-form"]')
  await d.page.waitForFunction(() => !document.querySelector('#cname'), { timeout: 15000 })
  await sleep(600)
  await tap(d.page, `[aria-label="Add sub-category to UI Furniture"]`)
  await d.page.waitForSelector('#cname', { timeout: 10000 }); await sleep(600)
  await setValue(d.page, '#cname', 'UI Lamps')
  await tap(d.page, 'button[form="category-form"]')
  await d.page.waitForFunction(() => !document.querySelector('#cname'), { timeout: 15000 })
  await sleep(600)
  await tapText(d.page, 'button', 'Expand all')
  await sleep(400)
  const subs = db(`SELECT name FROM sub_categories WHERE category_id = ${uiCat.id} ORDER BY id`).map((x) => x.name)
  check(JSON.stringify(subs) === JSON.stringify(['UI Tables', 'UI Lamps']), 'UI-B4 two sub-categories created in the form', subs)
  const subRows = await d.page.$$eval('[aria-label^="Add sub-category to"]', (e) => e.length)
  check(!(await d.page.$('[aria-label="Add sub-category to UI Tables"]')), 'UI-B4 a sub-category has no "Add sub-category" button: no third level in the screen', { addSubButtons: subRows })
  await tap(d.page, '[aria-label="UI Lamps active"]')   // deactivate one
  await sleep(800)
  check(db("SELECT is_active FROM sub_categories WHERE name = 'UI Lamps'")[0].is_active === 0, 'UI-B4 a sub-category is deactivated with its switch')

  await goto(d.page, 'products/new')
  await d.page.waitForSelector('#category', { timeout: 15000 })
  const catValue = await d.page.$$eval('#category option', (os, name) => os.find((o) => o.textContent.includes(name))?.value, 'UI Furniture')
  await d.page.select('#category', catValue)
  await sleep(300)
  const subValue = await d.page.$$eval('#subcategory option', (os) => os.find((o) => o.textContent.includes('UI Tables'))?.value)
  await d.page.select('#subcategory', subValue)
  await setValue(d.page, '#name', 'UI Oak Table')
  check(await d.page.$eval('#sku', (el) => el.value) === 'UI-OAK-TABLE', 'UI-B5 the SKU is made from the product name while the admin has not typed one (UI-OAK-TABLE)')
  await setValue(d.page, '#sku', 'UI-TABLE-1')
  await setValue(d.page, '#short', 'Made in the admin form')
  await setValue(d.page, '#mtitle', 'UI Oak Table | Test')
  await setValue(d.page, '#variants-0-name', 'Standard')   // the option name starts as a copy of the product name
  await setValue(d.page, '#variants-0-mrp', '200')
  await setValue(d.page, '#variants-0-price', '180')
  await setValue(d.page, '#variants-0-stock', '7')
  await d.page.select('#status', 'active')
  await tap(d.page, '.save-bar button[type="submit"]')
  await toast(d.page, /product added|product saved/, 30000)
  const uiProd = db("SELECT id, sub_category_id, status, slug FROM products WHERE sku = 'UI-TABLE-1'")[0]
  const uiVar = uiProd && db(`SELECT stock_qty, selling_price, mrp FROM product_variants WHERE product_id = ${uiProd.id}`)[0]
  check(uiProd && uiProd.status === 'active' && uiVar.stock_qty === 7 && Number(uiVar.selling_price) === 180, 'UI-B5 a product made in the form: sub-category, option, price 180/200, stock 7, active', { uiProd, uiVar })
  const pub = await api('GET', `store/products/${uiProd?.slug}`)
  check(pub.status === 200 && pub.json?.data?.product_name === 'UI Oak Table' && pub.json?.data?.variants?.[0]?.name === 'Standard', 'UI-B5 and it is on the public API at once', pub.status)

  // ============================================================ D12-D14: the order screens (client admin)
  const sample = (await api('GET', 'store/products?per_page=50')).json?.data?.find((p) => p.name !== 'UI Oak Table')
  const sampleVariant = db("SELECT id FROM product_variants WHERE is_active = 1 AND stock_qty > 12 ORDER BY id LIMIT 1")[0]?.id
  const plainCod = (o) => o.payment_method === 'cod' && !o.delivery_fee_pending   // bank-transfer or fee-pending orders (left by the Phase A flows below) need other steps first
  let pending = ((await api('GET', 'admin/orders?status=pending&per_page=50', null, admToken)).json?.data || []).filter(plainCod)
  for (let i = pending.length; i < 3 && sampleVariant; i++) {
    const r = await fetch(`${BASE}/api/v1/store/orders`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': `flowui-${Date.now()}-${i}-xxxx` }, body: JSON.stringify({ customer: { name: `UI Buyer ${i}`, phone: `+92 355 90000${i}0`, email: `ui.buyer${i}@example.com` }, address: { line1: '1 Test Road', city: 'Lahore' }, items: [{ variant_id: sampleVariant, qty: 1 }] }) })
    if (r.status !== 201) console.log(`  (could not place a helper order: ${r.status})`)
  }
  pending = ((await api('GET', 'admin/orders?status=pending&per_page=50', null, admToken)).json?.data || []).filter(plainCod)
  check(pending.length >= 2, 'UI-D12 there are pending orders to work on', pending.length)
  const [first, second] = pending

  await goto(a.page, 'orders')
  check(await bodyHas(a.page, new RegExp(first.order_number)), 'UI-D12 the Orders screen lists the order', first.order_number)
  await setValue(a.page, 'input[type="search"]', first.order_number)
  await a.page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1, { timeout: 15000 }).catch(() => {})
  check((await a.page.$$('tbody tr')).length === 1, 'UI-D12 the search box narrows the list to that order')
  await tap(a.page, 'tbody tr')
  await a.page.waitForSelector('#admin-note', { timeout: 15000 })
  check(await bodyHas(a.page, new RegExp(`Order ${first.order_number}`)), 'UI-D12 the order detail opens')
  await setValue(a.page, '#admin-note', 'UI note: call the customer first.')
  await tapText(a.page, 'button', 'Save note')
  await toast(a.page, /note saved/)
  check(db(`SELECT admin_note FROM orders WHERE id = ${first.id}`)[0].admin_note === 'UI note: call the customer first.', 'UI-D12 an internal note is saved from the screen')

  const step = async (buttonText, fill) => {
    await tapText(a.page, '.act-bar button', buttonText)   // the status simply changes: no form, no questions
  }
  await step('Confirm order')
  await toast(a.page, /confirmed|updated|saved/)
  await step('Mark as packed')
  await toast(a.page, /packed|updated|saved/)
  await step('Ship order')
  await toast(a.page, /shipped|updated|saved/)
  await step('Mark as delivered')
  await toast(a.page, /delivered|updated|saved/)
  await sleep(500)
  const done = db(`SELECT status, payment_status FROM orders WHERE id = ${first.id}`)[0]
  const hist = db(`SELECT to_status FROM order_status_history WHERE order_id = ${first.id} ORDER BY id`).map((h) => h.to_status)
  check(done.status === 'delivered' && done.payment_status === 'paid' && JSON.stringify(hist) === JSON.stringify(['pending', 'confirmed', 'packed', 'shipped', 'delivered']), 'UI-D13 every step through the screen: delivered, COD paid, five history rows', { done, hist })
  check(!(await a.page.evaluate(() => [...document.querySelectorAll('.act-bar button')].some((b) => /Cancel order|Mark as|Confirm order|Ship order/.test(b.textContent)))), 'UI-D13 a delivered order offers no further action buttons')
  check(db(`SELECT id FROM audit_logs WHERE subject_type = 'Order' AND subject_id = ${first.id} AND action = 'order.status_changed'`).length === 4, 'UI-D13 four status audit entries for the four steps')

  // invoice and packing slip: the print view
  await a.page.evaluate(() => { window.__printed = 0; window.print = () => { window.__printed++ } })
  await tapText(a.page, '.act-bar button', 'Print invoice')
  await sleep(300)
  await a.page.emulateMediaType('print')
  const sheet = async () => a.page.evaluate(() => { const s = document.querySelector('.print-sheet'); return { shown: getComputedStyle(s).display !== 'none', text: s.innerText.replace(/\s+/g, ' ') } })
  const inv = await sheet()
  const itemName = db(`SELECT name FROM order_items WHERE order_id = ${first.id} LIMIT 1`)[0].name
  check(inv.shown && /Invoice/i.test(inv.text) && inv.text.includes(first.order_number) && inv.text.includes(itemName) && /Bill to/i.test(inv.text) && /Total/i.test(inv.text) && /Cash on delivery/i.test(inv.text), 'UI-D14 invoice (print view): number, items, bill-to, totals, payment', inv.text.slice(0, 220))
  await a.page.pdf({ path: path.join(OUT, 'invoice.pdf'), format: 'A4' })
  await a.page.emulateMediaType('screen')
  await tapText(a.page, '.act-bar button', 'Packing slip')
  await sleep(300)
  await a.page.emulateMediaType('print')
  const slip = await sheet()
  check(slip.shown && /Packing slip/i.test(slip.text) && slip.text.includes(first.order_number) && slip.text.includes(itemName) && !/Bill to/i.test(slip.text) && /Cash on delivery/i.test(slip.text) && /Unit price/i.test(slip.text) && /(Collect|Nothing to collect)/i.test(slip.text), 'UI-D14 packing slip (print view): items with prices, ship-to, and what the courier collects (or that nothing is due), no bill-to', slip.text.slice(0, 260))
  await a.page.pdf({ path: path.join(OUT, 'packing-slip.pdf'), format: 'A4' })
  await a.page.emulateMediaType('screen')
  check((await a.page.evaluate(() => window.__printed)) === 2 && fs.statSync(path.join(OUT, 'invoice.pdf')).size > 1500 && fs.statSync(path.join(OUT, 'packing-slip.pdf')).size > 1500, 'UI-D14 both buttons call print; both print views render to a PDF', { dir: OUT })

  // cancel with a reason: the stock returns
  const line = db(`SELECT variant_id, qty FROM order_items WHERE order_id = ${second.id} LIMIT 1`)[0]
  const stockBefore = db(`SELECT stock_qty FROM product_variants WHERE id = ${line.variant_id}`)[0].stock_qty
  await goto(a.page, `orders/${second.id}`)
  await a.page.waitForSelector('.act-bar', { timeout: 15000 })
  await tapText(a.page, '.act-bar button', 'Cancel order')
  await tapText(a.page, '.modal__foot button', 'Cancel order')   // only an "are you sure?": no reason is asked
  await toast(a.page, /cancelled|updated|saved/)
  await sleep(500)
  const stockAfter = db(`SELECT stock_qty FROM product_variants WHERE id = ${line.variant_id}`)[0].stock_qty
  check(db(`SELECT status FROM orders WHERE id = ${second.id}`)[0].status === 'cancelled' && stockAfter - stockBefore === line.qty, 'UI-D14 cancel from the screen: status cancelled and the stock returned', { before: stockBefore, after: stockAfter, qty: line.qty })
  await goto(a.page, '')
  check(await bodyHas(a.page, /Revenue/) && await bodyHas(a.page, /Sales/) && await bodyHas(a.page, /Wholesale/) && !(await bodyHas(a.page, /Pending orders/)), 'UI-D12 the dashboard shows Sales, Revenue, Orders and Wholesale to the client admin')

  // ============================================================ D15: customers
  await goto(a.page, 'customers')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  const allRows = (await a.page.$$('tbody tr')).length
  const someCustomer = db("SELECT name, phone, email FROM customers WHERE email IS NOT NULL ORDER BY id LIMIT 1")[0]
  const typeSearch = async (text) => { await setValue(a.page, 'input[type="search"]', text); await sleep(3000) }
  await typeSearch(someCustomer.name.split(' ')[0])
  const byName = await a.page.$$eval('tbody tr', (r) => r.map((x) => x.innerText.replace(/\s+/g, ' ')))
  check(byName.length >= 1 && byName.length <= allRows && byName.every((t) => t.includes(someCustomer.name.split(' ')[0])), 'UI-D15 search by name', byName.length)
  await typeSearch(someCustomer.phone.slice(-7))
  check((await a.page.$$eval('tbody tr', (r) => r.length)) >= 1 && (await bodyHas(a.page, new RegExp(someCustomer.phone.replace('+', '\\+')))), 'UI-D15 search by part of the phone', someCustomer.phone)
  await typeSearch(someCustomer.email)
  check((await a.page.$$eval('tbody tr', (r) => r.length)) === 1, 'UI-D15 search by e-mail finds exactly that customer', someCustomer.email)
  await typeSearch('')
  await sleep(500)
  // the panel saves the file from a blob: catch that blob and the file name the browser would use
  await a.page.evaluate(() => { const original = URL.createObjectURL; URL.createObjectURL = (blob) => { window.__csv = blob.text(); return original.call(URL, blob) }; const click = HTMLAnchorElement.prototype.click; HTMLAnchorElement.prototype.click = function () { window.__csvName = this.download; click.call(this) } })
  await tapText(a.page, 'button', 'Export CSV')
  await a.page.waitForFunction(() => window.__csv, { timeout: 20000 })
  const csvText = await a.page.evaluate(() => window.__csv)
  check(/customers.*\.csv$/i.test(await a.page.evaluate(() => window.__csvName)), 'UI-D15 the browser would save it as a .csv file', await a.page.evaluate(() => window.__csvName))
  const apiCsv = (await api('GET', 'admin/customers/export', null, admToken)).text
  const csvLines = csvText.trim().split(/\r?\n/)
  check(csvLines.length - 1 === db('SELECT COUNT(*) n FROM customers')[0].n && csvLines[0].startsWith('Name,Phone,Email') && csvText.trim() === apiCsv.trim(), 'UI-D15 the CSV exported by the button has one row per customer and equals the API export', { rows: csvLines.length - 1, header: csvLines[0] })
  check(csvLines.some((l) => l.includes(someCustomer.phone) && l.includes(someCustomer.email)), 'UI-D15 the CSV contains the customer (phone and e-mail)')

  // ============================================================ E16: settings in the panel
  await goto(d.page, 'settings')
  await d.page.waitForSelector('#s-store_name', { timeout: 15000 })
  await setValue(d.page, '#s-store_name', 'Panel Store')
  await setValue(d.page, '#s-default_phone_country_code', '92')
  await tap(d.page, '[data-testid="save-settings"]')
  await d.page.waitForSelector('.err, .has-error', { timeout: 10000 })
  check(true, 'UI-E16 an invalid phone country code ("92") is refused with a message next to the field', await d.page.$eval('.err', (e) => e.innerText))
  await setValue(d.page, '#s-default_phone_country_code', '+92')
  await tap(d.page, '[data-testid="save-settings"]')
  await toast(d.page, /settings saved/)
  const pubSettings = (await api('GET', 'store/settings')).json
  check(pubSettings.store.name === 'Panel Store', 'UI-E16 General tab saved in the panel shows in GET /store/settings', pubSettings.store.name)
  check(await d.page.evaluate(() => document.querySelector('.sidebar, header')?.innerText.includes('Panel Store')), 'UI-E16 the panel itself shows the new store name')
  await api('DELETE', 'admin/settings/logo', null, devToken)   // an earlier flow may have uploaded one: start without
  await api('DELETE', 'admin/settings/favicon', null, devToken)
  await goto(d.page, 'settings')
  await d.page.waitForSelector('[data-testid="brand-name"]', { timeout: 15000 })
  check((await d.page.title()).includes('Panel Store') && /Panel Store/.test(await d.page.$eval('[data-testid="brand-name"]', (e) => e.innerText)), 'UI-C4 without a logo the sidebar shows the store name as text and the tab title carries it')
  {
    // the developer uploads a logo and a favicon in Settings > Branding: the sidebar, the login page and the tab icon follow without a reload
    await tap(d.page, '#tab-branding')
    await d.page.waitForSelector('input[type="file"]', { timeout: 10000 })
    const logoPng = writePng(path.join(OUT, 'logo.png'), 200, 60, [30, 64, 175])
    const favPng = writePng(path.join(OUT, 'favicon.png'), 64, 64, [200, 60, 60])
    await (await d.page.$('#panel-branding input[aria-label$="Logo"]')).uploadFile(logoPng)
    await d.page.waitForSelector('[data-testid="brand-logo"]', { timeout: 15000 })
    check((await d.page.$eval('[data-testid="brand-logo"]', (e) => e.getAttribute('src'))).includes('/storage/') && !(await d.page.$('[data-testid="brand-name"]')), 'UI-C4 after the logo is uploaded the sidebar shows it at once (no reload), with the name only as alt text')
    await sleep(600)
    await (await d.page.$('#panel-branding input[aria-label$="Favicon"]')).uploadFile(favPng)
    await d.page.waitForFunction(() => /\/storage\//.test(document.querySelector('link[rel~="icon"]')?.getAttribute('href') || ''), { timeout: 15000 })
    check(true, 'UI-C4 the favicon becomes the browser tab icon at once')
    const collapsedNow = () => d.page.$eval('.app-shell', (e) => e.classList.contains('is-collapsed'))
    if (!(await collapsedNow())) await d.page.$eval('[data-testid="collapse-toggle"]', (el) => el.click())
    await sleep(500)
    const small = await d.page.$eval('[data-testid="brand-logo"]', (e) => ({ w: e.getBoundingClientRect().width, src: e.getAttribute('src') }))
    check(small.w <= 40 && /storage/.test(small.src), 'UI-C4 on the collapsed sidebar the favicon is shown small (<= 40px)', small)
    if (await collapsedNow()) await d.page.$eval('[data-testid="collapse-toggle"]', (el) => el.click())
    const lp = await d.browser.newPage()
    await lp.setViewport({ width: 1300, height: 800 })
    await lp.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
    check(!!(await lp.$('[data-testid="brand-logo"]')) && (await lp.title()).includes('Panel Store'), 'UI-C4 the login page shows the logo and the store name in the title')
    await lp.close()
    await d.page.$$eval('#panel-branding button', (bs) => bs.filter((b) => /remove/i.test(b.textContent)).forEach((b) => b.click())).catch(() => {})
    await sleep(300)
  }

  await tap(d.page, '#tab-banners')
  await d.page.waitForSelector('#panel-banners', { timeout: 10000 })
  await d.page.waitForFunction(() => [...document.querySelectorAll('#panel-banners button')].some((b) => b.textContent.includes('Add banner') && !b.disabled), { timeout: 15000 })
  await tapText(d.page, '#panel-banners button', 'Add banner')
  await d.page.waitForSelector('#banner-form', { timeout: 10000 }); await sleep(600)
  await d.page.select('#bplace', 'promo_strip')
  const bannerPng = writePng(path.join(OUT, 'banner.png'), 1200, 300, [200, 90, 30])
  const picker = await d.page.$('input[aria-label="Banner picture"]')
  await picker.uploadFile(bannerPng)
  await setValue(d.page, '#btitle', 'Panel banner')
  await setValue(d.page, '#bbtn', 'Go')
  await setValue(d.page, '#blink', '/category/ui-furniture')
  await tap(d.page, 'button[form="banner-form"]')
  await toast(d.page, /banner/i)
  const live = (await api('GET', 'store/banners?placement=promo_strip')).json.data
  check(live.some((b) => b.title === 'Panel Store' || b.title === 'Panel banner') && live.some((b) => b.link_url === '/category/ui-furniture' && b.image_url?.startsWith('http')), 'UI-E16 a banner created in the panel (picture, link) is live on GET /store/banners?placement=promo_strip', live.map((b) => b.title))

  // ============================================================ Phase A: theme (developer)
  await goto(d.page, 'settings?tab=theme')
  await d.page.waitForSelector('[data-theme-preview]', { timeout: 15000 })
  const btnBg = () => d.page.$eval('[data-theme-preview] .tp-btn', (e) => getComputedStyle(e).backgroundColor)
  await setValue(d.page, '#s-theme_primary', '#123456')
  await sleep(200)
  check(await btnBg() === 'rgb(18, 52, 86)', 'UI-A1 Theme: the live preview follows a colour typed in the form', await btnBg())
  await tap(d.page, '[data-testid="save-settings"]')
  await toast(d.page, /settings saved/)
  check((await api('GET', 'store/settings')).json?.theme?.primary?.toUpperCase() === '#123456', 'UI-A1 Theme: the saved colour is in GET /store/settings (theme.primary)')
  await goto(a.page, 'settings')
  await a.page.waitForSelector('[role="tab"]', { timeout: 15000 })
  check(!(await a.page.$('#tab-theme')) && (await tabsOf(a.page)).includes('Payments'), 'UI-A1 the admin never sees the Theme tab (but sees Payments)', await tabsOf(a.page))

  // ============================================================ Phase A: discounts (admin, through the forms)
  await goto(a.page, 'discounts')
  await a.page.waitForSelector('[data-add]', { timeout: 15000 })
  await tap(a.page, '[data-add]')
  await a.page.waitForSelector('#promo-form', { timeout: 10000 })
  await tap(a.page, 'button[form="promo-form"]')   // nothing filled in
  await a.page.waitForSelector('#promo-form .err', { timeout: 10000 })
  check(/Enter a name for the discount/.test(await a.page.$eval('#promo-form', (f) => f.innerText)), 'UI-P1 an empty discount form shows the server messages under the fields', await a.page.$eval('#promo-form', (f) => [...f.querySelectorAll('.err')].map((e) => e.innerText)))
  await setValue(a.page, '#pname', `UI Flash ${RUN}`)
  await setValue(a.page, '#pvalue', '15')
  await setValue(a.page, '#pprio', '5')
  await a.page.select('#pscope', 'categories')
  await tap(a.page, 'button[form="promo-form"]')
  await a.page.waitForFunction(() => /Choose at least one/.test(document.querySelector('#promo-form')?.innerText || ''), { timeout: 10000 }).catch(() => {})
  check(/Choose at least one/.test(await a.page.$eval('#promo-form', (f) => f.innerText)), 'UI-P1 a category scope without a category is refused with the server message')
  await a.page.$eval('.target-picker input', (el) => el.dispatchEvent(new Event('focus')))
  await a.page.waitForSelector('.tp-option', { timeout: 10000 })
  const picked = await a.page.$eval('.tp-option', (el) => { const name = el.textContent.trim(); el.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true })); return name })
  await a.page.waitForSelector('.target-picker [data-chip]', { timeout: 5000 })
  check((await a.page.$$('.target-picker [data-chip]')).length === 1 && await a.page.$eval('.target-picker [data-chip]', (c, n) => c.innerText.includes(n), picked), 'UI-P1 the searchable multi-select adds the chosen category as a chip', picked)
  await a.page.$eval('.target-picker input', (el) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, 'zzzz-nothing'); el.dispatchEvent(new Event('input', { bubbles: true })) })
  await a.page.waitForFunction(() => /No match/.test(document.querySelector('.tp-list')?.innerText || ''), { timeout: 8000 }).catch(() => {})
  check(await a.page.evaluate(() => /No match/.test(document.querySelector('.tp-list')?.innerText || '')), 'UI-P1 the search box filters (no match for a nonsense term)', await a.page.evaluate(() => document.querySelector('.tp-list')?.innerText))
  await tap(a.page, 'button[form="promo-form"]')
  await toast(a.page, /discount added/)
  const disc = (await api('GET', `admin/discounts?q=UI Flash ${RUN}`, null, admToken)).json?.data?.[0]
  if (disc) created.discounts.push(disc.id)
  check(disc && disc.type === 'percent' && disc.value === 15 && disc.scope === 'categories' && disc.targets_count === 1 && disc.priority === 5 && disc.state === 'live', 'UI-P1 the discount made in the form: 15%, categories (1 target), priority 5, live', disc && { type: disc.type, value: disc.value, scope: disc.scope, targets: disc.targets_count, state: disc.state })
  check(await bodyHas(a.page, /15%/) && await bodyHas(a.page, /Categories \(1\)/), 'UI-P1 the list row shows "15%" and "Categories (1)"')
  await tap(a.page, `[aria-label="UI Flash ${RUN} active"]`)
  await toast(a.page, /switched off/)
  check((await api('GET', `admin/discounts/${disc.id}`, null, admToken)).json?.data?.is_active === false, 'UI-P1 the list switch deactivates the discount')
  await tap(a.page, `[aria-label="UI Flash ${RUN} active"]`)
  await toast(a.page, /switched on/)
  await tap(a.page, `[aria-label="Edit UI Flash ${RUN}"]`)
  await a.page.waitForSelector('#promo-form', { timeout: 10000 })
  await a.page.waitForSelector('.target-picker [data-chip]', { timeout: 10000 })
  check(await a.page.$eval('#pvalue', (e) => e.value) === '15' && (await a.page.$$('.target-picker [data-chip]')).length === 1, 'UI-P1 editing loads the saved value and the chosen category with its name')
  await setValue(a.page, '#pvalue', '20')
  await a.page.select('#ptype', 'fixed')
  await setValue(a.page, '#pvalue', '2.5')
  await tap(a.page, 'button[form="promo-form"]')
  await toast(a.page, /discount updated/)
  const disc2 = (await api('GET', `admin/discounts/${disc.id}`, null, admToken)).json?.data
  check(disc2.type === 'fixed' && disc2.value === 2.5 && disc2.targets.length === 1, 'UI-P1 the edit is saved (fixed 2.50, target kept)', { type: disc2.type, value: disc2.value })
  await setValue(a.page, 'input[type="search"]', 'no-such-discount-xyz')
  await a.page.waitForFunction(() => /No discounts found/.test(document.body.innerText), { timeout: 10000 }).catch(() => {})
  check(await bodyHas(a.page, /No discounts found/), 'UI-P1 the search box narrows the list (empty state)')
  await setValue(a.page, 'input[type="search"]', `UI Flash ${RUN}`)
  await a.page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1, { timeout: 10000 }).catch(() => {})
  check((await a.page.$$('tbody tr')).length === 1, 'UI-P1 searching by name finds the discount')
  await a.page.select('select[aria-label="Status"]', 'expired')
  await a.page.waitForFunction(() => !document.querySelector('tbody tr'), { timeout: 10000 }).catch(() => {})
  check((await a.page.$$('tbody tr')).length === 0, 'UI-P1 the status filter "Expired" hides a live discount')

  // ============================================================ Phase A: coupons (admin, through the forms)
  const CODE = `UI${RUN}`.slice(0, 20)
  await goto(a.page, 'coupons')
  await a.page.waitForSelector('[data-add]', { timeout: 15000 })
  await tap(a.page, '[data-add]')
  await a.page.waitForSelector('#pcode', { timeout: 10000 })
  await setValue(a.page, '#pcode', `${CODE.toLowerCase()}`)
  check(await a.page.$eval('#pcode', (e) => e.value) === CODE, 'UI-P2 the code is upper-cased as it is typed', await a.page.$eval('#pcode', (e) => e.value))
  await setValue(a.page, '#pname', 'UI coupon')
  await tap(a.page, '[data-free-delivery]')
  check(!(await a.page.$('#pvalue')) && !(await a.page.$('#ptype')), 'UI-P2 the free-delivery switch hides type and value')
  await tap(a.page, '[data-free-delivery]')
  await a.page.waitForSelector('#pvalue', { timeout: 5000 })
  await setValue(a.page, '#pvalue', '10')
  await setValue(a.page, '#plimit', '100')
  await setValue(a.page, '#pcust', '2')
  await setValue(a.page, '#pmin', '1')
  await tap(a.page, 'button[form="promo-form"]')
  await toast(a.page, /coupon added/)
  const coupon = (await api('GET', `admin/coupons?q=${CODE}`, null, admToken)).json?.data?.[0]
  if (coupon) created.coupons.push(coupon.id)
  check(coupon && coupon.code === CODE && coupon.value === 10 && coupon.type === 'percent' && coupon.usage_limit === 100 && coupon.per_customer_limit === 2 && coupon.min_order === 1 && coupon.scope === 'store', 'UI-P2 the coupon made in the form (code, 10%, limits, whole store)', coupon && { code: coupon.code, value: coupon.value, limit: coupon.usage_limit })
  check(await bodyHas(a.page, new RegExp(`${CODE}`)) && await bodyHas(a.page, /Whole store/) && await bodyHas(a.page, /0 \/ 100/), 'UI-P2 the list row shows the code, the scope and "0 / 100" uses')
  await tap(a.page, '[data-add]')
  await a.page.waitForSelector('#pcode', { timeout: 10000 })
  await setValue(a.page, '#pcode', CODE)
  await setValue(a.page, '#pname', 'duplicate')
  await setValue(a.page, '#pvalue', '5')
  await tap(a.page, 'button[form="promo-form"]')
  await a.page.waitForSelector('#promo-form .err', { timeout: 10000 })
  check(/already used by another coupon/.test(await a.page.$eval('#promo-form', (f) => f.innerText)), 'UI-P2 a duplicate code shows the server message under the field')
  await tap(a.page, '[data-cancel]')
  await a.page.waitForSelector(`tr[data-row="${CODE}"]`, { timeout: 15000 })   // back on the list
  await tapText(a.page, `tr[data-row="${CODE}"] button`, 'Uses')
  await a.page.waitForSelector('[data-no-usages]', { timeout: 10000 })
  check(true, 'UI-P2 the "Uses" dialog of an unused coupon says so')
  await tapText(a.page, '.modal__foot button', 'Close')

  // a real use of the coupon (public API), so deleting it is refused and the Uses list has a row
  const sampleV = db("SELECT id FROM product_variants WHERE is_active = 1 AND stock_qty > 12 ORDER BY id LIMIT 1")[0]?.id
  const phoneOf = () => `+92 346 ${String(Math.floor(Math.random() * 9000000) + 1000000)}`
  const place = async ({ method = 'cod', coupon: code } = {}) => {
    const phone = phoneOf()
    const r = await fetch(`${BASE}/api/v1/store/orders`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ customer: { name: 'UI Phase A', phone }, address: { line1: '9 Test Road', city: 'Lahore' }, items: [{ variant_id: sampleV, qty: 1 }], payment_method: method, ...(code ? { coupon_code: code } : {}) }) })
    const json = await r.json().catch(() => null)
    if (r.status !== 201) console.log(`  (order not placed: ${r.status} ${JSON.stringify(json).slice(0, 200)})`)
    return { status: r.status, phone, id: json?.data?.id, number: json?.data?.order_number, data: json?.data }
  }
  const couponOrder = await place({ coupon: CODE })
  check(couponOrder.status === 201 && couponOrder.data?.totals?.coupon_code === CODE && couponOrder.data?.totals?.coupon_discount > 0, 'UI-P2 an order placed with the coupon gets the coupon discount', couponOrder.data?.totals && { code: couponOrder.data.totals.coupon_code, discount: couponOrder.data.totals.coupon_discount })
  await goto(a.page, 'coupons')
  await a.page.waitForSelector(`tr[data-row="${CODE}"]`, { timeout: 15000 })
  check(await a.page.$eval(`tr[data-row="${CODE}"]`, (r) => /1 \/ 100/.test(r.innerText)), 'UI-P2 the list now shows 1 / 100 uses')
  await tapText(a.page, `tr[data-row="${CODE}"] button`, 'Uses')
  await a.page.waitForSelector('[data-usages]', { timeout: 10000 })
  check(await a.page.$eval('[data-usages]', (t, n) => t.innerText.includes(n), couponOrder.number), 'UI-P2 the Uses dialog lists the order number (the admin may see orders)', couponOrder.number)
  await tapText(a.page, '.modal__foot button', 'Close')
  await tap(a.page, `[aria-label="Delete ${CODE}"]`)
  await tapText(a.page, '.modal__foot button', 'Delete')
  await toast(a.page, /deactivate it instead/)
  check((await api('GET', `admin/coupons/${coupon.id}`, null, admToken)).status === 200, 'UI-P2 a used coupon cannot be deleted: the server message "deactivate it instead" is shown and the coupon stays')
  await tap(a.page, `[aria-label="${CODE} active"]`)
  await toast(a.page, /switched off/)
  check((await api('GET', `admin/coupons/${coupon.id}`, null, admToken)).json?.data?.state === 'inactive', 'UI-P2 deactivating it with the list switch works')
  await api('POST', `admin/orders/${couponOrder.id}/status`, { status: 'cancelled', reason: 'UI test cleanup' }, admToken)

  // delete the discount made above (nothing depends on it)
  await goto(a.page, 'discounts')
  await a.page.waitForSelector(`[aria-label="Delete UI Flash ${RUN}"]`, { timeout: 15000 })
  await tap(a.page, `[aria-label="Delete UI Flash ${RUN}"]`)
  await tapText(a.page, '.modal__foot button', 'Delete')
  await toast(a.page, /discount deleted/)
  check((await api('GET', `admin/discounts/${disc.id}`, null, admToken)).status === 404, 'UI-P1 a discount is deleted from the list')
  created.discounts.length = 0

  // ============================================================ Phase A: delivery zones (admin)
  await goto(a.page, 'settings?tab=delivery')
  await a.page.waitForSelector('[data-add-zone]', { timeout: 15000 })
  await a.page.waitForFunction(() => { const b = document.querySelector('[data-add-zone]'); return b && !b.disabled }, { timeout: 15000 })
  check(await a.page.evaluate(() => !!document.querySelector('[data-zones-hint]')) === (snapAdmin.delivery_method !== 'zones'), 'UI-Z1 the hint "zones are used only with the Zones method" shows while another method is chosen', snapAdmin.delivery_method)
  await a.page.select('#s-delivery_method', 'flat')
  await sleep(200)
  check(!!(await a.page.$('#s-delivery_fee')) && !(await a.page.$('#s-free_delivery_min_cart')) && !(await a.page.$('#s-delivery_unmatched')), 'UI-Z1 Flat method: only the fee field is shown (no "free from", no "outside every zone")')
  await a.page.select('#s-delivery_method', 'zones')
  await sleep(200)
  check(!(await a.page.$('#s-delivery_unmatched')) && !(await a.page.$('[data-zones-hint]')), 'UI-Z1 Zones method: the hint goes away (the technical "outside every zone" rule is not shown to the client admin)')
  await a.page.select('#s-delivery_method', 'free_above')
  await sleep(200)
  check(!!(await a.page.$('#s-free_delivery_min_cart')) && !!(await a.page.$('#s-delivery_fee')), 'UI-Z1 Free-above method: fee and "free from" are shown')
  await goto(a.page, 'settings?tab=delivery')   // nothing was saved
  await a.page.waitForFunction(() => { const b = document.querySelector('[data-add-zone]'); return b && !b.disabled }, { timeout: 15000 })

  const zoneForm = async (fill) => {
    await tap(a.page, '[data-add-zone]')
    await a.page.waitForSelector('#zone-form', { timeout: 10000 })
    await fill()
    await tap(a.page, 'button[form="zone-form"]')
  }
  await zoneForm(async () => {})   // empty: refused
  await a.page.waitForSelector('#zone-form .err', { timeout: 10000 })
  check(/Enter a name for the zone/.test(await a.page.$eval('#zone-form', (f) => f.innerText)) && /at least one value/.test(await a.page.$eval('#zone-form', (f) => f.innerText)), 'UI-Z1 an empty zone form shows the server messages', await a.page.$eval('#zone-form', (f) => [...f.querySelectorAll('.err')].map((e) => e.innerText)))
  await setValue(a.page, '#zname', `UI Zone A ${RUN}`)
  await setValue(a.page, '#zvalues', 'Lahore\nKarachi')
  await setValue(a.page, '#zfee', '3')
  await tap(a.page, 'button[form="zone-form"]')
  await toast(a.page, /zone added/)
  await zoneForm(async () => {
    await setValue(a.page, '#zname', `UI Zone B ${RUN}`)
    await a.page.select('#ztype', 'postcode')
    await setValue(a.page, '#zvalues', '54000\n54*')
    await setValue(a.page, '#zfee', '5')
    await setValue(a.page, '#zfree', '100')
    await setValue(a.page, '#zmin', '10')
    await setValue(a.page, '#zeta', '1 to 2 days')
  })
  await toast(a.page, /zone added/)
  const zones = async () => ((await api('GET', 'admin/delivery-zones', null, admToken)).json?.data || []).filter((z) => z.name.includes(RUN))
  let zs = await zones()
  check(zs.length === 2 && zs[0].name.startsWith('UI Zone A') && zs[1].match_type === 'postcode' && JSON.stringify(zs[1].match_values) === JSON.stringify(['54000', '54*']) && zs[1].free_above === 100 && zs[1].min_order === 10 && zs[1].eta_text === '1 to 2 days', 'UI-Z1 two zones made in the modal (city values one per line, postcode with *, free above, min order, ETA)', zs.map((z) => `${z.name}:${z.match_type}:${z.match_values}`))
  check(await bodyHas(a.page, /54000, 54\*/) && await bodyHas(a.page, /1 to 2 days/), 'UI-Z1 the zone list shows the values and the ETA')
  await tap(a.page, `[aria-label="Move UI Zone B ${RUN} up"]`)
  for (let n = 0; n < 20 && !(await zones())[0]?.name.startsWith('UI Zone B'); n++) await sleep(400)   // the reorder is saved in the background
  zs = await zones()
  check(zs[0].name.startsWith('UI Zone B') && zs[1].name.startsWith('UI Zone A'), 'UI-Z1 the up arrow reorders the zones (saved on the server)', zs.map((z) => z.name))
  const rowOrder = await a.page.$$eval('tr[data-zone]', (rs) => rs.map((r) => r.dataset.zone).filter((n) => n.includes('UI Zone')))
  check(rowOrder[0].startsWith('UI Zone B'), 'UI-Z1 the screen shows the new order', rowOrder)
  await tap(a.page, `[aria-label="UI Zone A ${RUN} active"]`)
  await toast(a.page, /switched off/)
  check((await zones()).find((z) => z.name.startsWith('UI Zone A')).is_active === false, 'UI-Z1 the zone switch deactivates a zone')
  await tap(a.page, `[aria-label="Edit UI Zone B ${RUN}"]`)
  await a.page.waitForSelector('#zone-form', { timeout: 10000 })
  check(await a.page.$eval('#zvalues', (e) => e.value) === '54000\n54*', 'UI-Z1 editing a zone loads its values one per line')
  await setValue(a.page, '#zfee', '6.5')
  await tap(a.page, 'button[form="zone-form"]')
  await toast(a.page, /zone updated/)
  check((await zones()).find((z) => z.name.startsWith('UI Zone B')).fee === 6.5, 'UI-Z1 a zone edit is saved (fee 6.50)')
  for (const letter of ['A', 'B']) {
    await tap(a.page, `[aria-label="Delete UI Zone ${letter} ${RUN}"]`)
    await tapText(a.page, '.modal__foot button', 'Delete')
    await toast(a.page, /zone deleted/)
  }
  check((await zones()).length === 0, 'UI-Z1 both zones deleted with the confirmation dialog')

  // ============================================================ Phase A: payments (admin)
  await goto(a.page, 'settings?tab=payments')
  await a.page.waitForSelector('[data-add-account]', { timeout: 15000 })
  const accountsBefore = (await a.page.$$('.acct-row')).length
  await tap(a.page, '[data-add-account]')
  check((await a.page.$$('.acct-row')).length === accountsBefore + 1, 'UI-PAY a bank account row is added')
  await tap(a.page, '[data-testid="save-settings"]')
  await a.page.waitForSelector('.acct-row .err', { timeout: 10000 })
  check(/Enter the bank name/.test(await a.page.$eval('.acct-row:last-child', (r) => r.innerText)), 'UI-PAY an account without a bank name: the server message shows on that row', await a.page.$eval('.acct-row:last-child', (r) => r.innerText.replace(/\s+/g, ' ')))
  await setValue(a.page, '.acct-row:last-child .acct-bank', `UI Bank ${RUN}`)
  await setValue(a.page, '.acct-row:last-child .acct-title', 'UI Shop Ltd')
  await setValue(a.page, '.acct-row:last-child .acct-number', '0123456789')
  await setValue(a.page, '.acct-row:last-child .acct-iban', 'PK36UIBK0000123456789')
  if (!(await a.page.$eval('#s-pay_bank_enabled', (e) => e.checked))) await tap(a.page, '#s-pay_bank_enabled')
  await setValue(a.page, '#s-pay_bank_instructions', 'Use your order number as the payment reference.')
  await tap(a.page, '[data-testid="save-settings"]')
  await toast(a.page, /settings saved/)
  const afterPay = (await api('GET', 'admin/settings', null, admToken)).json.values
  check(afterPay.pay_bank_enabled === true && afterPay.pay_bank_accounts.some((x) => x.bank === `UI Bank ${RUN}` && x.iban === 'PK36UIBK0000123456789' && x.active === true), 'UI-PAY the account and the bank-transfer switch are saved', afterPay.pay_bank_accounts.map((x) => x.bank))
  const pubPay = (await api('GET', 'store/settings')).json
  check(pubPay.payment?.bank_transfer?.enabled === true && pubPay.payment.bank_transfer.accounts.some((x) => x.bank === `UI Bank ${RUN}`), 'UI-PAY GET /store/settings now offers the bank transfer with the new account')
  // both methods off is refused by the server; its message shows next to the field
  if (await a.page.$eval('#s-pay_cod_enabled', (e) => e.checked)) await tap(a.page, '#s-pay_cod_enabled')
  await tap(a.page, '#s-pay_bank_enabled')
  await tap(a.page, '[data-testid="save-settings"]')
  await a.page.waitForSelector('[data-field="pay_cod_enabled"] .err, [data-field="pay_bank_enabled"] .err', { timeout: 10000 })
  check(/at least one payment method/i.test(await a.page.$eval('[data-field="pay_cod_enabled"] .err, [data-field="pay_bank_enabled"] .err', (e) => e.innerText)), 'UI-PAY switching both methods off shows the server message next to the field')
  await goto(a.page, 'settings?tab=payments')   // drop the unsaved switches

  // ============================================================ Phase A: bank-transfer orders (public API + admin screens)
  const pngFile = writePng(path.join(OUT, 'proof.png'), 160, 160, [30, 120, 80])
  const upload = async (order, reference) => {
    const fd = new FormData()
    fd.append('proof', new Blob([fs.readFileSync(pngFile)], { type: 'image/png' }), 'proof.png')
    if (reference) fd.append('reference', reference)
    const r = await fetch(`${BASE}/api/v1/store/orders/${order.number}/payment-proof?phone=${encodeURIComponent(order.phone)}`, { method: 'POST', headers: { Accept: 'application/json' }, body: fd })
    return { status: r.status, json: await r.json().catch(() => null) }
  }
  const adminOrder = async (id) => (await api('GET', `admin/orders/${id}`, null, admToken)).json?.data
  const confirmBtn = (page) => page.$eval('.act-bar button:last-child', (b) => ({ text: b.textContent.trim(), disabled: b.disabled }))

  const bank1 = await place({ method: 'bank_transfer' })
  check(bank1.status === 201 && bank1.data?.payment_status === 'awaiting_payment' && bank1.data?.payment?.can_upload_proof === true, 'UI-B1 a bank-transfer order is placed through the public API (awaiting payment)', bank1.data && { status: bank1.data.status, pay: bank1.data.payment_status })
  const up1 = await upload(bank1, 'UI-REF-123')
  check(up1.status === 200 && up1.json?.data?.payment?.proof_status === 'submitted', 'UI-B1 the proof (PNG, 160x160) is uploaded through the public API', up1.status)
  created.orders.push(bank1.id)

  await goto(a.page, `orders/${bank1.id}`)
  await a.page.waitForSelector('[data-bank-panel]', { timeout: 15000 })
  await a.page.waitForFunction(() => { const i = document.querySelector('[data-proof-img]'); return i && i.complete && i.naturalWidth >= 100 }, { timeout: 15000 })
  check(await a.page.$eval('[data-proof-img]', (i) => i.src.startsWith('blob:')), 'UI-B1 the proof picture is shown from a blob (fetched through the authenticated route)')
  check((await a.page.$eval('[data-proof-reference]', (e) => e.innerText)) === 'UI-REF-123' && await a.page.$eval('[data-proof-status]', (e) => /to check/i.test(e.innerText)), 'UI-B1 the reference and the "Proof to check" badge show')
  await a.page.waitForFunction((bank) => document.querySelector('.pay__accounts')?.innerText.includes(bank), { timeout: 15000 }, `UI Bank ${RUN}`).catch(() => {})   // the panel fills in after the order is read
  check(await a.page.$eval('.pay__accounts', (p, bank) => p.innerText.includes(bank) && /payment reference/i.test(p.innerText), `UI Bank ${RUN}`), 'UI-B1 the accounts and instructions shown to the customer are listed (the snapshot of the order)')
  const plainProof = await fetch(`${BASE}/api/v1/admin/orders/${bank1.id}/payment/proof`, { headers: { Accept: 'application/json' } })
  check(plainProof.status === 401, 'UI-B1 the proof URL is not public (401 without the login)')
  check(!(await a.page.$('[data-blocked]')) && (await confirmBtn(a.page)).disabled && /verify/i.test(await a.page.$eval('.act-bar button:last-child', (b) => b.title)), 'UI-B1 before verification: a disabled Confirm button (its hover text says why, no line of text next to it)', await confirmBtn(a.page))
  check((await api('POST', `admin/orders/${bank1.id}/status`, { status: 'confirmed' }, admToken)).json?.code === 'payment_not_verified', 'UI-B1 and the API agrees: 422 payment_not_verified')
  await tap(a.page, '[data-proof-thumb]')
  await a.page.waitForSelector('[data-proof-zoom]', { timeout: 5000 })
  check(await a.page.$eval('[data-proof-zoom]', (i) => i.naturalWidth >= 100), 'UI-B1 clicking the thumbnail zooms the proof in a dialog')
  await tap(a.page, '.modal__head .icon-btn')
  await tap(a.page, '[data-verify-payment]')
  await toast(a.page, /payment verified/)
  const v1 = await adminOrder(bank1.id)
  check(v1.payment.status === 'paid' && v1.payment.proof_status === 'verified' && v1.actions.blocked_reason === null, 'UI-B1 Verify payment: payment paid, proof verified, nothing blocks the order any more', { pay: v1.payment.status, proof: v1.payment.proof_status })
  check(!(await a.page.$('[data-verify-payment]')) && !(await confirmBtn(a.page)).disabled && /Confirm/.test((await confirmBtn(a.page)).text), 'UI-B1 the verify buttons are gone and Confirm order is enabled')
  await tapText(a.page, '.act-bar button', 'Confirm order')
  await toast(a.page, /confirmed/)
  check((await adminOrder(bank1.id)).status === 'confirmed', 'UI-B1 the verified bank-transfer order is confirmed from the screen')

  const bank2 = await place({ method: 'bank_transfer' })
  created.orders.push(bank2.id)
  await upload(bank2, 'UI-REF-BAD')
  await goto(a.page, `orders/${bank2.id}`)
  await a.page.waitForSelector('[data-reject-proof]', { timeout: 15000 })
  await tap(a.page, '[data-reject-proof]')
  await a.page.waitForSelector('#proof-form', { timeout: 5000 })
  await tap(a.page, 'button[form="proof-form"]')
  await a.page.waitForSelector('#proof-form .err', { timeout: 10000 })
  check(/Tell the customer why/.test(await a.page.$eval('#proof-form', (f) => f.innerText)), 'UI-B2 rejecting a proof needs a reason (server message in the dialog)')
  await setValue(a.page, '#proof-reason', 'The amount does not match (UI test).')
  await tap(a.page, 'button[form="proof-form"]')
  await toast(a.page, /proof sent back/)
  const r2 = await adminOrder(bank2.id)
  check(r2.payment.proof_status === 'rejected' && r2.payment.proof_reject_reason === 'The amount does not match (UI test).' && r2.status === 'pending', 'UI-B2 Reject proof: proof rejected with the reason, order still pending', { proof: r2.payment.proof_status })
  check(await bodyHas(a.page, /Proof rejected: The amount does not match/), 'UI-B2 the screen shows the rejection reason')
  const re = await upload(bank2, 'UI-REF-GOOD')
  check(re.status === 200 && re.json?.data?.payment?.proof_status === 'submitted', 'UI-B2 the customer can upload a new proof after a rejection (public API)')
  // now reject the whole order with a reason
  await a.page.reload({ waitUntil: 'networkidle0' })
  await a.page.waitForSelector('[data-reject-order]', { timeout: 15000 })
  await tap(a.page, '[data-reject-order]')
  await tapText(a.page, '.modal__foot button', 'Reject order')
  await toast(a.page, /rejected/)
  const r2b = await adminOrder(bank2.id)
  check(r2b.status === 'rejected' && r2b.actions.can_reject === false, 'UI-B2 Reject order: one confirmation, status rejected, no more actions', { status: r2b.status })
  check(!(await a.page.$('.act-bar button.btn-primary')), 'UI-B2 the screen offers no further button')
  check(await a.page.$eval('.timeline', (t) => /Rejected/.test(t.innerText)), 'UI-B2 the timeline has a "Rejected" step')
  const trackRejected = (await api('GET', `store/orders/${bank2.number}?phone=${encodeURIComponent(bank2.phone)}`)).json?.data
  check(trackRejected?.status === 'rejected', 'UI-B2 the customer tracking shows the rejection (public API)')

  // orders list: Rejected tab with its count, payment-method and payment-status filters
  await goto(a.page, 'orders')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  check(await a.page.$$eval('[role="tab"]', (ts) => ts.some((t) => /^Rejected\s*\d+$/.test(t.innerText.replace(/\s+/g, ' ').trim()) && Number(t.innerText.replace(/\D+/g, '')) >= 1)), 'UI-B3 the status tabs include Rejected with its count')
  await tapText(a.page, '[role="tab"]', 'Rejected')
  await a.page.waitForFunction(() => { const r = [...document.querySelectorAll('tbody tr')]; return r.length >= 1 && r.every((x) => /Rejected/.test(x.innerText)) }, { timeout: 12000 }).catch(() => {})
  check((await a.page.$$eval('tbody tr', (rs) => rs.length)) >= 1 && await a.page.$$eval('tbody tr', (rs) => rs.every((r) => /Rejected/.test(r.innerText))), 'UI-B3 the Rejected tab lists rejected orders')
  await goto(a.page, 'orders')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  await a.page.select('select[aria-label="Payment method"]', 'bank_transfer')
  await a.page.waitForFunction(() => { const r = [...document.querySelectorAll('tbody tr')]; return r.length >= 1 && r.every((x) => /Bank transfer/.test(x.innerText)) }, { timeout: 12000 }).catch(() => {})
  const bankRows = await a.page.$$eval('tbody tr', (rs) => rs.map((r) => r.innerText.replace(/\s+/g, ' ')))
  check(bankRows.length >= 2 && bankRows.every((t) => /Bank transfer/.test(t)), 'UI-B3 the payment-method filter shows only bank-transfer orders', bankRows.length)
  await a.page.select('select[aria-label="Payment"]', 'paid')
  await a.page.waitForFunction(() => { const r = [...document.querySelectorAll('tbody tr')]; return r.length >= 1 && r.every((x) => /Paid/.test(x.innerText)) }, { timeout: 12000 }).catch(() => {})
  const paidRows = await a.page.$$eval('tbody tr', (rs) => rs.map((r) => r.innerText.replace(/\s+/g, ' ')))
  check(paidRows.length >= 1 && paidRows.every((t) => /Bank transfer/.test(t) && /Paid/.test(t)), 'UI-B3 method + payment-status filters combine (paid bank-transfer orders)', paidRows.length)
  const awaiting = await place({ method: 'bank_transfer' })
  created.orders.push(awaiting.id)
  await goto(a.page, 'orders?payment_status=awaiting_payment')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  check(await a.page.$$eval('tbody tr', (rs) => rs.length >= 1 && rs.every((r) => /Awaiting payment/.test(r.innerText))), 'UI-B3 the "awaiting payment" badge and filter')
  await api('POST', `admin/orders/${awaiting.id}/status`, { status: 'rejected', reason: 'UI test cleanup' }, admToken)

  // ---- delivery fee confirmed by the owner
  check((await api('PUT', 'admin/settings', { delivery_method: 'owner_confirms' }, admToken)).status === 200, 'UI-D1 the store is switched to "owner confirms the delivery fee" through the settings API')
  const feeOrder = await place({ method: 'cod' })
  created.orders.push(feeOrder.id)
  check(feeOrder.status === 201 && feeOrder.data?.delivery_fee_pending === true && feeOrder.data?.totals?.delivery_fee === null, 'UI-D1 an order placed without a delivery fee (fee pending)')
  await api('PUT', 'admin/settings', { delivery_method: snapAdmin.delivery_method }, admToken)
  await goto(a.page, 'orders')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  check(await bodyHas(a.page, /delivery fee TBC/), 'UI-D1 the orders list marks orders whose delivery fee is still to be confirmed')
  await goto(a.page, `orders/${feeOrder.id}`)
  await a.page.waitForSelector('[data-section="delivery-fee"]', { timeout: 15000 })
  check(await a.page.$eval('[data-delivery-text]', (e) => e.innerText) === 'To be confirmed', 'UI-D1 the totals show "To be confirmed" for the delivery fee')
  check(await a.page.$eval('[data-blocked]', (e) => e.dataset.reason) === 'delivery_fee_pending' && (await confirmBtn(a.page)).disabled, 'UI-D1 Confirm order is disabled with the message "Set the delivery fee first"')
  check((await api('POST', `admin/orders/${feeOrder.id}/status`, { status: 'confirmed' }, admToken)).json?.code === 'delivery_fee_pending', 'UI-D1 and the API agrees: 422 delivery_fee_pending')
  await tap(a.page, '[data-set-fee]')
  await a.page.waitForSelector('[data-section="delivery-fee"] .err', { timeout: 5000 })
  check(true, 'UI-D1 "Set fee" without a number shows a message', await a.page.$eval('[data-section="delivery-fee"] .err', (e) => e.innerText))
  await setValue(a.page, '#fee', '4.5')
  await tap(a.page, '[data-set-fee]')
  await toast(a.page, /delivery fee set/i)
  const f1 = await adminOrder(feeOrder.id)
  check(f1.totals.delivery_fee === 4.5 && f1.actions.needs_delivery_fee === false && f1.actions.blocked_reason === null, 'UI-D1 Set fee: the fee is 4.50, the total is recalculated, the box is gone', { fee: f1.totals.delivery_fee, total: f1.totals.grand_total })
  check(await a.page.$eval('[data-delivery-text]', (e) => /4\.50/.test(e.innerText)) && !(await a.page.$('[data-section="delivery-fee"]')), 'UI-D1 the totals show the confirmed fee')
  await tapText(a.page, '.act-bar button', 'Confirm order')
  await toast(a.page, /confirmed/)
  check((await adminOrder(feeOrder.id)).status === 'confirmed', 'UI-D1 the order is confirmed after the fee was set')
  // a discount and a coupon are part of the totals box
  const couponTotals = await adminOrder(couponOrder.id)
  await goto(a.page, `orders/${couponOrder.id}`)
  await a.page.waitForSelector('[data-coupon-row]', { timeout: 15000 })
  check(await a.page.$eval('[data-coupon-row]', (e, c) => e.innerText.includes(c), CODE) && couponTotals.totals.coupon_discount > 0, 'UI-D1 the totals show the coupon code and its discount', couponTotals.totals.coupon_discount)


  // ============================================================ G. Phase B screens
  const PNG = writePng(path.join(OUT, 'g-poster.png'), 320, 180, [30, 90, 160])
  const MP4 = path.join(OUT, 'g-clip.mp4')
  fs.writeFileSync(MP4, Buffer.concat([Buffer.from('000000186674797069736f6d0000020069736f6d69736f32', 'hex'), Buffer.alloc(8192)]))
  const waitVal = (page, selector, timeout = 15000) => page.waitForSelector(selector, { timeout })
  const textOf = (page, selector) => page.$eval(selector, (e) => e.innerText.replace(/\s+/g, ' ').trim())
  const uiPhone = (n) => `+92 348 ${String(2000000 + n).slice(1)}`
  const gToken = admToken

  // ---- UI-G1 the dashboard: date tabs, coloured KPI cards, SVG chart, top sellers, recent orders; the developer sees catalogue widgets only
  await goto(a.page, '')
  await waitVal(a.page, '[data-kpi="revenue"]')
  const kpiCards = await a.page.$$eval('[data-kpi]', (els) => els.map((e) => e.dataset.kpi))
  check(JSON.stringify(kpiCards) === JSON.stringify(['sales', 'revenue', 'orders', 'wholesale']), 'UI-G1 the admin dashboard shows exactly four soft KPI cards (sales, revenue, orders, wholesale)', kpiCards)
  check(/Revenue/.test(await textOf(a.page, '[data-kpi="revenue"]')) && /Sales/.test(await textOf(a.page, '[data-kpi="sales"]')) && /^Welcome back/.test(await textOf(a.page, '.dash-head h1')), 'UI-C5 the Revenue and Sales cards are there (no explaining text) and the welcome line greets the user')
  const tabsDash = await a.page.$$eval('.dash-tabs [role="tab"]', (els) => els.map((e) => e.innerText.trim()))
  check(JSON.stringify(tabsDash) === JSON.stringify(['Today', '7 days', '30 days', 'This month', 'All time']), 'UI-G1 the date tabs: Today, 7 days, 30 days, This month, All time', tabsDash)
  await tap(a.page, '[data-range="all"]')
  await a.page.waitForFunction(() => location.search.includes('range=all'), { timeout: 10000 })
  await sleep(700)
  const apiAll = (await api('GET', 'admin/dashboard?range=all', null, admToken)).json
  const uiRevenue = await textOf(a.page, '[data-kpi="revenue"] .stat__value')
  check(uiRevenue.replace(/[^\d.]/g, '') === Number(apiAll.orders.kpis.revenue).toFixed(2).replace(/\.00$/, '').replace(/[^\d.]/g, '') || uiRevenue.replace(/[^\d]/g, '') === String(apiAll.orders.kpis.revenue.toFixed(2)).replace(/[^\d]/g, ''), 'UI-G1 "All time" shows the API revenue on the card', { card: uiRevenue, api: apiAll.orders.kpis.revenue })
  const bars = await a.page.$$eval('[data-testid="revenue-chart"] rect.hit', (els) => els.length)
  check(bars === apiAll.orders.series.points.length && bars >= 1 && (await a.page.$('[data-testid="revenue-chart"] path.rev-line') !== null || bars === 1) && (await a.page.$('[data-testid="revenue-chart"] canvas, [data-testid="revenue-chart"] img')) === null, 'UI-G1 the chart is plain SVG (a smooth area and an orders line, one target per day)', { targets: bars, points: apiAll.orders.series.points.length })
  await a.page.hover('[data-testid="revenue-chart"] rect.hit')
  check(/\d+ orders?/.test(await textOf(a.page, '[data-testid="chart-tip"]')), 'UI-G1 hovering a day shows a bubble with its revenue and orders', await textOf(a.page, '[data-testid="chart-tip"]'))
  check((await a.page.$('[data-testid="top-sellers"] .thumb')) !== null && (await a.page.$('[data-testid="quick-bank"]')) !== null && (await a.page.$('[data-testid="quick-cod"]')) !== null, 'UI-C5 best sellers have a thumbnail slot; the recent orders have the quick links (bank transfer, COD)')
  await tap(a.page, '[data-testid="quick-bank"]')
  await a.page.waitForFunction(() => location.search.includes('payment_method=bank_transfer'), { timeout: 10000 })
  check(true, 'UI-C5 "View bank transfer orders" opens the orders filtered by bank transfer')
  await goto(a.page, '?range=all')
  await waitVal(a.page, '[data-kpi="revenue"]')
  check(!(await a.page.$('[data-change="revenue"]')), 'UI-C5 all time has no previous period: no change figure')
  await goto(a.page, '?range=7d')
  await waitVal(a.page, '[data-kpi="revenue"]')
  check(!!(await a.page.$('[data-change="revenue"]')) && !!(await a.page.$('[data-change="orders"]')), 'UI-C5 a range with a previous period shows the change against it on revenue, orders and average order')
  check((await a.page.$('[data-testid="top-sellers"] li')) !== null && (await a.page.$('[data-testid="recent-orders"] tr')) !== null, 'UI-G1 top sellers and recent orders are listed')
  await tap(a.page, '[data-range="today"]')
  await a.page.waitForFunction(() => location.search.includes('range=today'), { timeout: 10000 })
  await sleep(600)
  check((await api('GET', 'admin/dashboard?range=today', null, admToken)).json.orders.kpis.total_orders === Number(await textOf(a.page, '[data-kpi="orders"] .stat__value')), 'UI-G1 "Today" shows the number of orders placed today (shop time)')
  await goto(d.page, '')
  await d.page.waitForSelector('[data-testid="low-stock-widget"], .kpis', { timeout: 15000 })
  check(!(await d.page.$('[data-kpi="revenue"]')) && !(await d.page.$('[data-testid="recent-orders"]')) && !(await d.page.$('[data-testid="revenue-chart"]')) && !(await bodyHas(d.page, /Revenue|Average order|Awaiting payment proof/)), 'UI-G1 the developer dashboard has no revenue, chart or order list: catalogue widgets only')
  check(!!(await d.page.$('[data-testid="low-stock-widget"]')) && !!(await d.page.$('[data-kpi="low-stock"]')), 'UI-G1 the developer still gets the low-stock widget and card')

  // ---- UI-G2 sidebar badges follow the server every 30 seconds
  const wsBefore = await api('GET', 'admin/wholesale/new-count', null, admToken)
  const pcBefore = await api('GET', 'admin/orders/pending-count', null, admToken)
  await goto(a.page, '')
  await a.page.waitForFunction((n) => Number(document.querySelector('[data-testid="orders-badge"]')?.textContent || 0) === n, { timeout: 20000 }, pcBefore.json.count)
  check(true, 'UI-G2 the Orders badge shows pending orders + payment proofs to check', pcBefore.json)
  const wsPost = await fetch(`${BASE}/api/v1/store/wholesale-requests`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ name: `UI Wholesale ${RUN}`, phone: uiPhone(1), product_name: 'UI cartons', quantity: 40, message: 'From the UI flow' }) })
  const wsRef = (await wsPost.json()).data?.reference
  check(wsPost.status === 201 && wsRef, 'UI-G2 a wholesale request arrives through the public form', wsRef)
  await a.page.waitForFunction((n) => Number(document.querySelector('[data-testid="wholesale-badge"]')?.textContent || 0) === n, { timeout: 50000 }, wsBefore.json.count + 1)
  check(true, 'UI-G2 within the 30-second poll the Wholesale badge goes up by one (no reload)', { before: wsBefore.json.count })

  // ---- UI-G3 the wholesale screen
  await tapText(a.page, '.sidebar__nav a', 'Wholesale')
  await waitVal(a.page, `tr[data-ref="${wsRef}"]`)
  check(await bodyHas(a.page, new RegExp(`UI Wholesale ${RUN}`)), 'UI-G3 the request is in the list with its name', wsRef)
  const tabCounts = await a.page.$$eval('[role="tab"]', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').trim()))
  check(tabCounts[0].startsWith('All') && tabCounts.some((t) => t.startsWith('New')), 'UI-G3 status tabs with counts', tabCounts)
  await tap(a.page, `tr[data-ref="${wsRef}"]`)
  await waitVal(a.page, '#wstatus')
  const wa = await a.page.$eval('[data-testid="whatsapp"]', (el) => el.getAttribute('href'))
  check(wa.startsWith('https://wa.me/92348') && decodeURIComponent(wa).includes(wsRef), 'UI-G3 the WhatsApp button opens wa.me/<digits> with a message that names the request', wa.slice(0, 90))
  await a.page.select('#wstatus', 'contacted')
  await setValue(a.page, '#wnote', 'Called, will send a quote')
  await tap(a.page, '[data-testid="save-request"]')
  await toast(a.page, new RegExp(`${wsRef} saved`))
  const wsDb = db(`SELECT status, admin_note, handled_by FROM wholesale_requests WHERE reference = '${wsRef}'`)[0]
  check(wsDb.status === 'contacted' && wsDb.admin_note === 'Called, will send a quote' && wsDb.handled_by > 0, 'UI-G3 status and note are saved with who handled it', wsDb)
  await a.page.waitForFunction((n) => Number(document.querySelector('[data-testid="wholesale-badge"]')?.textContent || 0) === n, { timeout: 50000 }, wsBefore.json.count)
  check(true, 'UI-G3 and the sidebar badge goes back down after the poll')
  const exportWs = a.page.waitForResponse((r) => r.url().includes('/admin/wholesale/export') && r.status() === 200, { timeout: 15000 })
  await tap(a.page, '[data-testid="export-wholesale"]')
  check(/text\/csv/.test((await exportWs).headers()['content-type']), 'UI-G3 Export CSV downloads the list')
  await goto(d.page, 'wholesale')
  check(d.page.url().endsWith('/forbidden'), 'UI-G3 the developer cannot open /admin/wholesale by default')

  // ---- UI-G4 products: dense list, switches, stock filter; the compact form with sections, options table, bulk popover, validation, video
  const gp = await api('POST', 'admin/products', { category_id: db('SELECT id FROM categories WHERE deleted_at IS NULL AND is_active = 1 ORDER BY id DESC LIMIT 1')[0].id, name: `UI Switch Item ${RUN}`, sku: `UISW-${RUN}`, status: 'active', variants: [{ name: `UI Switch Item ${RUN}`, sku: `UISW-${RUN}-V`, mrp: 50, selling_price: 40, stock: 2, low_stock_threshold: 5, is_default: true }] }, devToken)
  const gpId = gp.json?.data?.id
  await goto(d.page, 'products')
  await waitVal(d.page, `tr[data-product="${gpId}"]`)
  const heads = await d.page.$$eval('.prod-table th', (els) => els.map((e) => e.innerText.trim()).filter(Boolean))
  check(['Product', 'Category', 'Price', 'Stock', 'Status', 'Sold out', 'Featured'].every((h) => heads.some((x) => x.toLowerCase() === h.toLowerCase())), 'UI-G4 the product list is a dense table: image + name, category, price, stock, status, sold-out and featured switches', heads)
  // arrange by drag and drop: the grip of the second row is dropped on the first row; the list moves at once and the order is saved
  const rowIds = () => d.page.$$eval('tr[data-product]', (rs) => rs.map((r) => Number(r.dataset.product)))
  const before = await rowIds()
  check(before.length >= 2 && (await d.page.$$('[data-testid="product-grip"]')).length === before.length, 'UI-G4 every row of the product list has a grip to drag it', before.length)
  await d.page.evaluate(() => {
    const rows = [...document.querySelectorAll('tr[data-product]')]
    const dt = new DataTransfer()
    rows[1].querySelector('[data-testid="product-grip"]').dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }))
    rows[0].dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }))
    rows[0].dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }))
  })
  await toast(d.page, /order saved/i)
  const after = await rowIds()
  const saved = db('SELECT id FROM products WHERE deleted_at IS NULL ORDER BY sort_order ASC, id DESC LIMIT 2').map((r) => r.id)
  check(after[0] === before[1] && after[1] === before[0] && saved[0] === before[1] && saved[1] === before[0], 'UI-G4 dragging a product above another moves it at once and the new order is saved', { before: before.slice(0, 3), after: after.slice(0, 3), saved })
  await tap(d.page, `tr[data-product="${gpId}"] input[data-flag="sold-out"]`)
  await toast(d.page, /shown as sold out/i)
  await tap(d.page, `tr[data-product="${gpId}"] input[data-flag="featured"]`)
  await toast(d.page, /is featured/i)
  const gpRow = db(`SELECT is_sold_out, is_featured FROM products WHERE id = ${gpId}`)[0]
  check(gpRow.is_sold_out === 1 && gpRow.is_featured === 1 && (await fetch(`${BASE}/api/v1/store/products?featured=1`).then((r) => r.json())).data.some((p) => p.id === gpId), 'UI-G4 both switches are saved (database and public ?featured=1)', gpRow)
  await d.page.select('[data-filter="stock"]', 'sold_out')
  await d.page.waitForFunction((id) => document.querySelectorAll('tr[data-product]').length >= 1 && [...document.querySelectorAll('tr[data-product]')].every((r) => r.querySelector('input[data-flag="sold-out"]').checked) && !!document.querySelector(`tr[data-product="${id}"]`), { timeout: 15000 }, gpId)
  check(true, 'UI-G4 the stock filter "Marked sold out" shows only sold-out products')
  await d.page.select('[data-filter="stock"]', 'low')
  await d.page.waitForFunction((id) => !!document.querySelector(`tr[data-product="${id}"] .stock-pill.is-low`), { timeout: 15000 }, gpId)
  check(true, 'UI-G4 the stock filter "Running low" shows it with an amber stock pill (2 left, alert at 5)')
  await api('PATCH', `admin/products/${gpId}/sold-out`, { value: false }, devToken)

  await goto(d.page, `products/${gpId}/edit`)
  await waitVal(d.page, '[data-testid="options-table"]')
  const secIds = await d.page.$$eval('.pf-card', (els) => els.map((e) => e.dataset.section))
  check(JSON.stringify(secIds) === JSON.stringify(['basics', 'options', 'stock', 'images', 'video', 'content', 'seo', 'related']), 'UI-G4 the form has the sections Basics, Options and pricing, Stock, Images, Video, Content, SEO, Related', secIds)
  check(await d.page.evaluate(() => [...document.querySelectorAll('.pf-card')].every((s) => s.dataset.open === 'true')), 'UI-G4 on a first visit every section is open')
  await tap(d.page, '[data-section="seo"] .pf-toggle')
  await tap(d.page, '[data-section="related"] .pf-toggle')
  await d.page.reload({ waitUntil: 'networkidle0' })
  await waitVal(d.page, '[data-testid="options-table"]')
  const openState = await d.page.$$eval('.pf-card', (els) => Object.fromEntries(els.map((e) => [e.dataset.section, e.dataset.open])))
  check(openState.seo === 'false' && openState.related === 'false' && openState.basics === 'true' && openState.options === 'true', 'UI-G4 a section stays collapsed after a reload (remembered per section)', openState)
  await tap(d.page, '[data-section="seo"] .pf-toggle')
  await tap(d.page, '[data-section="related"] .pf-toggle')
  check(await d.page.evaluate(() => !!document.querySelector('.save-bar') && getComputedStyle(document.querySelector('.save-bar')).position === 'sticky'), 'UI-G4 the save bar is sticky')
  // the options table: price above MRP shows the message under the cell; there is no bulk price button any more
  await setValue(d.page, '#variants-0-price', '60')
  await tap(d.page, '[data-testid="save-product"]')
  await d.page.waitForFunction(() => /cannot be higher than the regular price/i.test(document.querySelector('.opt-table')?.innerText || ''), { timeout: 10000 })
  check(true, 'UI-G4 an invalid price shows its message in the table cell (inline validation) and nothing is sent')
  await setValue(d.page, '#variants-0-price', '40')
  check(!(await d.page.$('[data-testid="bulk-button"]')) && !(await d.page.$('.tier-pop')), 'UI-G4 the options table has no Bulk button or popover')
  await setValue(d.page, '#variants-0-stock', '30')
  await tap(d.page, '[data-testid="save-product"]')
  await toast(d.page, /product saved/i)
  check(db(`SELECT stock_qty FROM product_variants WHERE product_id = ${gpId}`)[0].stock_qty === 30 && Number(db(`SELECT selling_price FROM product_variants WHERE product_id = ${gpId}`)[0].selling_price) === 40, 'UI-G4 the price (40) and the stock (30) are saved from the table')
  // video: upload with a poster, progress, listed, deleted; a link
  await setValue(d.page, '#video_url', 'https://youtu.be/dQw4w9WgXcQ')
  const videoInput = await d.page.$('[data-testid="video-input"]')
  await videoInput.uploadFile(MP4)   // choosing the file is the upload
  await toast(d.page, /video added/i)
  check(db("SELECT COUNT(*) n FROM media WHERE collection_name = 'video'")[0].n >= 1 && await d.page.$eval('[data-testid="video-manager"]', (e) => e.innerText.includes('g-clip.mp4')), 'UI-G4 a video file is uploaded from the form and listed')
  await tap(d.page, '[data-testid="save-product"]')
  await toast(d.page, /product saved/i)
  const pubVideos = (await fetch(`${BASE}/api/v1/store/products/${gp.json.data.slug}`).then((r) => r.json())).data.videos
  check(pubVideos.length === 2 && pubVideos[0].source === 'upload' && pubVideos[1].url.includes('youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'UI-G4 the public product page has the uploaded video and the YouTube embed', pubVideos.map((v) => v.source))
  await d.page.evaluate(() => { window.confirm = () => true })   // Chrome stops showing dialogs on a page that had many: answer the confirmation ourselves
  await d.page.$eval('[data-testid="video-manager"] button[aria-label^="Delete"]', (el) => el.click())
  await d.page.waitForFunction(() => !document.querySelector('[data-testid="video-manager"]')?.innerText.includes('g-clip.mp4'), { timeout: 10000 })
  check(db("SELECT COUNT(*) n FROM media WHERE collection_name = 'video' AND model_id = " + gpId)[0].n === 0, 'UI-G4 the video is deleted from the form')
  // create form: a video file can be chosen BEFORE the product exists and is uploaded right after the save; the SKU is made from the name
  await goto(d.page, 'products/new')
  await waitVal(d.page, '[data-section="video"]')
  check(!(await bodyHas(d.page, /Save the product first, then add video files/)) && !!(await d.page.$('[data-testid="video-input"]')), 'UI-G4 a new product can choose a video file at once (no "save first")')
  const newCat = await d.page.$$eval('#category option', (os) => os.find((o) => o.value)?.value)
  await d.page.select('#category', newCat)
  await setValue(d.page, '#name', `UI Video Toy ${RUN}`)
  check(await d.page.$eval('#sku', (el) => el.value) === `UI-VIDEO-TOY-${RUN}`.toUpperCase(), 'UI-G4 the SKU follows the typed name', await d.page.$eval('#sku', (el) => el.value))
  await setValue(d.page, '#variants-0-mrp', '90')
  await setValue(d.page, '#variants-0-price', '80')
  await setValue(d.page, '#variants-0-stock', '4')
  await (await d.page.$('[data-testid="video-input"]')).uploadFile(MP4)   // a new product keeps the chosen file until it is saved
  check(await bodyHas(d.page, /uploaded when you save the product/), 'UI-G4 the chosen video waits in the form until the product is saved')
  await tap(d.page, '[data-testid="save-product"]')
  await toast(d.page, /product added/i, 30000)
  const vidProd = db(`SELECT id, sku FROM products WHERE name = 'UI Video Toy ${RUN}'`)[0]
  check(vidProd && vidProd.sku === `UI-VIDEO-TOY-${RUN}`.toUpperCase() && db(`SELECT COUNT(*) n FROM media WHERE collection_name = 'video' AND model_id = ${vidProd?.id}`)[0].n === 1, 'UI-G4 the new product is saved with its automatic SKU and the video is uploaded with it', vidProd)
  await goto(d.page, 'products/new')
  const subsExist = (await api('GET', 'admin/categories/options', null, devToken)).json.data.some((c) => c.sub_categories.length > 0)
  check(!!(await d.page.$('[data-field="sub_category"]')) === subsExist, 'UI-G4 the sub-category field shows only when a sub-category exists', { subsExist })

  // ---- UI-G5 orders and customers: CSV export, WhatsApp, abandoned carts
  await goto(a.page, 'orders')
  await waitVal(a.page, 'tbody tr')
  const exportOrders = a.page.waitForResponse((r) => r.url().includes('/admin/orders/export') && r.status() === 200, { timeout: 15000 })
  await tap(a.page, '[data-testid="export-orders"]')
  check(/text\/csv/.test((await exportOrders).headers()['content-type']), 'UI-G5 the orders list has Export CSV (orders.export)')
  const firstOrder = db('SELECT id, customer_phone FROM orders ORDER BY id DESC LIMIT 1')[0]
  await goto(a.page, `orders/${firstOrder.id}`)
  await waitVal(a.page, '[data-testid="whatsapp"]')
  const waOrder = await a.page.$eval('[data-testid="whatsapp"]', (el) => el.getAttribute('href'))
  check(waOrder.startsWith(`https://wa.me/${firstOrder.customer_phone.replace(/\D/g, '')}`) && /ORD|JHK|ZED/.test(decodeURIComponent(waOrder)), 'UI-G5 the order page has a WhatsApp button with the customer number and the order number in the message', waOrder.slice(0, 100))
  // an abandoned cart: a cart with a contact, aged by three hours
  const cartRes = await fetch(`${BASE}/api/v1/store/cart`, { headers: { Accept: 'application/json' } })
  const cartTok = (await cartRes.json()).data.token
  const cartVariant = db('SELECT id FROM product_variants WHERE is_active = 1 AND stock_qty > 5 ORDER BY id LIMIT 1')[0].id
  await fetch(`${BASE}/api/v1/store/cart/items`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Cart-Token': cartTok }, body: JSON.stringify({ variant_id: cartVariant, qty: 2 }) })
  await fetch(`${BASE}/api/v1/store/cart/contact`, { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Cart-Token': cartTok }, body: JSON.stringify({ name: `UI Abandoned ${RUN}`, phone: uiPhone(2), email: 'abandoned@example.test' }) })
  execFileSync('php', ['artisan', 'tinker', '--execute', `App\\Domain\\Cart\\Models\\Cart::where('token', '${cartTok}')->update(['last_activity_at' => now()->subHours(3)]);`], { cwd: ROOT, stdio: 'ignore' })
  await goto(a.page, 'customers')
  await waitVal(a.page, '[data-tab="abandoned"]')
  await tap(a.page, '[data-tab="abandoned"]')
  await waitVal(a.page, '[data-testid="abandoned-table"]')
  check(await bodyHas(a.page, new RegExp(`UI Abandoned ${RUN}`)) && !(await bodyHas(a.page, new RegExp(cartTok))), 'UI-G5 the Abandoned carts tab lists the cart (name, phone, items, value) and never shows the token')
  const waCart = await a.page.$eval('[data-testid="abandoned-table"] [data-testid="whatsapp"]', (el) => el.getAttribute('href'))
  check(waCart.startsWith('https://wa.me/') && decodeURIComponent(waCart).includes('left 2 items'), 'UI-G5 each row has a WhatsApp button with a message about the cart', waCart.slice(0, 90))
  const exportCarts = a.page.waitForResponse((r) => r.url().includes('/abandoned-carts/export') && r.status() === 200, { timeout: 15000 })
  await tap(a.page, '[data-testid="export-customers"]')
  check(/text\/csv/.test((await exportCarts).headers()['content-type']), 'UI-G5 the abandoned carts tab has its own CSV')

  // ---- UI-G6 Settings > Notifications: the mail setup, the test e-mail, the push bell
  await goto(a.page, 'settings?tab=notifications')
  await waitVal(a.page, '#s-mail_enabled')
  const notiSwitches = await a.page.$$eval('[data-switch]', (els) => els.map((e) => e.dataset.switch))
  const notiFields = [...notiSwitches, ...(await a.page.$$eval('input[id^="s-"]', (els) => els.map((e) => e.id.slice(2))))]
  check(['mail_enabled', 'mail_on_placed', 'mail_on_confirmed', 'mail_on_shipped', 'mail_on_delivered', 'mail_on_cancelled', 'mail_on_payment_proof_rejected', 'mail_on_fee_confirmed', 'owner_mail_on_placed', 'owner_mail_on_proof', 'owner_mail_on_wholesale', 'push_on_placed', 'push_on_proof', 'push_on_wholesale', 'push_enabled'].every((k) => notiFields.includes(k)) && !notiFields.includes('notify_admin_email') && !notiFields.includes('mail_from_name'), 'UI-G6 the Notifications tab has the e-mail switches, the owner address, the tracking page and the push switch', notiFields)
  await waitVal(a.page, '[data-testid="mail-status"]')
  check(/^\d+$/.test(await textOf(a.page, '[data-testid="failed-jobs"]').then((t) => t.split(' ')[0])), 'UI-G6 the failed e-mail count is shown (a number, never the content)', await textOf(a.page, '[data-testid="failed-jobs"]'))
  await setValue(a.page, '#test-email', 'ui-test@example.test')
  await tap(a.page, '[data-testid="send-test-email"]')
  await a.page.waitForFunction(() => /Test e-mail sent to ui-test@example.test/.test(document.querySelector('[data-testid="test-email-result"]')?.innerText || ''), { timeout: 15000 })
  check(true, 'UI-G6 "Send test" reports where the test e-mail went')
  await setValue(a.page, '#test-email', 'not-an-address')
  await tap(a.page, '[data-testid="send-test-email"]')
  await a.page.waitForFunction(() => /valid|email/i.test(document.querySelector('[data-testid="test-email-result"]')?.innerText || '') && !/sent to/.test(document.querySelector('[data-testid="test-email-result"]').innerText), { timeout: 15000 })
  check(true, 'UI-G6 a bad address shows the server message')
  // the bell: a fake PushManager lets this headless browser walk through subscribe and unsubscribe
  await api('PUT', 'admin/settings', { push_enabled: false }, admToken)
  await goto(a.page, '')
  await waitVal(a.page, '[data-testid="push-toggle"]')
  await sleep(500)
  check(/switched off in Settings/i.test(await a.page.$eval('[data-testid="push-toggle"]', (el) => el.title)), 'UI-G6 the bell explains that push is switched off in Settings while it is')
  await api('PUT', 'admin/settings', { push_enabled: true }, admToken)
  await a.page.evaluateOnNewDocument(() => {
    let current = null
    const fake = (opts) => ({ endpoint: `https://push.invalid/ui-${Math.random().toString(36).slice(2)}`, toJSON() { return { endpoint: this.endpoint, keys: { p256dh: 'BPFAKEKEYFORTHETEST', auth: 'FAKEAUTHKEY' } } }, async unsubscribe() { current = null; return true }, opts })
    PushManager.prototype.subscribe = async function (opts) { current = fake(opts); return current }
    PushManager.prototype.getSubscription = async function () { return current }
    Object.defineProperty(Notification, 'permission', { get: () => 'granted', configurable: true })
    Notification.requestPermission = async () => 'granted'
  })
  await goto(a.page, '')
  await waitVal(a.page, '[data-testid="push-toggle"]')
  await sleep(800)
  check(/Get a notification on this device/i.test(await a.page.$eval('[data-testid="push-toggle"]', (el) => el.title)), 'UI-G6 push on: the bell offers to turn notifications on for this device')
  await tap(a.page, '[data-testid="push-toggle"]')
  await toast(a.page, /Push notifications are on/i)
  check(db('SELECT COUNT(*) n FROM push_subscriptions')[0].n === 1 && (await a.page.$eval('[data-testid="push-toggle"]', (el) => el.getAttribute('aria-pressed'))) === 'true', 'UI-G6 pressing the bell subscribes this browser (a row in push_subscriptions) and the bell turns green')
  await tap(a.page, '[data-testid="push-toggle"]')
  await toast(a.page, /off on this device/i)
  check(db('SELECT COUNT(*) n FROM push_subscriptions')[0].n === 0, 'UI-G6 pressing it again unsubscribes')
  await api('PUT', 'admin/settings', { push_enabled: false }, admToken)
  await goto(d.page, '')
  check(!(await d.page.$('[data-testid="push-toggle"]')), 'UI-G6 the developer account has no bell (no orders.view, no wholesale.view)')

  // ---- UI-C3 the notification status card and the test notification (the fake PushManager of the section above is still installed)
  await api('PUT', 'admin/settings', { push_enabled: true }, admToken)
  await a.page.evaluateOnNewDocument(() => {
    window.__plays = []
    HTMLMediaElement.prototype.play = function () { window.__plays.push(String(this.src).split('/').pop()); return Promise.resolve() }   // count the chime instead of making noise
  })
  await goto(a.page, 'settings?tab=notifications')
  await waitVal(a.page, '[data-testid="notification-status"]')
  await sleep(600)
  const rowsNs = await a.page.$$eval('[data-testid="notification-status"] [data-row]', (els) => Object.fromEntries(els.map((e) => [e.dataset.row, e.dataset.tone])))
  check(['browser', 'permission', 'device', 'vapid', 'switch', 'queue', 'last'].every((k) => k in rowsNs) && rowsNs.vapid === 'ok' && rowsNs.switch === 'ok' && rowsNs.browser === 'ok', 'UI-C3 the Notification status card lists browser support, permission, subscription, VAPID, the switch, the queue worker and the last push', rowsNs)
  await tap(a.page, '[data-testid="send-test-push"]')
  await a.page.waitForFunction(() => document.querySelector('[data-testid="test-push-result"]')?.innerText.length > 5, { timeout: 15000 })
  await a.page.waitForFunction(() => document.querySelector('[data-testid="test-push-result"]')?.classList.contains('is-error') || document.querySelector('[data-testid="test-push-result"]')?.classList.contains('is-ok'), { timeout: 40000 })
  const testText = await textOf(a.page, '[data-testid="test-push-result"]')
  check(testText.length > 10, 'UI-C3 "Send test notification" goes through the queue and the card shows the outcome or the exact error', testText)
  // the header popover next to the bell shows the same card
  await goto(a.page, '')
  await tap(a.page, '[data-testid="notification-status-btn"]')
  await waitVal(a.page, '.status-pop [data-testid="notification-status"]')
  check(!!(await a.page.$('.status-pop [data-testid="send-test-push"]')), 'UI-C3 the status button next to the bell opens the card with the test button')
  await tap(a.page, '[data-testid="notification-status-btn"]')

  // ---- UI-C3b the in-panel alert: a rising pending count shows a toast and plays the chime; the speaker button mutes it (kept in localStorage)
  await a.page.evaluate(() => localStorage.removeItem('admin_sound_muted'))
  await goto(a.page, '')
  await sleep(1200)
  const playsBefore = await a.page.evaluate(() => window.__plays.length)
  const alertVariant = db("SELECT id FROM product_variants WHERE is_active = 1 AND stock_qty > 12 ORDER BY id LIMIT 1")[0]?.id
  if (alertVariant) {
    const rr = await fetch(`${BASE}/api/v1/store/orders`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': `flowui-c3-${Date.now()}-xxxxxxxx` }, body: JSON.stringify({ customer: { name: 'UI Alert Buyer', phone: '+92 355 9100001' }, address: { line1: '1 Test Road', city: 'Lahore' }, items: [{ variant_id: alertVariant, qty: 1 }] }) })
    await rr.arrayBuffer()
    await a.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))   // the same refresh the 30-second timer runs
    await toast(a.page, /new order/i)
    check((await a.page.evaluate(() => window.__plays)).slice(playsBefore).some((n) => /chime/.test(n)), 'UI-C3 when the pending count rises the panel shows a toast and plays the bundled chime')
    await tap(a.page, '[data-testid="sound-toggle"]')
    check((await a.page.evaluate(() => localStorage.getItem('admin_sound_muted'))) === '1' && (await a.page.$eval('[data-testid="sound-toggle"]', (e) => e.getAttribute('aria-pressed'))) === 'false', 'UI-C3 the speaker button mutes the alert and remembers it in this browser')
    const playsMuted = await a.page.evaluate(() => window.__plays.length)
    const r2 = await fetch(`${BASE}/api/v1/store/orders`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': `flowui-c3b-${Date.now()}-xxxxxxxx` }, body: JSON.stringify({ customer: { name: 'UI Alert Buyer Two', phone: '+92 355 9100002' }, address: { line1: '1 Test Road', city: 'Lahore' }, items: [{ variant_id: alertVariant, qty: 1 }] }) })
    await r2.arrayBuffer()
    await a.page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')))
    await toast(a.page, /new order/i)
    await sleep(500)
    check((await a.page.evaluate(() => window.__plays.length)) === playsMuted, 'UI-C3 muted: the toast still shows, but no sound is played')
    await goto(a.page, '')
    check((await a.page.$eval('[data-testid="sound-toggle"]', (e) => e.getAttribute('aria-pressed'))) === 'false', 'UI-C3 the mute choice survives a reload')
    await tap(a.page, '[data-testid="sound-toggle"]')
    check((await a.page.evaluate(() => localStorage.getItem('admin_sound_muted'))) === '0', 'UI-C3 unmuting switches the sound back on')
  }
  check(!(await d.page.$('[data-testid="sound-toggle"]')) && !(await d.page.$('[data-testid="notification-status-btn"]')), 'UI-C3 the developer header has neither the sound toggle nor the status button')

  // ---- UI-H1 Settings > Website: the developer sets the hook (address + secret), the client presses Rebuild website now, the failure is shown, the secret never is
  {
    const HOOK = 'https://127.0.0.1:9/ui-hook-token-abc'
    const SECRET = 'ui-secret-value-789'
    await goto(d.page, 'settings?tab=website')
    await d.page.waitForSelector('[data-testid="website-status"]', { timeout: 15000 })
    check(!!(await d.page.$('#s-rebuild_hook_url')) && !!(await d.page.$('#s-rebuild_hook_secret')) && !!(await d.page.$('#s-rebuild_debounce_seconds')) && !!(await d.page.$('#s-rebuild_enabled')), 'UI-H1 the developer sees the hook address, secret, wait time and the switch in the Website tab')
    check(await d.page.$eval('[data-testid="rebuild-now"]', (e) => e.disabled), 'UI-H1 Rebuild website now is off while no hook is set')
    await setValue(d.page, '#s-rebuild_hook_url', 'http://not-secure.example/hook')
    await sleep(150)
    await tap(d.page, '[data-testid="save-settings"]')
    await d.page.waitForSelector('.has-error, .err', { timeout: 10000 })
    check(/https:\/\//.test(await d.page.$eval('[data-field="rebuild_hook_url"]', (e) => e.innerText)), 'UI-H1 a hook address that is not https shows a message under the field')
    await setValue(d.page, '#s-rebuild_hook_url', HOOK)
    await setValue(d.page, '#s-rebuild_hook_secret', SECRET)
    await sleep(150)
    check((await textOf(d.page, '[data-field="rebuild_hook_secret"] [data-testid="secret-state"]')).includes('replaced'), 'UI-H1 typing a secret says it will be saved')
    check(await d.page.$eval('#s-rebuild_hook_secret', (e) => e.type === 'password'), 'UI-H1 the secret is typed into a password box')
    await tap(d.page, '[data-testid="save-settings"]')
    await toast(d.page, /settings saved/)
    await sleep(500)
    check((await textOf(d.page, '[data-field="rebuild_hook_secret"] [data-testid="secret-state"]')) === 'Set' && (await d.page.$eval('#s-rebuild_hook_secret', (e) => e.value)) === '', 'UI-H1 after saving, the secret box is empty and says "Set": the secret is never shown again')
    check(!(await d.page.evaluate(() => document.documentElement.outerHTML)).includes(SECRET), 'UI-H1 the secret is nowhere in the page')
    await d.page.waitForFunction(() => document.querySelector('[data-testid="rebuild-now"]') && !document.querySelector('[data-testid="rebuild-now"]').disabled, { timeout: 15000 }).catch(() => {})   // the status card reloads after the save
    check(!(await d.page.$eval('[data-testid="rebuild-now"]', (e) => e.disabled)), 'UI-H1 with a hook set, the button is on')
    // the shop owner's admin account does not get the Website tab at all (it is the developer's)
    await goto(a.page, 'settings?tab=website')
    await a.page.waitForSelector('[role="tablist"]', { timeout: 15000 })
    check(!(await a.page.$('[data-testid="rebuild-now"]')) && !(await a.page.$$eval('[role="tab"]', (ts) => ts.some((t) => /^\s*Website\s*$/.test(t.innerText)))), 'UI-H1 the shop owner (admin) has no Website tab and no Rebuild button')
    await d.page.screenshot({ path: path.join(OUT, 'website-tab-developer.png') })
    // clear the secret with the Clear button
    await tap(d.page, '[data-testid="secret-clear"]')
    await sleep(150)
    check((await textOf(d.page, '[data-field="rebuild_hook_secret"] [data-testid="secret-state"]')).includes('cleared'), 'UI-H1 Clear marks the secret to be removed')
    await tap(d.page, '[data-testid="save-settings"]')
    await toast(d.page, /settings saved/)
    await sleep(400)
    check((await textOf(d.page, '[data-field="rebuild_hook_secret"] [data-testid="secret-state"]')) === 'Not set', 'UI-H1 after saving, the secret is "Not set"')
    await api('PUT', 'admin/settings', { rebuild_hook_url: '', rebuild_enabled: false }, devToken)
    console.log(`  (screenshots of the Website tab: ${OUT})`)
  }

  // ---- UI-G7 time zone: a discount ending 23:59 shop time is typed, saved, shown and ended in the SHOP zone (a server in Pacific/Auckland)
  if (process.env.E2E_BASE_TZ) {
    const TZ = process.env.E2E_BASE_TZ
    const tzp = await open()
    try {
      const tzLogin = async () => { await tzp.page.goto(`${TZ}/admin/login`, { waitUntil: 'networkidle0' }); await setValue(tzp.page, '#username', ADMIN[0]); await setValue(tzp.page, '#password', ADMIN[1]); await Promise.all([tzp.page.waitForSelector('.sidebar__nav', { timeout: 30000 }), tzp.page.click('button[type="submit"]')]) }
      await tzLogin()
      const tzMe = await tzp.page.evaluate(async () => (await (await fetch('/api/v1/admin/auth/me', { credentials: 'include' })).json()).admin.timezone)
      check(tzMe === 'Pacific/Auckland', 'UI-G7 /admin/auth/me tells the panel the store zone (Pacific/Auckland)', tzMe)
      await tzp.page.goto(`${TZ}/admin/discounts`, { waitUntil: 'networkidle0' })
      await tzp.page.waitForSelector('[data-add]', { timeout: 15000 })
      await tap(tzp.page, '[data-add]')
      await tzp.page.waitForSelector('#pend', { timeout: 10000 })
      check(/Store time \(Pacific\/Auckland\)/.test(await tzp.page.$eval('#pend', (e) => e.closest('.field').innerText)), 'UI-G7 the date inputs say which zone they are in')
      const dName = `UI Zone Sale ${RUN}`
      await setValue(tzp.page, '#pname', dName)
      await setValue(tzp.page, '#pvalue', '10')
      await setValue(tzp.page, '#pend', '2030-06-30T23:59')
      await tap(tzp.page, 'button[form="promo-form"]')
      await toast(tzp.page, /discount added/i)
      const zoned = db(`SELECT id, ends_at FROM discounts WHERE name = '${dName}'`)[0]
      check(zoned && String(zoned.ends_at).includes('2030-06-30 23:59'), 'UI-G7 typed as 23:59 shop time: stored as 23:59 (not shifted by the browser or UTC)', zoned)
      if (zoned) created.discounts.push(zoned.id)
      const viaApi = (await (await fetch(`${TZ}/api/v1/admin/discounts/${zoned?.id}`, { headers: { Accept: 'application/json', Authorization: `Bearer ${(await (await fetch(`${TZ}/api/v1/admin/auth/login`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ login: ADMIN[0], password: ADMIN[1] }) })).json()).token}` } })).json()).data
      check(/^2030-06-30T23:59:00\+1[23]:00$/.test(viaApi?.ends_at || ''), 'UI-G7 the API answers with the shop offset (+12:00 / +13:00), not UTC', viaApi?.ends_at)
      await tzp.page.reload({ waitUntil: 'networkidle0' })
      await tzp.page.waitForFunction((n) => document.body.innerText.includes(n), { timeout: 15000 }, dName)
      check(/11:59\s?PM|23:59/.test(await tzp.page.evaluate((n) => [...document.querySelectorAll('tr')].find((r) => r.innerText.includes(n))?.innerText || '', dName)), 'UI-G7 the list shows the end as 11:59 PM / 23:59 (shop time)')
    } finally {
      await tzp.browser.close()
    }
  } else {
    console.log('SKIP  UI-G7 time zone check: start a server with APP_TIMEZONE=Pacific/Auckland and set E2E_BASE_TZ (see the header)')
  }

  // ============================================================ UI-S: the panel's own drop-down list and calendar, the copy icons, no explaining text on the cards
  await api('PUT', 'admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, devToken)   // earlier flows changed what the admin may do
  await goto(a.page, 'orders')
  await a.page.waitForSelector('select[aria-label="Payment"]', { timeout: 15000 }).catch(async (e) => { console.log(`  (UI-S: no select; url ${a.page.url()}; text: ${(await a.page.evaluate(() => document.body.innerText)).slice(0, 300).replace(/s+/g, ' ')})`); throw e })
  await a.page.click('select[aria-label="Payment"]')
  await a.page.waitForSelector('.pop--list')
  check((await a.page.$$eval('.pop__opt', (o) => o.length)) >= 3, 'UI-S1 a click on a select opens the panel\'s own list (not the browser\'s)')
  await a.page.click('.pop__opt:nth-child(2)')
  await a.page.waitForFunction(() => location.search.includes('payment_status='), { timeout: 15000 })
  check(!(await a.page.$('.pop--list')), 'UI-S1 picking an option closes the list and sets the filter', a.page.url())
  await a.page.click('input[aria-label="From date"]')
  await a.page.waitForSelector('.pop--cal')
  await a.page.click('.cal__day.is-today')
  await a.page.waitForFunction(() => location.search.includes('from='), { timeout: 15000 })
  check(/from=\d{4}-\d{2}-\d{2}/.test(a.page.url()) && !(await a.page.$('.pop--cal')), 'UI-S2 the panel\'s own calendar picks a date', a.page.url())
  await goto(a.page, 'customers')
  await a.page.waitForSelector('[data-testid="customers-table"] [data-testid="copy"]')
  await a.page.evaluate(() => { window.__clip = ''; navigator.clipboard.writeText = async (t) => { window.__clip = t } })
  await a.page.click('[data-testid="customers-table"] [data-testid="copy"]')
  await a.page.waitForFunction(() => window.__clip !== '', { timeout: 5000 })
  check(/^\+?\d[\d ]{6,}$/.test(await a.page.evaluate(() => window.__clip)), 'UI-S3 the copy icon next to a phone number copies it', await a.page.evaluate(() => window.__clip))
  // the delivery boxes of an order: copy icons for the name, phone, e-mail and address, and one button for all of it
  const shipOrder = db("SELECT id FROM orders ORDER BY id DESC LIMIT 1")[0]
  await goto(a.page, 'orders/' + shipOrder.id)
  await a.page.waitForSelector('[data-testid="copy-shipping"]')
  await a.page.evaluate(() => { window.__clip = ''; navigator.clipboard.writeText = async (t) => { window.__clip = t } })
  const icons = await a.page.$$eval('.addr [data-testid="copy"]', (b) => b.length)
  await a.page.click('[data-testid="copy-shipping"]')
  await a.page.waitForFunction(() => window.__clip !== '', { timeout: 5000 })
  const copied = await a.page.evaluate(() => window.__clip)
  check(icons >= 3 && copied.split('\n').length >= 4 && /\d{6,}/.test(copied), 'UI-S5 the shipping address has copy icons (name, phone, e-mail, address) and a "Copy all" that copies the delivery block', { icons, copied })
  await goto(a.page, '')
  await a.page.waitForSelector('[data-kpi="sales"]')
  check(!(await a.page.$('.kpi__under')) && (await a.page.$$eval('.kpi', (k) => k.length)) === 4, 'UI-S4 the four cards carry no explaining text')
  // big figures: the card keeps one line (smaller type, the full figure in the tooltip) instead of breaking
  await a.page.evaluate(() => { const v = document.querySelector('[data-kpi="sales"] .kpi__value'); v.textContent = '$8,853,333,333,333,333.33'; v.className = 'kpi__value stat__value is-sm' })
  const fits = await a.page.$eval('[data-kpi="sales"] .kpi__value', (e) => ({ h: Math.round(e.getBoundingClientRect().height), oneLine: e.getBoundingClientRect().height < 40 }))
  check(fits.oneLine, 'UI-S6 a very long figure stays on one line in its card', fits)


  // ============================================================ UI-T: Help (the how-to videos) and the installable app (manifest, service worker, offline page)
  await goto(a.page, 'help')
  await a.page.waitForSelector('[data-testid="help"]', { timeout: 15000 })
  const slugs = await a.page.$$eval('[data-video]', (e) => e.map((x) => x.dataset.video))
  check(slugs.includes('add-discount') && slugs.includes('add-coupon') && slugs.includes('settings') && !slugs.includes('add-product'), 'UI-T1 Help lists the videos of the screens this account may use, and not the others', slugs)
  const meta = await a.page.$eval('[data-testid="help-video"]', (v) => new Promise((res) => { const done = () => res({ dur: Math.round(v.duration), w: v.videoWidth, err: v.error ? v.error.code : null }); if (v.readyState >= 1) done(); else { v.addEventListener('loadedmetadata', done); v.addEventListener('error', done); v.load() } }))
  check(meta.dur >= 20 && meta.w === 1280 && !meta.err, 'UI-T1 the video loads (length and picture size)', meta)
  check((await a.page.$$eval('.help__steps li', (l) => l.length)) >= 5, 'UI-T1 the steps are written under the video')
  await tap(a.page, '[data-video="settings"]')
  await a.page.waitForFunction(() => location.search.includes('v=settings'), { timeout: 5000 })
  check((await textOf(a.page, '.help__body h2')) === 'Settings', 'UI-T1 another video can be chosen', await textOf(a.page, '.help__body h2'))
  check(/Help/.test(await textOf(a.page, '.sidebar__nav')), 'UI-T1 Help is in the sidebar')
  await goto(d.page, 'help')
  await d.page.waitForSelector('[data-testid="help"]', { timeout: 15000 })
  const devSlugs = await d.page.$$eval('[data-video]', (e) => e.map((x) => x.dataset.video))
  check(devSlugs.includes('add-product') && devSlugs.includes('edit-product') && devSlugs.includes('categories'), 'UI-T1 the developer also sees the catalogue videos', devSlugs)

  const man = await a.page.evaluate(async () => { const r = await fetch(document.querySelector('link[rel="manifest"]').href); return { ok: r.ok, type: r.headers.get('content-type'), j: await r.json() } })
  check(man.ok && /manifest\+json/.test(man.type) && man.j.start_url === '/admin/' && man.j.scope === '/admin/' && man.j.display === 'standalone' && /Admin$/.test(man.j.name), 'UI-T2 the app manifest carries the store name, the start page and the standalone display', man.j.name)
  const iconsOk = await a.page.evaluate(async (icons) => (await Promise.all(icons.map((i) => fetch(i.src).then((r) => r.ok)))).every(Boolean), man.j.icons)
  const head = await a.page.evaluate(() => ({ apple: !!document.querySelector('link[rel="apple-touch-icon"]'), svg: !!document.querySelector('link[rel="icon"][type="image/svg+xml"]'), theme: !!document.querySelector('meta[name="theme-color"]') }))
  check(iconsOk && head.apple && head.svg && head.theme, 'UI-T2 the icons of the manifest load, and the page has the favicon, the apple touch icon and the theme colour', head)
  await a.page.evaluate(() => navigator.serviceWorker.ready)
  await a.page.reload({ waitUntil: 'networkidle0' })   // from now on the worker controls the panel
  const sw = await a.page.evaluate(async () => ({ controlled: Boolean(navigator.serviceWorker.controller), cached: (await (await caches.open('admin-offline-v1')).keys()).length }))
  check(sw.controlled && sw.cached === 1, 'UI-T2 the service worker controls the panel and keeps the offline page', sw)
  const swTarget = await a.browser.waitForTarget((t) => t.type() === 'service_worker', { timeout: 10000 })
  const swCdp = await swTarget.createCDPSession()
  const net = (offline) => swCdp.send('Network.emulateNetworkConditions', { offline, latency: 0, downloadThroughput: -1, uploadThroughput: -1 })
  await swCdp.send('Network.enable')
  await net(true)
  await a.page.setOfflineMode(true)
  await a.page.goto(`${BASE}/admin/orders`, { waitUntil: 'load' }).catch(() => {})
  const offlineShown = /You are offline/.test(await a.page.evaluate(() => document.body.innerText))
  await a.page.setOfflineMode(false)
  await net(false)
  check(offlineShown, 'UI-T2 without a connection the panel shows the "You are offline" page, not the browser error')
  await goto(a.page, '')
  check(!!(await a.page.$('.sidebar__nav')), 'UI-T2 and works again when the connection is back')
  for (let i = a.problems.length - 1; i >= 0; i--) if (/ERR_INTERNET_DISCONNECTED/.test(a.problems[i])) a.problems.splice(i, 1)   // the failed request of the offline test is expected

  // ============================================================ UI-R: profile, password, theme, the plain sidebar, the logout icon
  await goto(a.page, '')
  check(!(await a.page.$('.nav-label')) && !(await a.page.$('.user-btn')), 'UI-R1 the sidebar has no group headings and the top bar no name button')
  check(!!(await a.page.$('[data-testid="logout"]')) && !!(await a.page.$('[data-testid="theme-toggle"]')), 'UI-R1 the top right has the theme toggle and the logout icon')
  check(/Admin/.test(await textOf(a.page, '[data-testid="profile-link"]')), 'UI-R1 the person (name and role) sits at the bottom of the sidebar', await textOf(a.page, '[data-testid="profile-link"]'))
  await a.page.evaluate(() => localStorage.removeItem('admin_theme'))
  await tap(a.page, '[data-testid="theme-toggle"]')
  check((await a.page.evaluate(() => document.documentElement.dataset.theme)) === 'dark', 'UI-R2 the theme toggle makes the panel dark')
  await a.page.reload({ waitUntil: 'networkidle0' })
  check((await a.page.evaluate(() => document.documentElement.dataset.theme)) === 'dark', 'UI-R2 the dark theme survives a reload (no flash: it is applied before the page is drawn)')
  await tap(a.page, '[data-testid="theme-toggle"]')
  check((await a.page.evaluate(() => document.documentElement.dataset.theme)) !== 'dark', 'UI-R2 and switches back to light')
  await tap(a.page, '[data-testid="profile-link"]')
  await a.page.waitForSelector('[data-testid="password-form"]', { timeout: 15000 })
  check(a.page.url().endsWith('/profile'), 'UI-R3 the person at the bottom opens the profile page', a.page.url())
  const typeOf = () => a.page.$eval('#pw-new', (e) => e.type)
  const pwType = await typeOf()
  await tap(a.page, '[data-testid="pw-new-eye"]')
  check(pwType === 'password' && (await typeOf()) === 'text', 'UI-R3 the eye button shows the password and hides it again')
  await tap(a.page, '[data-testid="pw-new-eye"]')
  await setValue(a.page, '#pw-current', 'wrong-password'); await setValue(a.page, '#pw-new', 'brand-new-pass-1'); await setValue(a.page, '#pw-again', 'brand-new-pass-1')
  await tap(a.page, '[data-testid="password-save"]')
  await a.page.waitForFunction(() => /not correct/i.test(document.querySelector('[data-testid="password-form"]')?.innerText || ''), { timeout: 15000 })
  check(true, 'UI-R3 a wrong current password is told, nothing changes')
  await setValue(a.page, '#pw-current', ADMIN[1])
  await tap(a.page, '[data-testid="password-save"]')
  await a.page.waitForSelector('[data-testid="password-done"]', { timeout: 15000 })
  check(true, 'UI-R3 the password is changed')
  // put it back: the panel itself needs 8 characters, the seeded password is shorter, so the database is used (the session stays signed in)
  execFileSync('php', ['artisan', 'tinker', '--execute', `DB::table('admins')->where('username', 'admin')->update(['password' => bcrypt('admin')]);`], { cwd: ROOT, stdio: 'ignore' })
  check(!!(await tokenOf(ADMIN)), 'UI-R3 the old password signs in again after putting it back')

  check(a.problems.length === 0 && d.problems.length === 0, `no script errors in either browser (${[...a.problems, ...d.problems].join('; ') || 'none'})`)
} catch (e) {
  failures++
  console.log(`FAIL  script error: ${e.stack || e}`)
} finally {
  // put the settings back and remove what the Phase A flows made
  try {
    await api('PUT', 'admin/settings', snapAdmin, admToken)
    if (snapThemePrimary) await api('PUT', 'admin/settings', { theme_primary: snapThemePrimary }, devToken)
    for (const z of (await api('GET', 'admin/delivery-zones', null, admToken)).json?.data || []) if (z.name.startsWith('UI Zone')) await api('DELETE', `admin/delivery-zones/${z.id}`, null, admToken)
    for (const id of created.discounts) await api('DELETE', `admin/discounts/${id}`, null, admToken)
    for (const id of created.coupons) { const r = await api('DELETE', `admin/coupons/${id}`, null, admToken); if (r.status !== 200) await api('PATCH', `admin/coupons/${id}/active`, { is_active: false }, admToken) }
  } catch (e) { console.log(`  (cleanup problem: ${e.message})`) }
  await api('PUT', 'admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, devToken)
  await a.browser.close()
  await d.browser.close()
}
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed')
console.log(`(PDF print views: ${OUT})`)
process.exit(failures ? 1 : 0)

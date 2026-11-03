// Browser side of the flow check (headless Chrome through puppeteer-core): the admin screens driven like a person would, on the REAL app.
//
//   php artisan migrate:fresh --seed && php artisan serve          (and run `node scripts/flow-check.mjs` first: it leaves orders to work on;
//                                                                    without them this script places a few itself)
//   npm run build                                                  (admin panel -> public/admin-assets)
//   npm run flow-ui
//
// Environment: E2E_BASE (default http://127.0.0.1:8000), E2E_ADMIN_USER / E2E_ADMIN_PASSWORD, E2E_DEV_USER / E2E_DEV_PASSWORD, CHROME_PATH.
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
const ROOT = path.resolve(HERE, '..', '..')
const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8000'
const ADMIN = [process.env.E2E_ADMIN_USER || 'admin', process.env.E2E_ADMIN_PASSWORD || 'admin']
const DEV = [process.env.E2E_DEV_USER || 'developer', process.env.E2E_DEV_PASSWORD || 'developer']
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
if (!CHROME) { console.error('Chrome not found: set CHROME_PATH'); process.exit(2) }
const OUT = fs.mkdtempSync(path.join(os.tmpdir(), 'flow-ui-'))
const ADMIN_DEFAULTS = ['dashboard.view', 'orders.view', 'orders.manage', 'orders.export', 'customers.view', 'customers.export', 'settings.general', 'settings.footer', 'settings.orders_delivery', 'settings.store', 'settings.delivery', 'settings.payments', 'settings.notifications', 'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage', 'wholesale.view', 'wholesale.manage']

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
let failures = 0
const check = (ok, label, actual) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}${actual === undefined ? '' : `  -> ${typeof actual === 'string' ? actual : JSON.stringify(actual)}`}`); if (!ok) failures++ }
const db = (sql) => JSON.parse(execFileSync('php', [path.join(ROOT, 'scripts', 'flow-db.php'), sql], { cwd: ROOT, encoding: 'utf8' }))

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
  page.on('dialog', (d) => d.accept())
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
const goto = async (page, p) => { await page.goto(`${BASE}/admin/${p}`, { waitUntil: 'networkidle0' }); await sleep(400) }
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
  check(JSON.stringify(await nav(d.page)) === JSON.stringify(['Dashboard', 'Products', 'Categories', 'Discounts', 'Coupons', 'Settings', 'Access Control']), 'UI-A2 developer sidebar: no Orders, no Customers', await nav(d.page))
  check(!(await bodyHas(d.page, /Orders today|Revenue today|Pending orders|JHK-|ZED-/)), 'UI-A2 the developer dashboard shows no order figure, revenue or order number')
  await goto(d.page, 'orders')
  check(d.page.url().endsWith('/forbidden'), 'UI-A2 /admin/orders shows "no access" to the developer')

  // ============================================================ A3: switches change the admin's screens
  await login(a.page, ADMIN)
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Customers', 'Discounts', 'Coupons', 'Settings']), 'UI-A3 admin starts with Dashboard, Orders, Customers, Discounts, Coupons, Settings', await nav(a.page))
  await goto(a.page, 'products')
  check(a.page.url().endsWith('/forbidden'), 'UI-A3 admin cannot open Products by default')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-role="admin"] .card__head button')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Products', 'Categories', 'Customers', 'Discounts', 'Coupons', 'Settings']), 'UI-A3 after the developer switches them on: Products and Categories appear in the admin sidebar', await nav(a.page))
  await goto(a.page, 'categories')
  check(await a.page.evaluate(() => [...document.querySelectorAll('button')].some((b) => b.textContent.includes('Add category'))), 'UI-A3 the admin can use the Categories screen (Add category button)')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-role="admin"] .card__head button')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Customers', 'Discounts', 'Coupons', 'Settings']), 'UI-A3 switched off again: they disappear from the sidebar', await nav(a.page))
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
    await tapText(a.page, '.act-bar button', buttonText)
    await a.page.waitForSelector('#status-form', { timeout: 10000 })
    if (fill) await fill()
    await tap(a.page, 'button[form="status-form"]')
  }
  await step('Confirm order')
  await toast(a.page, /confirmed|updated|saved/)
  await step('Mark as packed')
  await toast(a.page, /packed|updated|saved/)
  await tapText(a.page, '.act-bar button', 'Ship order')
  await a.page.waitForSelector('#courier', { timeout: 10000 })
  await tap(a.page, 'button[form="status-form"]')   // nothing filled in
  await a.page.waitForSelector('#status-form .err, #status-form .has-error', { timeout: 10000 })
  check(await a.page.evaluate(() => /courier|tracking|AWB/i.test(document.querySelector('#status-form').innerText)), 'UI-D13 shipping without courier and tracking number shows the error in the form', await a.page.$eval('#status-form', (f) => f.innerText.replace(/\s+/g, ' ').slice(-140)))
  await setValue(a.page, '#courier', 'TCS')
  await setValue(a.page, '#awb', 'UI-AWB-001')
  await setValue(a.page, '#track', 'https://track.example.com/UI-AWB-001')
  await tap(a.page, 'button[form="status-form"]')
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
  check(inv.shown && /Invoice/.test(inv.text) && inv.text.includes(first.order_number) && inv.text.includes(itemName) && /Bill to/.test(inv.text) && /Total/.test(inv.text) && /Cash on delivery/.test(inv.text), 'UI-D14 invoice (print view): number, items, bill-to, totals, payment', inv.text.slice(0, 220))
  await a.page.pdf({ path: path.join(OUT, 'invoice.pdf'), format: 'A4' })
  await a.page.emulateMediaType('screen')
  await tapText(a.page, '.act-bar button', 'Packing slip')
  await sleep(300)
  await a.page.emulateMediaType('print')
  const slip = await sheet()
  check(slip.shown && /Packing slip/.test(slip.text) && slip.text.includes(first.order_number) && slip.text.includes(itemName) && !/Bill to/.test(slip.text) && !/Total\b.*\$/.test(slip.text.replace(/Qty.*/, '')), 'UI-D14 packing slip (print view): items and ship-to, no prices or bill-to', slip.text.slice(0, 220))
  await a.page.pdf({ path: path.join(OUT, 'packing-slip.pdf'), format: 'A4' })
  await a.page.emulateMediaType('screen')
  check((await a.page.evaluate(() => window.__printed)) === 2 && fs.statSync(path.join(OUT, 'invoice.pdf')).size > 1500 && fs.statSync(path.join(OUT, 'packing-slip.pdf')).size > 1500, 'UI-D14 both buttons call print; both print views render to a PDF', { dir: OUT })

  // cancel with a reason: the stock returns
  const line = db(`SELECT variant_id, qty FROM order_items WHERE order_id = ${second.id} LIMIT 1`)[0]
  const stockBefore = db(`SELECT stock_qty FROM product_variants WHERE id = ${line.variant_id}`)[0].stock_qty
  await goto(a.page, `orders/${second.id}`)
  await a.page.waitForSelector('.act-bar', { timeout: 15000 })
  await tapText(a.page, '.act-bar button', 'Cancel order')
  await a.page.waitForSelector('#reason', { timeout: 10000 })
  await tap(a.page, 'button[form="status-form"]')
  await a.page.waitForSelector('#reason.has-error, #status-form .err', { timeout: 10000 })
  check(true, 'UI-D14 cancelling without a reason is refused in the form')
  await setValue(a.page, '#reason', 'Customer changed their mind (UI)')
  await tap(a.page, 'button[form="status-form"]')
  await toast(a.page, /cancelled|updated|saved/)
  await sleep(500)
  const stockAfter = db(`SELECT stock_qty FROM product_variants WHERE id = ${line.variant_id}`)[0].stock_qty
  check(db(`SELECT status FROM orders WHERE id = ${second.id}`)[0].status === 'cancelled' && stockAfter - stockBefore === line.qty, 'UI-D14 cancel from the screen: status cancelled and the stock returned', { before: stockBefore, after: stockAfter, qty: line.qty })
  await goto(a.page, '')
  check(await bodyHas(a.page, /Orders today/) && await bodyHas(a.page, /Pending/i), 'UI-D12 the dashboard shows the order figures to the client admin')

  // ============================================================ D15: customers
  await goto(a.page, 'customers')
  await a.page.waitForSelector('tbody tr', { timeout: 15000 })
  const allRows = (await a.page.$$('tbody tr')).length
  const someCustomer = db("SELECT name, phone, email FROM customers WHERE email IS NOT NULL ORDER BY id LIMIT 1")[0]
  const typeSearch = async (text) => { await setValue(a.page, 'input[type="search"]', text); await sleep(1300) }
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
  await tap(d.page, 'button[form="settings-form"]')
  await d.page.waitForSelector('.err, .has-error', { timeout: 10000 })
  check(true, 'UI-E16 an invalid phone country code ("92") is refused with a message next to the field', await d.page.$eval('.err', (e) => e.innerText))
  await setValue(d.page, '#s-default_phone_country_code', '+92')
  await tap(d.page, 'button[form="settings-form"]')
  await toast(d.page, /settings saved/)
  const pubSettings = (await api('GET', 'store/settings')).json
  check(pubSettings.store.name === 'Panel Store', 'UI-E16 General tab saved in the panel shows in GET /store/settings', pubSettings.store.name)
  check(await d.page.evaluate(() => document.querySelector('.sidebar, header')?.innerText.includes('Panel Store')), 'UI-E16 the panel itself shows the new store name')

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
  await tap(d.page, 'button[form="settings-form"]')
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
  await tapText(a.page, '.modal__foot button', 'Cancel')
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
  check(!!(await a.page.$('#s-delivery_unmatched')) && !(await a.page.$('[data-zones-hint]')), 'UI-Z1 Zones method: "outside every zone" appears and the hint goes away')
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
  await tap(a.page, 'button[form="settings-form"]')
  await a.page.waitForSelector('.acct-row .err', { timeout: 10000 })
  check(/Enter the bank name/.test(await a.page.$eval('.acct-row:last-child', (r) => r.innerText)), 'UI-PAY an account without a bank name: the server message shows on that row', await a.page.$eval('.acct-row:last-child', (r) => r.innerText.replace(/\s+/g, ' ')))
  await setValue(a.page, '.acct-row:last-child .acct-bank', `UI Bank ${RUN}`)
  await setValue(a.page, '.acct-row:last-child .acct-title', 'UI Shop Ltd')
  await setValue(a.page, '.acct-row:last-child .acct-number', '0123456789')
  await setValue(a.page, '.acct-row:last-child .acct-iban', 'PK36UIBK0000123456789')
  if (!(await a.page.$eval('#s-pay_bank_enabled', (e) => e.checked))) await tap(a.page, '#s-pay_bank_enabled')
  await setValue(a.page, '#s-pay_bank_instructions', 'Use your order number as the payment reference.')
  await tap(a.page, 'button[form="settings-form"]')
  await toast(a.page, /settings saved/)
  const afterPay = (await api('GET', 'admin/settings', null, admToken)).json.values
  check(afterPay.pay_bank_enabled === true && afterPay.pay_bank_accounts.some((x) => x.bank === `UI Bank ${RUN}` && x.iban === 'PK36UIBK0000123456789' && x.active === true), 'UI-PAY the account and the bank-transfer switch are saved', afterPay.pay_bank_accounts.map((x) => x.bank))
  const pubPay = (await api('GET', 'store/settings')).json
  check(pubPay.payment?.bank_transfer?.enabled === true && pubPay.payment.bank_transfer.accounts.some((x) => x.bank === `UI Bank ${RUN}`), 'UI-PAY GET /store/settings now offers the bank transfer with the new account')
  // both methods off is refused by the server; its message shows next to the field
  if (await a.page.$eval('#s-pay_cod_enabled', (e) => e.checked)) await tap(a.page, '#s-pay_cod_enabled')
  await tap(a.page, '#s-pay_bank_enabled')
  await tap(a.page, 'button[form="settings-form"]')
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
  check(await a.page.$eval('[data-bank-panel]', (p, bank) => p.innerText.includes(bank) && p.innerText.includes('payment reference'), `UI Bank ${RUN}`), 'UI-B1 the accounts and instructions shown to the customer are listed')
  const plainProof = await fetch(`${BASE}/api/v1/admin/orders/${bank1.id}/payment/proof`, { headers: { Accept: 'application/json' } })
  check(plainProof.status === 401, 'UI-B1 the proof URL is not public (401 without the login)')
  check(await a.page.$eval('[data-blocked]', (e) => e.dataset.reason) === 'payment_not_verified' && (await confirmBtn(a.page)).disabled, 'UI-B1 before verification: a message and a disabled Confirm button', await confirmBtn(a.page))
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
  await a.page.waitForSelector('#status-form', { timeout: 10000 })
  await tap(a.page, 'button[form="status-form"]')
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
  await a.page.waitForSelector('#reason', { timeout: 5000 })
  await tap(a.page, 'button[form="status-form"]')
  await a.page.waitForSelector('#reason.has-error, #status-form .err', { timeout: 10000 })
  check(/reason/i.test(await a.page.$eval('#status-form', (f) => f.innerText)), 'UI-B2 rejecting an order without a reason is refused in the form', await a.page.$eval('#status-form .err', (e) => e.innerText))
  await setValue(a.page, '#reason', 'We cannot deliver this order (UI test).')
  await tap(a.page, 'button[form="status-form"]')
  await toast(a.page, /rejected/)
  const r2b = await adminOrder(bank2.id)
  check(r2b.status === 'rejected' && r2b.reject_reason === 'We cannot deliver this order (UI test).' && r2b.actions.can_reject === false, 'UI-B2 Reject order: status rejected with the reason; no more actions', { status: r2b.status })
  check(await a.page.$eval('[data-reject-reason]', (e) => /We cannot deliver/.test(e.innerText)) && !(await a.page.$('.act-bar button.btn-primary')), 'UI-B2 the screen shows the reject reason and offers no further button')
  check(await a.page.$eval('.timeline', (t) => /Rejected/.test(t.innerText)), 'UI-B2 the timeline has a "Rejected" step')
  const trackRejected = (await api('GET', `store/orders/${bank2.number}?phone=${encodeURIComponent(bank2.phone)}`)).json?.data
  check(trackRejected?.status === 'rejected' && trackRejected?.reject_reason === 'We cannot deliver this order (UI test).', 'UI-B2 the customer tracking shows the rejection and its reason (public API)')

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
  await a.page.waitForSelector('#status-form', { timeout: 10000 })
  await tap(a.page, 'button[form="status-form"]')
  await toast(a.page, /confirmed/)
  check((await adminOrder(feeOrder.id)).status === 'confirmed', 'UI-D1 the order is confirmed after the fee was set')
  // a discount and a coupon are part of the totals box
  const couponTotals = await adminOrder(couponOrder.id)
  await goto(a.page, `orders/${couponOrder.id}`)
  await a.page.waitForSelector('[data-coupon-row]', { timeout: 15000 })
  check(await a.page.$eval('[data-coupon-row]', (e, c) => e.innerText.includes(c), CODE) && couponTotals.totals.coupon_discount > 0, 'UI-D1 the totals show the coupon code and its discount', couponTotals.totals.coupon_discount)

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

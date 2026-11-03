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
const ADMIN_DEFAULTS = ['dashboard.view', 'orders.view', 'orders.manage', 'customers.view', 'customers.export', 'settings.general', 'settings.footer', 'settings.orders_delivery']

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

const a = await open()   // client admin
const d = await open()   // developer
try {
  // ============================================================ A2: the developer sees no orders
  await login(d.page, DEV)
  check(JSON.stringify(await nav(d.page)) === JSON.stringify(['Dashboard', 'Products', 'Categories', 'Settings', 'Access Control']), 'UI-A2 developer sidebar: no Orders, no Customers', await nav(d.page))
  check(!(await bodyHas(d.page, /Orders today|Revenue today|Pending orders|JHK-|ZED-/)), 'UI-A2 the developer dashboard shows no order figure, revenue or order number')
  await goto(d.page, 'orders')
  check(d.page.url().endsWith('/forbidden'), 'UI-A2 /admin/orders shows "no access" to the developer')

  // ============================================================ A3: switches change the admin's screens
  await login(a.page, ADMIN)
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Customers', 'Settings']), 'UI-A3 admin starts with Dashboard, Orders, Customers, Settings', await nav(a.page))
  await goto(a.page, 'products')
  check(a.page.url().endsWith('/forbidden'), 'UI-A3 admin cannot open Products by default')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-role="admin"] .card__head button')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Products', 'Categories', 'Customers', 'Settings']), 'UI-A3 after the developer switches them on: Products and Categories appear in the admin sidebar', await nav(a.page))
  await goto(a.page, 'categories')
  check(await a.page.evaluate(() => [...document.querySelectorAll('button')].some((b) => b.textContent.includes('Add category'))), 'UI-A3 the admin can use the Categories screen (Add category button)')
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  for (const key of ['products.view', 'products.manage', 'categories.view', 'categories.manage']) await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  await tap(d.page, '[data-role="admin"] .card__head button')
  await toast(d.page, /saved|updated/)
  await a.page.reload({ waitUntil: 'networkidle0' })
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Customers', 'Settings']), 'UI-A3 switched off again: they disappear from the sidebar', await nav(a.page))
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
  let pending = (await api('GET', 'admin/orders?status=pending&per_page=50', null, admToken)).json?.data || []
  for (let i = pending.length; i < 3 && sampleVariant; i++) {
    const r = await fetch(`${BASE}/api/v1/store/orders`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'Idempotency-Key': `flowui-${Date.now()}-${i}-xxxx` }, body: JSON.stringify({ customer: { name: `UI Buyer ${i}`, phone: `+92 355 90000${i}0` }, address: { line1: '1 Test Road', city: 'Lahore' }, items: [{ variant_id: sampleVariant, qty: 1 }] }) })
    if (r.status !== 201) console.log(`  (could not place a helper order: ${r.status})`)
  }
  pending = (await api('GET', 'admin/orders?status=pending&per_page=50', null, admToken)).json?.data || []
  check(pending.length >= 2, 'UI-D12 there are pending orders to work on', pending.length)
  const [first, second] = pending

  await goto(a.page, 'orders')
  check(await bodyHas(a.page, new RegExp(first.order_number)), 'UI-D12 the Orders screen lists the order', first.order_number)
  await setValue(a.page, 'input[type="search"]', first.order_number)
  await sleep(1200)
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

  check(a.problems.length === 0 && d.problems.length === 0, `no script errors in either browser (${[...a.problems, ...d.problems].join('; ') || 'none'})`)
} catch (e) {
  failures++
  console.log(`FAIL  script error: ${e.stack || e}`)
} finally {
  await api('PUT', 'admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, devToken)
  await a.browser.close()
  await d.browser.close()
}
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed')
console.log(`(PDF print views: ${OUT})`)
process.exit(failures ? 1 : 0)

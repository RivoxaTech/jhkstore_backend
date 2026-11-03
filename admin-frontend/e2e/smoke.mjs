// Browser smoke test of the admin panel (headless Chrome through puppeteer-core).
//
//   1. start the API on a freshly seeded database:  php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve
//   2. build the panel:                             npm run build
//   3. run:                                         npm run e2e
//
// Environment: E2E_BASE (default http://127.0.0.1:8000), E2E_ADMIN_USER / E2E_ADMIN_PASSWORD (default admin / admin), E2E_DEV_USER / E2E_DEV_PASSWORD
// (default developer / developer), CHROME_PATH (default: the usual Windows / Linux / macOS Chrome).
//
// It checks the two accounts: the client admin starts with the default access (dashboard, orders, customers, three Settings tabs); the developer has no
// orders and no order figures anywhere; the developer's Access Control switches change what the admin sees (sidebar, Settings tabs, buttons) and the
// API refuses the rest. Every screen opens without script errors. Exit code 1 when a check fails. It restores the admin's default access at the end.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8000'
const ADMIN = [process.env.E2E_ADMIN_USER || 'admin', process.env.E2E_ADMIN_PASSWORD || 'admin']
const DEV = [process.env.E2E_DEV_USER || 'developer', process.env.E2E_DEV_PASSWORD || 'developer']
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
if (!CHROME) { console.error('Chrome not found: set CHROME_PATH'); process.exit(2) }

const ADMIN_DEFAULTS = ['dashboard.view', 'orders.view', 'orders.manage', 'customers.view', 'customers.export', 'settings.general', 'settings.footer', 'settings.orders_delivery', 'settings.store', 'settings.delivery', 'settings.payments', 'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage']

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
let failures = 0
const check = (ok, label) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++ }

async function open() {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width: 1366, height: 900 } })
  const page = await browser.newPage()
  const problems = []
  page.on('pageerror', (e) => problems.push(`page error: ${e.message}`))
  page.on('console', (m) => { if (m.type() === 'error' && !/401|403|422/.test(m.text())) problems.push(`console: ${m.text()}`) })   // 401 before login, 403 on a refused call and 422 validation answers are expected
  return { browser, page, problems }
}

async function login(page, [user, pass]) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await page.type('#username', user)
  await page.type('#password', pass)
  await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
}

const send = async (method, path, body, token) => {
  const r = await fetch(`${BASE}/api/v1/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, json: await r.json().catch(() => null) }
}
const tokenOf = async ([login, password]) => (await send('POST', 'admin/auth/login', { login, password })).json?.token
const nav = (page) => page.$$eval('.sidebar__nav a', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').replace(/\d+$/, '').trim()))
const tabsOf = (page) => page.$$eval('[role="tab"]', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').replace(/[•!]$/, '').trim()))
const goto = async (page, path) => { await page.goto(`${BASE}/admin/${path}`, { waitUntil: 'networkidle0' }); await sleep(400) }
// a click dispatched inside the page: independent of layout shifts while the screen is still loading
const tap = (page, selector) => page.$eval(selector, (el) => el.click())

const devToken = await tokenOf(DEV)
if (!devToken) { console.error('Cannot log in as the developer: check E2E_DEV_USER / E2E_DEV_PASSWORD'); process.exit(2) }
const setAdminAccess = (permissions) => send('PUT', 'admin/access-control/admin', { permissions }, devToken)
await setAdminAccess(ADMIN_DEFAULTS)   // a known starting point

const a = await open()   // the client admin
const d = await open()   // the developer
try {
  // ---- login: a wrong password shows the server's message and stays on the page
  await a.page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await a.page.type('#username', ADMIN[0])
  await a.page.type('#password', 'definitely-wrong')
  await a.page.click('button[type="submit"]')
  await a.page.waitForSelector('.alert--error', { timeout: 30000 })
  check(/incorrect/i.test(await a.page.$eval('.alert--error', (e) => e.innerText)), 'wrong password shows an error message')

  // ================= the client admin, default access
  await login(a.page, ADMIN)
  check(a.page.url().endsWith('/admin/'), 'admin login lands on the dashboard')
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Dashboard', 'Orders', 'Customers', 'Discounts', 'Coupons', 'Settings']), `admin sidebar: Dashboard, Orders, Customers, Discounts, Coupons, Settings (${(await nav(a.page)).join(', ')})`)
  for (const [path, heading] of [['', 'Dashboard'], ['orders', 'Orders'], ['customers', 'Customers'], ['discounts', 'Discounts'], ['coupons', 'Coupons'], ['settings', 'Settings']]) {
    await goto(a.page, path)
    await a.page.waitForSelector('h1', { timeout: 15000 })
    check((await a.page.$eval('h1', (e) => e.innerText)).includes(heading), `admin opens /admin/${path} ("${heading}")`)
  }
  await goto(a.page, '')
  check(await a.page.evaluate(() => document.body.innerText.includes('Orders today')), 'the admin dashboard shows the order figures')

  await goto(a.page, 'settings')
  check(JSON.stringify(await tabsOf(a.page)) === JSON.stringify(['General', 'Footer & Social', 'Orders & Delivery', 'Delivery', 'Payments', 'Store']), `admin sees General, Footer, Orders & Delivery, Delivery, Payments and Store, and no Theme (${(await tabsOf(a.page)).join(', ')})`)
  // the new tabs render their fields from the API (colour / choice / accounts are the new field types)
  await goto(a.page, 'settings?tab=delivery')
  await a.page.waitForSelector('#s-delivery_method', { timeout: 15000 })
  check(await a.page.$eval('#s-delivery_method', (e) => e.tagName === 'SELECT' && e.options.length === 5), 'Delivery tab: the delivery method is a select of five options')
  check(!!(await a.page.$('[data-section="zones"]')), 'Delivery tab: the delivery zone manager is there')
  await goto(a.page, 'settings?tab=payments')
  await a.page.waitForSelector('[data-add-account]', { timeout: 15000 })
  check(!!(await a.page.$('#s-pay_cod_enabled')) && !!(await a.page.$('#s-pay_bank_enabled')) && !!(await a.page.$('#s-pay_card_visible')) && !!(await a.page.$('#s-pay_bank_instructions')), 'Payments tab: COD, bank transfer and card switches, instructions and the accounts editor')
  await goto(a.page, 'settings?tab=store')
  await a.page.waitForSelector('#s-store_open', { timeout: 15000 })
  check(!!(await a.page.$('#s-store_closed_message')) && !!(await a.page.$('#s-checkout_notice')) && !!(await a.page.$('#s-delivery_eta_text')), 'Store tab: open switch, closed message, checkout notice and ETA text')
  await goto(a.page, 'settings?tab=footer')
  await a.page.waitForSelector('#s-agency_name', { timeout: 15000 })
  check(!!(await a.page.$('#s-agency_url')), 'Footer tab: agency name and link')
  check(!(await a.page.$('#tab-theme')) && (await send('GET', 'admin/settings', null, await tokenOf(ADMIN))).json.tabs.every((t) => t.key !== 'theme'), 'the admin has no Theme tab, in the panel and in the API')
  for (const path of ['products', 'categories', 'access-control']) {
    await goto(a.page, path)
    check(a.page.url().endsWith('/forbidden'), `admin cannot open /admin/${path}`)
  }
  check((await send('GET', 'admin/access-control', null, await tokenOf(ADMIN))).status === 403, 'the API refuses Access Control to the admin (403)')

  // ================= the developer
  await login(d.page, DEV)
  const devNav = await nav(d.page)
  check(JSON.stringify(devNav) === JSON.stringify(['Dashboard', 'Products', 'Categories', 'Discounts', 'Coupons', 'Settings', 'Access Control']), `developer sidebar has no Orders or Customers (${devNav.join(', ')})`)
  check(!(await d.page.evaluate(() => /Orders today|Revenue today|Pending orders/.test(document.body.innerText))), 'the developer dashboard shows no order or revenue figures')
  await goto(d.page, 'orders')
  check(d.page.url().endsWith('/forbidden'), 'the developer cannot open /admin/orders')
  await goto(d.page, 'customers')
  check(d.page.url().endsWith('/forbidden'), 'the developer cannot open /admin/customers by default')
  check((await send('GET', 'admin/orders', null, devToken)).status === 403, 'the API refuses orders to the developer (403)')

  for (const [path, heading] of [['products', 'Products'], ['products/new', 'Add product'], ['categories', 'Categories'], ['settings', 'Settings'], ['access-control', 'Access Control']]) {
    await goto(d.page, path)
    await d.page.waitForSelector('h1', { timeout: 15000 })
    check((await d.page.$eval('h1', (e) => e.innerText)).includes(heading), `developer opens /admin/${path} ("${heading}")`)
  }
  await goto(d.page, 'nothing-here')
  check(/not found/i.test(await d.page.title()), 'an unknown address shows "not found"')

  // a product row opens its edit page (route pattern regression)
  await goto(d.page, 'products')
  if (await d.page.$('tbody tr')) {
    await tap(d.page, 'tbody tr:nth-child(1)')
    await d.page.waitForSelector('#basic', { timeout: 20000 }).catch(() => {})
    check(/\/admin\/products\/\d+\/edit$/.test(d.page.url()) && (await d.page.title()).startsWith('Edit product'), 'a product row opens the edit form')
  } else console.log('SKIP  no products (load the DemoSeeder to check the edit form)')

  // ---- settings: seven tabs, Banners inside, links editor
  await goto(d.page, 'settings')
  const tabs = await tabsOf(d.page)
  check(JSON.stringify(tabs) === JSON.stringify(['General', 'Branding', 'Banners', 'Footer & Social', 'Currency & Tax', 'Orders & Delivery', 'Delivery', 'Payments', 'Store', 'Theme', 'SEO']), `the developer sees all eleven tabs, Theme included (${tabs.join(', ')})`)

  // ---- Theme tab (developer only): colour inputs stay in sync and the live preview follows the form; the admin panel's own look does not change
  await tap(d.page, '#tab-theme')
  await d.page.waitForSelector('[data-theme-preview]', { timeout: 10000 })
  const btnColour = () => d.page.$eval('[data-theme-preview] .tp-btn', (e) => getComputedStyle(e).backgroundColor)
  const panelPrimary = () => d.page.evaluate(() => getComputedStyle(document.querySelector('.btn-primary')).backgroundColor)
  const themeBefore = { preview: await btnColour(), panel: await panelPrimary() }
  check(!!(await d.page.$('#s-theme_primary')) && !!(await d.page.$('input[type="color"][aria-label^="Primary colour"]')) && (await d.page.$eval('#s-theme_font_heading', (e) => e.tagName)) === 'SELECT' && (await d.page.$eval('#s-theme_radius', (e) => e.tagName)) === 'SELECT', 'Theme tab: colour inputs (picker + hex box), font / radius / shadow selects')
  await d.page.$eval('#s-theme_primary', (el) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, '#FF0000'); el.dispatchEvent(new Event('input', { bubbles: true })) })
  await sleep(200)
  check((await btnColour()) === 'rgb(255, 0, 0)' && themeBefore.preview !== 'rgb(255, 0, 0)', 'Theme tab: the live preview button follows the primary colour', { before: themeBefore.preview, after: await btnColour() })
  check((await d.page.$eval('input[type="color"][aria-label^="Primary colour"]', (e) => e.value)) === '#ff0000', 'Theme tab: the colour picker follows the hex box')
  await d.page.select('#s-theme_radius', '999px')
  await sleep(150)
  check((await d.page.$eval('[data-theme-preview] .tp-btn', (e) => getComputedStyle(e).borderTopLeftRadius)) !== '8px', 'Theme tab: the preview follows the corner radius')
  check((await panelPrimary()) === themeBefore.panel, 'the admin panel keeps its own colours while the theme is edited')
  await d.page.$eval('#s-theme_primary', (el) => { Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, 'red'); el.dispatchEvent(new Event('input', { bubbles: true })) })
  await tap(d.page, 'button[form="settings-form"]')
  await d.page.waitForSelector('#panel-theme .err', { timeout: 10000 })
  check(/#RRGGBB/.test(await d.page.$eval('#panel-theme .err', (e) => e.innerText)), 'Theme tab: an invalid colour shows the server message next to the field', await d.page.$eval('#panel-theme .err', (e) => e.innerText))
  await goto(d.page, 'settings')   // nothing was saved: the form is dropped
  await tap(d.page, '#tab-banners')
  await d.page.waitForSelector('#panel-banners', { timeout: 10000 })
  check(await d.page.evaluate(() => [...document.querySelectorAll('#panel-banners button')].some((b) => b.textContent.includes('Add banner'))), 'the Banners tab holds the banner manager')
  await tap(d.page, '#tab-footer')
  await sleep(300)
  await d.page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Add link')).click())
  await d.page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Add link')).click())
  check((await d.page.$$('.link-row')).length >= 2, 'links can be added')
  const urls = await d.page.$$('.link-row .link-url input')
  await urls[urls.length - 2].type('https://example.com/one')
  await urls[urls.length - 1].type('https://example.com/two')
  const before = await d.page.$$eval('.link-row .link-url input', (els) => els.map((e) => e.value))
  await tap(d.page, `.link-row:nth-child(${before.length}) [aria-label="Move link ${before.length} up"]`)
  const after = await d.page.$$eval('.link-row .link-url input', (els) => els.map((e) => e.value))
  check(after[after.length - 2] === before[before.length - 1], 'a link moves up')
  await tap(d.page, `.link-row:nth-child(${after.length}) [aria-label="Remove link ${after.length}"]`)
  check((await d.page.$$('.link-row')).length === after.length - 1, 'a link is removed')
  check(/\u2022|!/.test(await d.page.$eval('#tab-footer', (e) => e.innerText)), 'the tab shows unsaved changes')

  // ================= Access Control: a switch changes what the admin sees
  await goto(d.page, 'access-control')
  await d.page.waitForSelector('[data-role="admin"] input[data-permission="products.view"]', { timeout: 15000 })
  check(!(await d.page.$('[data-role="admin"] input[data-permission="orders.view"]:not(:checked)')), 'the admin starts with orders switched on')
  check(!(await d.page.$('[data-role="developer"] input[data-permission="orders.view"]')), 'there is no orders switch for the developer')
  const newKeys = ['discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage', 'settings.store', 'settings.delivery', 'settings.payments']
  const adminRows = await d.page.$$eval('[data-role="admin"] input[data-permission]', (els) => els.map((e) => [e.dataset.permission, e.checked]))
  check(newKeys.every((k) => adminRows.some(([key, on]) => key === k && on)), 'Access Control: Discounts, Coupons and the Store / Delivery / Payments tabs are listed and on for the admin')
  check(!adminRows.some(([key]) => key === 'settings.theme' || key === 'access.manage'), 'Access Control: settings.theme and access.manage are not offered for the admin')
  const groups = await d.page.$$eval('[data-role="admin"] .access-group__head strong', (els) => els.map((e) => e.innerText))
  check(groups.includes('Discounts') && groups.includes('Coupons'), 'Access Control: Discounts and Coupons are groups', groups)
  for (const key of ['products.view', 'settings.banners']) {
    await d.page.$eval(`[data-role="admin"] input[data-permission="${key}"]`, (el) => el.click())
  }
  await d.page.$eval('[data-role="admin"] .card__head button', (el) => el.click())
  await d.page.waitForSelector('.toast, [role="status"]', { timeout: 10000 }).catch(() => {})
  await sleep(500)
  const mine = (await send('GET', 'admin/access-control', null, devToken)).json.roles.find((r) => r.role === 'admin')
  const on = mine.groups.flatMap((g) => g.permissions).filter((p) => p.enabled).map((p) => p.key)
  check(on.includes('products.view') && on.includes('settings.banners') && on.includes('orders.view'), 'saving the switches reaches the server')

  await a.page.reload({ waitUntil: 'networkidle0' })
  check((await nav(a.page)).includes('Products'), 'the admin now sees Products in the sidebar')
  await goto(a.page, 'settings')
  check((await tabsOf(a.page)).includes('Banners'), 'the admin now sees the Banners tab')
  await goto(a.page, 'products')
  check(!(await a.page.$('.page-actions a[href$="/products/new"]')), 'the admin has no "Add product" button (products.manage is off)')
  check((await send('POST', 'admin/tags', { name: 'e2e' }, await tokenOf(ADMIN))).status === 403, 'the API refuses what stays switched off (403)')

  // the developer cannot give themselves orders
  check((await send('PUT', 'admin/access-control/developer', { permissions: ['orders.view'] }, devToken)).status === 422, 'the developer cannot grant themselves orders (422)')

  // any single Settings tab opens the Settings page (auth.can('settings.*')): an admin that holds only settings.payments sees just that tab
  await setAdminAccess(['settings.payments'])
  await goto(a.page, 'settings')
  check(JSON.stringify(await nav(a.page)) === JSON.stringify(['Settings']) && JSON.stringify(await tabsOf(a.page)) === JSON.stringify(['Payments']), `only settings.payments: the sidebar has Settings and the page has the Payments tab (${(await nav(a.page)).join(', ')} / ${(await tabsOf(a.page)).join(', ')})`)
  await goto(a.page, 'discounts')
  check(a.page.url().endsWith('/forbidden'), 'without discounts.view /admin/discounts shows "no access"')
  await setAdminAccess(['discounts.view', 'coupons.view'])
  await goto(a.page, 'discounts')
  await a.page.waitForSelector('h1', { timeout: 15000 })
  check(!(await a.page.$('[data-add]')) && !(await a.page.$('button[aria-label^="Edit "], button[aria-label^="Delete "]')), 'discounts.view without discounts.manage: no Add / Edit / Delete buttons')
  await setAdminAccess(ADMIN_DEFAULTS)

  check(a.problems.length === 0 && d.problems.length === 0, `no script errors (${[...a.problems, ...d.problems].join('; ') || 'none'})`)
} finally {
  await setAdminAccess(ADMIN_DEFAULTS)   // leave the database as it was
  await a.browser.close()
  await d.browser.close()
}
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed')
process.exit(failures ? 1 : 0)

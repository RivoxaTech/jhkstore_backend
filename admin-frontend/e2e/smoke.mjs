// Browser smoke test of the admin panel (headless Chrome through puppeteer-core).
//
//   1. start the API on a freshly seeded database:  php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve
//   2. build the panel:                             npm run build
//   3. run:                                         npm run e2e
//
// Environment: E2E_BASE (default http://127.0.0.1:8000), E2E_USER / E2E_PASSWORD (default admin / admin), CHROME_PATH (default: the usual Windows / Linux / macOS Chrome).
// It logs in, opens every screen, checks that nothing throws, that product edit and order routes resolve, that the settings tabs and links editor work,
// and that the sidebar of a staff user is limited. Exit code 1 when a check fails.
import puppeteer from 'puppeteer-core'
import fs from 'node:fs'

const BASE = process.env.E2E_BASE || 'http://127.0.0.1:8000'
const USER = process.env.E2E_USER || 'admin'
const PASS = process.env.E2E_PASSWORD || 'admin'
const CHROME = process.env.CHROME_PATH || ['C:/Program Files/Google/Chrome/Application/chrome.exe', '/usr/bin/google-chrome', '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((p) => fs.existsSync(p))
if (!CHROME) { console.error('Chrome not found: set CHROME_PATH'); process.exit(2) }

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
let failures = 0
const check = (ok, label) => { console.log(`${ok ? 'PASS' : 'FAIL'}  ${label}`); if (!ok) failures++ }

async function open() {
  const browser = await puppeteer.launch({ executablePath: CHROME, headless: 'new', args: ['--no-sandbox'], defaultViewport: { width: 1366, height: 900 } })
  const page = await browser.newPage()
  const problems = []
  page.on('pageerror', (e) => problems.push(`page error: ${e.message}`))
  page.on('console', (m) => { if (m.type() === 'error' && !/401|422/.test(m.text())) problems.push(`console: ${m.text()}`) })   // 401 before login and 422 validation answers are expected
  return { browser, page, problems }
}

async function login(page, user, pass) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await page.type('#username', user)
  await page.type('#password', pass)
  await Promise.all([page.waitForSelector('.sidebar__nav', { timeout: 30000 }), page.click('button[type="submit"]')])
}

const send = async (method, path, body, token) => {
  const r = await fetch(`${BASE}/api/v1/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined })
  return { status: r.status, json: await r.json().catch(() => null) }
}

const a = await open()
try {
  // ---- login: a wrong password shows the server's message and stays on the page
  await a.page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle0' })
  await a.page.type('#username', USER)
  await a.page.type('#password', 'definitely-wrong')
  await a.page.click('button[type="submit"]')
  await a.page.waitForSelector('.alert--error', { timeout: 30000 })
  check(/incorrect/i.test(await a.page.$eval('.alert--error', (e) => e.innerText)), 'wrong password shows an error message')

  await login(a.page, USER, PASS)
  check(a.page.url().endsWith('/admin/'), 'login lands on the dashboard')

  // ---- every screen opens, with a title and no script errors
  for (const [path, heading] of [['', 'Dashboard'], ['categories', 'Categories'], ['products', 'Products'], ['products/new', 'Add product'], ['orders', 'Orders'], ['banners', 'Banners'], ['settings', 'Settings'], ['users', 'Users & roles']]) {
    await a.page.goto(`${BASE}/admin/${path}`, { waitUntil: 'networkidle0' })
    await a.page.waitForSelector('h1', { timeout: 15000 })
    check((await a.page.$eval('h1', (e) => e.innerText)).includes(heading), `/admin/${path} shows "${heading}"`)
  }
  await a.page.goto(`${BASE}/admin/nothing-here`, { waitUntil: 'networkidle0' })
  check(/not found/i.test(await a.page.title()), 'an unknown address shows "not found"')

  // ---- a product row opens its edit page (route pattern regression)
  await a.page.goto(`${BASE}/admin/products`, { waitUntil: 'networkidle0' })
  if (await a.page.$('tbody tr')) {
    await a.page.click('tbody tr:nth-child(1)')
    await a.page.waitForSelector('#basic', { timeout: 20000 }).catch(() => {})
    check(/\/admin\/products\/\d+\/edit$/.test(a.page.url()) && (await a.page.title()).startsWith('Edit product'), 'a product row opens the edit form')
  } else console.log('SKIP  no products (load the DemoSeeder to check the edit form)')

  // ---- settings: tabs and the links editor
  await a.page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle0' })
  const tabs = await a.page.$$eval('[role="tab"]', (els) => els.map((e) => e.innerText.replace(/\s+/g, ' ').trim()))
  check(['General', 'Branding', 'Footer & Social', 'Currency & Tax', 'Orders & Delivery', 'Features', 'SEO'].every((t, i) => tabs[i]?.startsWith(t)), 'settings has the seven tabs')
  await a.page.click('#tab-footer')
  await sleep(300)
  await a.page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Add link')).click())
  await a.page.evaluate(() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('Add link')).click())
  check((await a.page.$$('.link-row')).length >= 2, 'links can be added')
  const urls = await a.page.$$('.link-row .link-url input')
  await urls[urls.length - 2].type('https://example.com/one')
  await urls[urls.length - 1].type('https://example.com/two')
  const before = await a.page.$$eval('.link-row .link-url input', (els) => els.map((e) => e.value))
  await a.page.click(`.link-row:nth-child(${before.length}) [aria-label="Move link ${before.length} up"]`)
  const after = await a.page.$$eval('.link-row .link-url input', (els) => els.map((e) => e.value))
  check(after[after.length - 2] === before[before.length - 1], 'a link moves up')
  await a.page.click(`.link-row:nth-child(${after.length}) [aria-label="Remove link ${after.length}"]`)
  check((await a.page.$$('.link-row')).length === after.length - 1, 'a link is removed')
  check(/\u2022|!/.test(await a.page.$eval('#tab-footer', (e) => e.innerText)), 'the tab shows unsaved changes')

  check(a.problems.length === 0, `no script errors (${a.problems.join('; ') || 'none'})`)

  // ---- a staff user sees a limited panel
  const token = (await send('POST', 'admin/auth/login', { login: USER, password: PASS })).json?.token
  const name = `e2e${Date.now() % 1e6}`
  const made = await send('POST', 'admin/users', { username: name, name: 'E2E Staff', role: 'staff', password: 'E2e-staff-pass1' }, token)
  check(made.status === 201, 'a staff user can be created')
  if (made.status === 201) {
    const b = await open()
    try {
      await login(b.page, name, 'E2e-staff-pass1')
      const nav = await b.page.$$eval('.sidebar__nav a', (els) => els.map((e) => e.innerText.trim()))
      check(!nav.includes('Settings') && !nav.includes('Users & roles') && nav.includes('Orders'), `staff sidebar is limited (${nav.join(', ')})`)
      await b.page.goto(`${BASE}/admin/settings`, { waitUntil: 'networkidle0' })
      check(b.page.url().endsWith('/forbidden'), 'staff cannot open settings')
      await b.page.goto(`${BASE}/admin/products`, { waitUntil: 'networkidle0' })
      check(!(await b.page.$('.page-actions a[href$="/products/new"]')), 'staff has no "Add product" button')
    } finally { await b.browser.close() }
    const me = (await send('GET', 'admin/users', null, token)).json.data.find((u) => u.username === name)
    if (me) await send('DELETE', `admin/users/${me.id}`, null, token)
  }
} finally {
  await a.browser.close()
}
console.log(failures ? `\n${failures} check(s) failed` : '\nAll checks passed')
process.exit(failures ? 1 : 0)

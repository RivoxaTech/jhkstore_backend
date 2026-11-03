// End-to-end flow check of the REAL app over real HTTP (no test database, no mocks).
//
//   php artisan migrate:fresh --seed        (base seeder only; or pass --fresh to let this script do it)
//   php artisan serve                       (port 8000; start both servers with CHECKOUT_PER_HOUR=1000: the run places far more than 60 orders)
//   php artisan serve --port=8001           (a second server process: the "last unit" race needs two real PHP processes, because the
//                                            PHP development server handles one request at a time)
//   node scripts/flow-check.mjs [--fresh]
//
// Environment: FLOW_BASE (default http://127.0.0.1:8000), FLOW_BASE2 (default http://127.0.0.1:8001),
// FLOW_DEV_USER / FLOW_DEV_PASSWORD (developer / developer), FLOW_ADMIN_USER / FLOW_ADMIN_PASSWORD (admin / admin).
//
// It covers checks A1 to E16 (see docs/QA_REPORT.md), prints PASS / FAIL with the actual answer for every assertion and exits with 1 when
// something fails. Database reads (stock, inventory movements, audit log, payments) go through scripts/flow-db.php (SELECT only).
// The browser side of the same checks (sidebar, buttons, print pages) is admin-frontend/e2e/flow-ui.mjs.
// It leaves test data behind: reseed afterwards with `php artisan migrate:fresh --seed`.
import { execFileSync } from 'node:child_process'
import zlib from 'node:zlib'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const BASE = process.env.FLOW_BASE || 'http://127.0.0.1:8000'
const BASE2 = process.env.FLOW_BASE2 || 'http://127.0.0.1:8001'
const DEV = [process.env.FLOW_DEV_USER || 'developer', process.env.FLOW_DEV_PASSWORD || 'developer']
const ADM = [process.env.FLOW_ADMIN_USER || 'admin', process.env.FLOW_ADMIN_PASSWORD || 'admin']
const ADMIN_DEFAULTS = ['dashboard.view', 'orders.view', 'orders.manage', 'customers.view', 'customers.export', 'settings.general', 'settings.footer', 'settings.orders_delivery',
  'settings.store', 'settings.delivery', 'settings.payments', 'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage']

if (process.argv.includes('--fresh')) {
  execFileSync('php', ['artisan', 'migrate:fresh', '--seed', '--force'], { cwd: ROOT, stdio: 'ignore' })
  execFileSync('php', ['artisan', 'cache:clear'], { cwd: ROOT, stdio: 'ignore' })
}

// ------------------------------------------------------------------ small helpers
const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const short = (v, n = 220) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s && s.length > n ? `${s.slice(0, n)}…` : s }

async function call(method, route, { token, body, form, headers = {}, base = `${BASE}/api/v1` } = {}) {
  const h = { Accept: 'application/json', ...headers }
  let payload
  if (form) payload = form
  else if (body !== undefined) { h['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }
  if (token) h.Authorization = `Bearer ${token}`
  const r = await fetch(`${base}/${route}`, { method, headers: h, body: payload })
  const text = await r.text()
  let json = null
  try { json = JSON.parse(text) } catch { /* not JSON (CSV, HTML) */ }
  return { status: r.status, json, text, headers: r.headers }
}
const get = (route, token, extra) => call('GET', route, { token, ...extra })
const post = (route, body, token, extra) => call('POST', route, { token, body, ...extra })
const put = (route, body, token, extra) => call('PUT', route, { token, body, ...extra })
const patch = (route, body, token, extra) => call('PATCH', route, { token, body, ...extra })
const del = (route, token) => call('DELETE', route, { token })
const idOf = (r) => r.json?.data?.id ?? r.json?.id

function db(sql) {
  return JSON.parse(execFileSync('php', [path.join(ROOT, 'scripts', 'flow-db.php'), sql], { cwd: ROOT, encoding: 'utf8' }))
}
const dbOne = (sql) => db(sql)[0]

// A tiny PNG writer (no dependencies): a flat colour picture of the given size.
const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0 }
function chunk(type, data) {
  const len = Buffer.alloc(4); len.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type), data])
  const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body))
  return Buffer.concat([len, body, crc])
}
function png(w, h, [r, g, b]) {
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => [r, g, b]).flat())])
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.concat(Array.from({ length: h }, () => row)))), chunk('IEND', Buffer.alloc(0))])
}
const file = (name, w, h, colour) => new File([png(w, h, colour)], name, { type: 'image/png' })
function formOf(fields, files = {}) {
  const f = new FormData()
  for (const [k, v] of Object.entries(fields)) if (v !== undefined && v !== null) f.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
  for (const [k, v] of Object.entries(files)) for (const x of [].concat(v)) f.append(k, x)
  return f
}

// ------------------------------------------------------------------ result book
const book = new Map()
let current = null
let failures = 0
function section(id, title) {
  current = book.get(id) || { id, title, items: [] }
  book.set(id, current)
  console.log(`\n[${id}] ${title}`)
}
function check(cond, label, actual) {
  const ok = !!cond
  current.items.push({ ok, label, actual: actual === undefined ? '' : short(actual) })
  if (!ok) failures++
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${actual === undefined ? '' : `  -> ${short(actual, 300)}`}`)
}
const status = (r) => `HTTP ${r.status} ${short(r.json ?? r.text, 160)}`

// ------------------------------------------------------------------ order helpers (rate-limit aware)
const orderPosts = []   // when POST /orders was sent: the public limit is 10 a minute per IP
async function throttle() {
  const now = Date.now()
  while (orderPosts.length && now - orderPosts[0] > 60000) orderPosts.shift()
  if (orderPosts.length >= 9) {
    const wait = 61000 - (now - orderPosts[0])
    console.log(`  (waiting ${Math.ceil(wait / 1000)}s: the checkout limit is 10 a minute)`)
    await sleep(wait)
  }
}
// wait until n more order requests fit in the 10-a-minute window, then count them
async function reserve(n) {
  for (;;) {
    const now = Date.now()
    while (orderPosts.length && now - orderPosts[0] > 60000) orderPosts.shift()
    if (orderPosts.length + n <= 10) break
    const wait = 61000 - (now - orderPosts[0])
    console.log(`  (waiting ${Math.ceil(wait / 1000)}s for the checkout limit)`)
    await sleep(wait)
  }
  for (let i = 0; i < n; i++) orderPosts.push(Date.now())
}
let keySeq = 0
const newKey = () => `flow-${Date.now()}-${++keySeq}-abcdef`
const ADDRESS = { line1: '12 Test Street', city: 'Lahore', area: 'Gulberg', postcode: '54000', country: 'Pakistan' }
const orderBody = (items, c, extra = {}) => ({
  customer: { name: c.name, phone: c.phone, ...(c.email ? { email: c.email } : {}) },
  address: ADDRESS,
  items: items.map(([variant_id, qty]) => ({ variant_id, qty })),
  ...extra,
})
async function placeOrder(body, { key = newKey(), base = BASE, noThrottle = false } = {}) {
  if (!noThrottle) { await throttle(); orderPosts.push(Date.now()) }
  return post('orders', body, undefined, { base: `${base}/api/v1/store`, headers: key ? { 'Idempotency-Key': key } : {} })
}
const store = (route, extra) => get(route, undefined, { base: `${BASE}/api/v1/store`, ...extra })
const quote = (items) => post('checkout/quote', { items: items.map(([variant_id, qty]) => ({ variant_id, qty })) }, undefined, { base: `${BASE}/api/v1/store` })
const lookup = (number, phone) => store(`orders/${number}${phone === undefined ? '' : `?phone=${encodeURIComponent(phone)}`}`)
const variantRow = (id) => dbOne(`SELECT stock_qty, reserved_qty FROM product_variants WHERE id = ${Number(id)}`)

// ================================================================== run
try {
  const fresh = dbOne('SELECT (SELECT COUNT(*) FROM products) p, (SELECT COUNT(*) FROM orders) o, (SELECT COUNT(*) FROM categories) c')
  if (fresh.p || fresh.o || fresh.c) console.log(`WARNING: the database is not fresh (${JSON.stringify(fresh)}); run php artisan migrate:fresh --seed first, or pass --fresh`)

  // ============================================================ A. auth and access
  section('A1', 'Developer and admin log in; a wrong password is rejected')
  const login = (u, p) => post('admin/auth/login', { login: u, password: p })
  const devLogin = await login(...DEV)
  const admLogin = await login(...ADM)
  check(devLogin.status === 200 && devLogin.json?.token, 'developer logs in and gets a token', `HTTP ${devLogin.status} roles=${devLogin.json?.admin?.roles}`)
  check(admLogin.status === 200 && admLogin.json?.token, 'admin (client) logs in and gets a token', `HTTP ${admLogin.status} roles=${admLogin.json?.admin?.roles}`)
  const D = devLogin.json.token
  const A = admLogin.json.token
  const wrong = await login(DEV[0], 'definitely-wrong')
  check(wrong.status === 422 && !wrong.json?.token, 'wrong password is rejected, no token', status(wrong))
  const wrong2 = await login('nobody', 'x')
  check(wrong2.status === 422 && wrong2.json?.message === wrong.json?.message, 'unknown user gets the same answer as a wrong password', status(wrong2))
  check((await get('admin/auth/me')).status === 401, 'no token = 401 on an admin route', 'GET admin/auth/me without token')
  await put('admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, D)   // a known starting point

  section('A2', 'Developer is blocked from orders; the dashboard shows no order figures; (sidebar: see flow-ui.mjs)')
  for (const [m, route, body] of [['GET', 'admin/orders'], ['GET', 'admin/orders/options'], ['GET', 'admin/orders/1'], ['PATCH', 'admin/orders/1/note', { note: 'x' }], ['POST', 'admin/orders/1/status', { status: 'confirmed' }]]) {
    const r = await call(m, route, { token: D, body })
    check(r.status === 403, `developer ${m} ${route} -> 403`, status(r))
  }
  const devDash0 = await get('admin/dashboard', D)
  check(devDash0.status === 200 && !('orders' in (devDash0.json || {})), 'developer dashboard has no "orders" figures', `keys=${Object.keys(devDash0.json || {})}`)
  check((await get('admin/customers', D)).status === 403 && (await get('admin/customers/export', D)).status === 403, 'developer: customers list and export are off by default (403)')

  section('A3', 'Admin cannot open Access Control; Products / Categories are off until the developer enables them; changes are audited')
  const acGet = await get('admin/access-control', A)
  const acPut = await put('admin/access-control/admin', { permissions: [...ADMIN_DEFAULTS, 'products.view'] }, A)
  check(acGet.status === 403 && acPut.status === 403, 'admin GET / PUT access-control -> 403', `${acGet.status} / ${acPut.status}`)
  for (const route of ['admin/products', 'admin/products/options', 'admin/tags', 'admin/categories', 'admin/categories/options', 'admin/banners']) {
    const r = await get(route, A)
    check(r.status === 403, `admin GET ${route} -> 403 by default`, status(r))
  }
  check((await post('admin/categories', { name: 'Nope' }, A)).status === 403, 'admin cannot create a category by default (403)')
  const admSettings = await get('admin/settings', A)
  const tabKeys = (r) => (r.json?.tabs || []).map((t) => t.key ?? t.id ?? t)
  check(JSON.stringify(tabKeys(admSettings).sort()) === JSON.stringify(['delivery', 'footer', 'general', 'orders_delivery', 'payments', 'store']), 'admin sees only the General, Footer, Orders & Delivery, Delivery, Payments and Store tabs', tabKeys(admSettings))
  const meBefore = await get('admin/auth/me', A)
  check(!meBefore.json.admin.permissions.some((p) => p.startsWith('products.') || p.startsWith('categories.')), 'admin /me lists no products/categories permission', meBefore.json.admin.permissions)

  const grant = await put('admin/access-control/admin', { permissions: [...ADMIN_DEFAULTS, 'products.view', 'products.manage', 'categories.view', 'categories.manage'] }, D)
  check(grant.status === 200, 'developer switches Products and Categories on for admin', status(grant))
  const pList = await get('admin/products', A)
  const cList = await get('admin/categories', A)
  const meAfter = await get('admin/auth/me', A)
  check(pList.status === 200 && cList.status === 200, 'admin can now list products and categories (no re-login)', `${pList.status} / ${cList.status}`)
  check(meAfter.json.admin.permissions.includes('products.manage') && meAfter.json.admin.permissions.includes('categories.view'), '/me now lists the new permissions')
  const adminCat = await post('admin/categories', { name: 'Admin Made Category' }, A)
  check(adminCat.status === 201 || adminCat.status === 200, 'admin can create a category once enabled', status(adminCat))
  if (idOf(adminCat)) check((await del(`admin/categories/${idOf(adminCat)}`, A)).status < 300, 'admin can delete it again')
  const revoke = await put('admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, D)
  const pList2 = await get('admin/products', A)
  const cList2 = await get('admin/categories', A)
  const meOff = await get('admin/auth/me', A)
  check(revoke.status === 200 && pList2.status === 403 && cList2.status === 403, 'turning them off hides them again (403)', `${pList2.status} / ${cList2.status}`)
  check(!meOff.json.admin.permissions.includes('products.view'), '/me no longer lists products.view')
  const audits = db("SELECT action, subject_type, subject_id, changes FROM audit_logs WHERE action = 'access.updated' ORDER BY id")
  const grants = audits.filter((a) => /products\.manage/.test(a.changes || ''))
  check(audits.length >= 3 && audits[0].subject_type, 'permission changes are in the audit log (access.updated)', `${audits.length} rows; e.g. ${short(audits[1], 260)}`)
  check(grants.length >= 2, 'the log shows both the grant and the revoke of products.manage', `${grants.length} rows mention products.manage`)
  const devAdminTry = await put('admin/access-control/developer', { permissions: ['orders.view'] }, D)
  check(devAdminTry.status === 422, 'the developer can never be given orders.* (422)', status(devAdminTry))

  // ============================================================ B. catalogue
  section('B4', 'Categories: plain, with two sub-categories, edit, reorder, deactivate, no third level')
  const plain = await post('admin/categories', { name: 'Home Goods', description: 'Plain category without sub-categories', is_top: true, meta_title: 'Home goods' }, D)
  const withSubs = await post('admin/categories', { name: 'Furniture', description: 'Has two sub-categories', is_top: true }, D)
  const plainId = idOf(plain)
  const parentId = idOf(withSubs)
  check(plainId && parentId, 'create a category without and one with sub-categories', `${plainId} / ${parentId}`)
  const iconCat = await call('POST', 'admin/categories', { token: D, form: formOf({ name: 'Garden', is_top: false }, { icon: file('icon.png', 128, 128, [10, 120, 60]), image: file('image.png', 600, 300, [30, 90, 40]) }) })
  check(iconCat.status === 201 && iconCat.json?.data?.icon_url, 'a category can carry an icon and an image (multipart)', status(iconCat))
  const sub1 = await post(`admin/categories/${parentId}/sub-categories`, { name: 'Desks', description: 'Work desks' }, D)
  const sub2 = await post(`admin/categories/${parentId}/sub-categories`, { name: 'Chairs' }, D)
  const sub1Id = idOf(sub1)
  const sub2Id = idOf(sub2)
  check(sub1Id && sub2Id, 'create two sub-categories', `${sub1Id} / ${sub2Id}`)
  const dupSub = await post(`admin/categories/${parentId}/sub-categories`, { name: 'Desks' }, D)
  check(dupSub.status === 422, 'a duplicate sub-category name in the same category is refused (422)', status(dupSub))
  const edit = await put(`admin/categories/${parentId}`, { name: 'Furniture & Decor', description: 'Edited description' }, D)
  check(edit.status === 200 && (edit.json?.data?.name === 'Furniture & Decor'), 'edit the category name', status(edit))
  await put(`admin/sub-categories/${sub1Id}`, { sort_order: 2 }, D)
  await put(`admin/sub-categories/${sub2Id}`, { sort_order: 1 }, D)
  const tree1 = await store('categories')
  const furniture = (tree1.json?.data || []).find((c) => c.id === parentId)
  check(JSON.stringify(furniture?.sub_categories?.map((s) => s.name)) === JSON.stringify(['Chairs', 'Desks']), 'reorder: sub-categories come out in the new order', furniture?.sub_categories?.map((s) => s.name))
  check(furniture?.name === 'Furniture & Decor' && (tree1.json.data.find((c) => c.id === plainId)?.sub_categories || []).length === 0, 'the public tree shows the edit, and the plain category has no sub-categories')
  await put(`admin/categories/${plainId}`, { sort_order: 9 }, D)
  await put(`admin/categories/${parentId}`, { sort_order: 1 }, D)
  const tree2 = await store('categories')
  check(tree2.json.data.findIndex((c) => c.id === parentId) < tree2.json.data.findIndex((c) => c.id === plainId), 'reorder: categories come out by sort_order', tree2.json.data.map((c) => `${c.name}:${c.id}`))
  const deact = await put(`admin/sub-categories/${sub1Id}`, { is_active: false }, D)
  const tree3 = await store('categories')
  const furn3 = tree3.json.data.find((c) => c.id === parentId)
  check(deact.status === 200 && JSON.stringify(furn3.sub_categories.map((s) => s.name)) === JSON.stringify(['Chairs']), 'deactivating a sub-category hides it from the public tree', furn3.sub_categories.map((s) => s.name))
  await put(`admin/sub-categories/${sub1Id}`, { is_active: true }, D)
  const third = await call('POST', `admin/sub-categories/${sub1Id}/sub-categories`, { token: D, body: { name: 'Third level' } })
  check([404, 405].includes(third.status), 'a third level is impossible: there is no route below a sub-category', status(third))
  const nest = await post('admin/categories', { name: 'Nested?', parent_id: sub1Id, category_id: parentId }, D)
  const nestTree = (await store('categories')).json.data
  const nestedRow = nestTree.find((c) => c.id === idOf(nest))
  check(nest.status === 422 || (nestedRow && nestedRow.sub_categories.length === 0 && !nest.json.data.parent_id && nestTree.find((c) => c.id === parentId).sub_categories.every((s) => !('sub_categories' in s))), 'a parent_id on a category is ignored: it is a top-level category, nothing nests, sub-categories have no children', { status: nest.status, topLevel: !!nestedRow, subKeys: Object.keys(nestTree.find((c) => c.id === parentId).sub_categories[0]) })
  if (idOf(nest) && nest.status < 300) await del(`admin/categories/${idOf(nest)}`, D)
  const wrongSub = await post('admin/products', { category_id: plainId, sub_category_id: sub1Id, name: 'Bad', sku: 'BAD-1', variants: [{ name: 'x', mrp: 10, selling_price: 9, stock: 1 }] }, D)
  check(wrongSub.status === 422 && wrongSub.json?.errors?.sub_category_id, 'a product cannot use a sub-category of another category (422)', status(wrongSub))

  section('B5', 'Tag, products (plain category + sub-category) with every field, pictures, publish')
  const tag = await post('admin/tags', { name: 'Best Seller', color: '#d97706' }, D)
  const tagId = idOf(tag)
  const tagSlug = tag.json?.data?.slug
  check(tagId && tagSlug, 'create a tag', status(tag))
  const tab = (key, format, content) => ({ tab_key: key, format, content })
  const deskBody = {
    category_id: parentId, sub_category_id: sub1Id, name: 'Oak Desk', sku: 'DESK-001', short_description: 'A solid oak desk', description: '<p>Solid oak, hand finished.</p>',
    badge_text: 'New', min_order_qty: 1, max_order_qty: 100, is_cod_available: true, status: 'draft', meta_title: 'Oak Desk | Test Store', meta_description: 'Solid oak desk for the home office.',
    tag_ids: [tagId],
    variants: [
      { name: 'Small', sku: 'DESK-S', is_default: true, mrp: 60, selling_price: 50, stock: 20, low_stock_threshold: 3, price_tiers: [{ min_qty: 5, price: 45 }, { min_qty: 10, price: 40 }] },
      { name: 'Large', sku: 'DESK-L', mrp: 100, selling_price: 90, stock: 5, low_stock_threshold: 2 },
    ],
    content_tabs: [tab('description', 'paragraphs', 'Solid oak.\nHand finished.'), tab('features', 'bullets', 'Cable hole\nAdjustable legs'), tab('specifications', 'table', 'Width|120 cm\nDepth|60 cm')],
  }
  const desk = await post('admin/products', deskBody, D)
  const deskId = idOf(desk)
  check(desk.status === 201 && deskId, 'create a product in a SUB-CATEGORY (2 options, bulk tiers, tag, content tabs, SEO)', status(desk))
  const mugBody = {
    category_id: plainId, name: 'Ceramic Mug', sku: 'MUG-001', short_description: 'A blue mug', description: 'Dishwasher safe.', tax_rate: null, max_order_qty: 100,
    status: 'draft', meta_title: 'Ceramic Mug', meta_description: 'Hand made ceramic mug.', tag_ids: [tagId], related_ids: [deskId],
    variants: [
      { name: 'Blue', sku: 'MUG-B', is_default: true, mrp: 15, selling_price: 12, stock: 30, low_stock_threshold: 5, price_tiers: [{ min_qty: 12, price: 10 }] },
      { name: 'Red (sold out)', sku: 'MUG-R', mrp: 15, selling_price: 12, stock: 0 },
    ],
    content_tabs: [tab('description', 'paragraphs', 'A sturdy mug.'), tab('directions', 'bullets', 'Hand wash\nNo microwave')],
  }
  const mug = await post('admin/products', mugBody, D)
  const mugId = idOf(mug)
  check(mug.status === 201 && mugId, 'create a product in the PLAIN category (related product, tag, tier, content)', status(mug))
  const mugFull = (await get(`admin/products/${mugId}`, D)).json?.data
  const deskFull = (await get(`admin/products/${deskId}`, D)).json?.data
  const vid = (p, sku) => p.variants.find((v) => v.sku === sku)?.id
  const deskS = vid(deskFull, 'DESK-S')
  const deskL = vid(deskFull, 'DESK-L')
  const mugB = vid(mugFull, 'MUG-B')
  const mugR = vid(mugFull, 'MUG-R')
  check(deskS && deskL && mugB && mugR, 'options are saved with their SKUs', { deskS, deskL, mugB, mugR })
  check((deskFull.variants.find((v) => v.sku === 'DESK-S').price_tiers || []).length === 2, 'bulk price tiers are saved (2 on Small)', deskFull.variants.find((v) => v.sku === 'DESK-S').price_tiers)
  check(mugFull.status === 'draft' && mugFull.meta_title === 'Ceramic Mug' && (mugFull.related_ids || mugFull.related || []).length >= 1, 'SEO fields, draft status and the related product are stored', { status: mugFull.status, meta_title: mugFull.meta_title, related: mugFull.related_ids ?? mugFull.related })
  check((mugFull.content_tabs || []).length === 2 && (deskFull.content_tabs || []).length === 3, 'content tabs are stored (2 and 3)')
  check(JSON.stringify((mugFull.tag_ids || (mugFull.tags || []).map((t) => t.id))) === JSON.stringify([tagId]), 'the tag is attached', mugFull.tag_ids ?? mugFull.tags)
  const stockRow = variantRow(deskS)
  const mv = dbOne(`SELECT type, qty_change, balance_after FROM inventory_movements WHERE variant_id = ${deskS} ORDER BY id LIMIT 1`)
  check(stockRow.stock_qty === 20 && mv && mv.balance_after === 20, 'opening stock is 20 and an inventory movement was written', { stock: stockRow.stock_qty, movement: mv })
  const dupSku = await post('admin/products', { ...mugBody, name: 'Dup' }, D)
  check(dupSku.status === 422, 'a duplicate SKU is refused (422)', status(dupSku))
  const badTier = await post('admin/products', { ...mugBody, sku: 'MUG-X', variants: [{ name: 'x', sku: 'X1', mrp: 15, selling_price: 12, stock: 1, price_tiers: [{ min_qty: 5, price: 13 }] }] }, D)
  check(badTier.status === 422, 'a bulk price that is not cheaper is refused (422)', status(badTier))

  // pictures
  const up = await call('POST', `admin/products/${deskId}/images`, { token: D, form: formOf({}, { 'images[]': [file('a.png', 400, 400, [200, 50, 50]), file('b.png', 400, 400, [50, 200, 50]), file('c.png', 400, 400, [50, 50, 200])] }) })
  const imgs = (r) => r.json?.data?.images ?? r.json?.images ?? r.json?.data
  check(up.status < 300, 'upload several pictures at once (3)', status(up))
  const deskImgs = (await get(`admin/products/${deskId}`, D)).json.data.images || []
  check(deskImgs.length === 3, 'the product now has 3 pictures', deskImgs.map((i) => `${i.id}${i.is_primary ? '*' : ''}`))
  const firstPrimary = deskImgs.find((i) => i.is_primary)?.id
  const ids = deskImgs.map((i) => String(i.id))
  const reordered = [...ids].reverse()
  const ro = await put(`admin/products/${deskId}/images/order`, { ids: reordered }, D)
  const afterRo = (await get(`admin/products/${deskId}`, D)).json.data.images
  check(ro.status < 300 && JSON.stringify(afterRo.map((i) => String(i.id))) === JSON.stringify(reordered), 'reorder the pictures', afterRo.map((i) => i.id))
  const target = ids[1]
  const pr = await put(`admin/products/${deskId}/images/${target}/primary`, undefined, D)
  const afterPr = (await get(`admin/products/${deskId}`, D)).json.data.images
  check(pr.status < 300 && String(afterPr.find((i) => i.is_primary)?.id) === target && afterPr.filter((i) => i.is_primary).length === 1, 'set another picture as primary (exactly one primary)', { was: firstPrimary, now: afterPr.find((i) => i.is_primary)?.id })
  const rm = await del(`admin/products/${deskId}/images/${ids[2]}`, D)
  const afterRm = (await get(`admin/products/${deskId}`, D)).json.data.images
  check(rm.status < 300 && afterRm.length === 2, 'delete a picture', afterRm.map((i) => i.id))
  const optUp = await call('POST', `admin/products/${deskId}/images`, { token: D, form: formOf({ variant_id: deskL }, { 'images[]': [file('large.png', 300, 300, [120, 120, 120])] }) })
  const optImgs = (await get(`admin/products/${deskId}`, D)).json.data.images
  check(optUp.status < 300 && optImgs.some((i) => i.variant_id === deskL) && optImgs.filter((i) => i.variant_id === null).length === 2, 'upload a picture for one option (variant_id): it belongs to that option only', { admin: optImgs.map((i) => `${i.id}:v${i.variant_id}`) })
  const bigUp = await call('POST', `admin/products/${deskId}/images`, { token: D, form: formOf({}, { 'images[]': [file('tiny.png', 50, 50, [1, 1, 1])] }) })
  check(bigUp.status === 422, 'a picture below 100 px is refused (422)', status(bigUp))
  const mugUp = await call('POST', `admin/products/${mugId}/images`, { token: D, form: formOf({}, { 'images[]': [file('mug.png', 500, 500, [30, 144, 255])] }) })
  check(mugUp.status < 300, 'upload the mug picture', status(mugUp))

  // status workflow
  const pub1 = await patch(`admin/products/${deskId}/status`, { status: 'active' }, D)
  const pub2 = await patch(`admin/products/${mugId}/status`, { status: 'active' }, D)
  check(pub1.status === 200 && pub2.status === 200, 'publish both products (draft -> active)', `${pub1.status} / ${pub2.status}`)
  check((await patch(`admin/products/${mugId}/status`, { status: 'bogus' }, D)).status === 422, 'an unknown status is refused (422)')
  const hid = await patch(`admin/products/${deskId}/status`, { status: 'inactive' }, D)
  const hidden = await store(`products/${deskFull.slug}`)
  await patch(`admin/products/${deskId}/status`, { status: 'active' }, D)
  check(hid.status === 200 && hidden.status === 404, 'the workflow works both ways: inactive hides, active shows again', `inactive -> public ${hidden.status}`)

  // extra products used by the ordering tests
  const utility = await post('admin/products', {
    category_id: plainId, name: 'Notebook', sku: 'NOTE-001', max_order_qty: 600, status: 'active', is_cod_available: true,
    variants: [
      { name: 'Last unit A', sku: 'NOTE-A', is_default: true, mrp: 25, selling_price: 20, stock: 1 },
      { name: 'Last unit B', sku: 'NOTE-B', mrp: 25, selling_price: 20, stock: 1 },
      { name: 'Last unit C', sku: 'NOTE-C', mrp: 25, selling_price: 20, stock: 1 },
      { name: 'Cancel test', sku: 'NOTE-D', mrp: 25, selling_price: 20, stock: 50 },
      { name: 'Limited to 3', sku: 'NOTE-E', mrp: 25, selling_price: 20, stock: 50 },
    ],
  }, D)
  const utilFull = (await get(`admin/products/${idOf(utility)}`, D)).json?.data
  const [noteA, noteB, noteC, noteD] = ['NOTE-A', 'NOTE-B', 'NOTE-C', 'NOTE-D'].map((s) => vid(utilFull, s))
  const limited = await post('admin/products', { category_id: plainId, name: 'Limited Pen', sku: 'PEN-001', max_order_qty: 3, status: 'active', variants: [{ name: 'Pen', sku: 'PEN-1', mrp: 10, selling_price: 8, stock: 100 }] }, D)
  const penId = vid((await get(`admin/products/${idOf(limited)}`, D)).json.data, 'PEN-1')
  check(noteA && noteB && noteC && noteD && penId, 'extra products for the ordering tests exist', { noteA, noteB, noteC, noteD, penId })

  section('B6', 'Public API: categories, products with every filter, product detail; hidden items stay hidden')
  const cats = await store('categories')
  check(cats.status === 200 && cats.json.data.some((c) => c.slug) && cats.json.data.find((c) => c.id === parentId).sub_categories.length === 2, 'GET /categories returns the two-level tree', cats.json.data.map((c) => `${c.name}(${c.sub_categories.length})`))
  const top = await store('categories?top=1')
  check(top.json.data.every((c) => c.is_top), 'GET /categories?top=1 returns only top categories', top.json.data.map((c) => c.name))
  const oneCat = await store('categories/home-goods')
  check(oneCat.status === 200 && oneCat.json.data.name === 'Home Goods', 'GET /categories/{slug}', status(oneCat))
  const list = await store('products?per_page=50')
  const names = (r) => (r.json?.data || []).map((p) => p.name)
  check(list.status === 200 && names(list).includes('Oak Desk') && names(list).includes('Ceramic Mug'), 'GET /products lists the published products', names(list))
  const fCat = await store('products?category=home-goods')
  check(names(fCat).includes('Ceramic Mug') && !names(fCat).includes('Oak Desk'), 'filter category=home-goods', names(fCat))
  const deskSlug = deskFull.slug
  const furnSlug = furnitureSlug(tree1, parentId)
  const subSlug = furniture.sub_categories.find((s) => s.name === 'Desks')?.slug
  const fSub = await store(`products?category=${furnSlug}&sub_category=${subSlug}`)
  check(JSON.stringify(names(fSub)) === JSON.stringify(['Oak Desk']), 'filter category + sub_category', names(fSub))
  const fTag = await store(`products?tag=${tagSlug}`)
  check(names(fTag).includes('Oak Desk') && names(fTag).includes('Ceramic Mug') && !names(fTag).includes('Notebook'), `filter tag=${tagSlug}`, names(fTag))
  const fQ = await store('products?q=oak')
  check(JSON.stringify(names(fQ)) === JSON.stringify(['Oak Desk']), 'search q=oak', names(fQ))
  const fSku = await store('products?q=MUG-001')
  check(names(fSku).includes('Ceramic Mug'), 'search by product SKU (q=MUG-001; option SKUs are searched in the admin only)', names(fSku))
  const fPrice = await store('products?min_price=40&max_price=60')
  check(JSON.stringify(names(fPrice)) === JSON.stringify(['Oak Desk']), 'filter min_price=40&max_price=60 (cheapest option counts)', names(fPrice))
  const fStock = await store('products?in_stock=1')
  check(names(fStock).includes('Oak Desk'), 'filter in_stock=1', names(fStock))
  const asc = await store('products?sort=price_asc&per_page=50')
  const desc = await store('products?sort=price_desc&per_page=50')
  const price = (p) => Number(p.price ?? p.selling_price ?? p.min_price ?? p.starting_price)
  const ascPrices = (asc.json?.data || []).map(price)
  check(asc.status === 200 && ascPrices.every((p, i) => i === 0 || p >= ascPrices[i - 1]) && names(desc)[0] === names(asc).at(-1), 'sort price_asc / price_desc', { asc: names(asc), desc: names(desc), ascPrices })
  for (const s of ['newest', 'popularity', 'discount']) check((await store(`products?sort=${s}`)).status === 200, `sort=${s} is accepted`)
  check((await store('products?sort=bogus')).status === 422, 'an unknown sort is refused (422)')
  const detail = await store(`products/${deskSlug}`)
  const dd = detail.json?.data
  check(detail.status === 200 && dd.variants?.length === 2 && dd.variants[0].price_tiers?.length === 2 && dd.images?.length >= 1 && dd.related !== undefined || detail.json?.data?.related_products !== undefined, 'GET /products/{slug}: options, bulk tiers, images, content, related', { variants: dd?.variants?.length, tiers: dd?.variants?.[0]?.price_tiers, images: dd?.images?.length, keys: Object.keys(dd || {}) })
  const optPublic = dd?.variants?.find((v) => v.id === deskL)
  check(optPublic?.images?.length === 1 && optPublic.images[0].url.startsWith('http'), 'the public product shows the option picture on its option only', { large: optPublic?.images?.length, small: dd?.variants?.find((v) => v.id === deskS)?.images?.length })
  const mugDetail = (await store(`products/${mugFull.slug}`)).json.data
  check(JSON.stringify(mugDetail.related || []).includes('Oak Desk'), 'the mug shows its related product (Oak Desk)', (mugDetail.related || []).map((r) => r.name))

  // hidden items
  const draft = await post('admin/products', { category_id: plainId, name: 'Secret Draft', sku: 'DRAFT-1', status: 'draft', variants: [{ name: 'x', mrp: 10, selling_price: 9, stock: 3 }] }, D)
  const archived = await post('admin/products', { category_id: plainId, name: 'Old Archived', sku: 'ARCH-1', status: 'archived', variants: [{ name: 'x', mrp: 10, selling_price: 9, stock: 3 }] }, D)
  const hiddenCat = await post('admin/categories', { name: 'Hidden Category' }, D)
  const hiddenProd = await post('admin/products', { category_id: idOf(hiddenCat), name: 'In Hidden Category', sku: 'HID-1', status: 'active', variants: [{ name: 'x', mrp: 10, selling_price: 9, stock: 3 }] }, D)
  const inactiveVariant = await post('admin/products', { category_id: plainId, name: 'No Active Option', sku: 'NOOPT-1', status: 'active', variants: [{ name: 'x', sku: 'NOOPT-1A', mrp: 10, selling_price: 9, stock: 3 }, { name: 'y', sku: 'NOOPT-2', mrp: 10, selling_price: 9, stock: 3 }] }, D)
  const draftSlug = draft.json?.data?.slug
  const archSlug = archived.json?.data?.slug
  const hidProdSlug = hiddenProd.json?.data?.slug
  const before = await store(`products/${hidProdSlug}`)
  await put(`admin/categories/${idOf(hiddenCat)}`, { is_active: false }, D)
  const after = await store(`products/${hidProdSlug}`)
  const allNow = await store('products?per_page=100')
  check(before.status === 200 && after.status === 404 && !names(allNow).includes('In Hidden Category'), 'a product of an inactive category is hidden (list and detail)', `before ${before.status}, after ${after.status}`)
  check((await store(`products/${draftSlug}`)).status === 404 && !names(allNow).includes('Secret Draft'), 'a draft product is hidden', names(allNow))
  check((await store(`products/${archSlug}`)).status === 404 && !names(allNow).includes('Old Archived'), 'an archived product is hidden')
  const treeNow = await store('categories')
  check(!treeNow.json.data.some((c) => c.name === 'Hidden Category'), 'an inactive category is not in the public tree')
  check((await store('categories/hidden-category')).status === 404, 'an inactive category answers 404')
  await put(`admin/sub-categories/${sub1Id}`, { is_active: false }, D)
  const inSub = await store(`products/${deskSlug}`)
  const subList = await store('products?per_page=100')
  await put(`admin/sub-categories/${sub1Id}`, { is_active: true }, D)
  check(inSub.status === 404 && !names(subList).includes('Oak Desk'), 'a product of an inactive sub-category is hidden', `detail ${inSub.status}`)
  const oneOpt = (await get(`admin/products/${idOf(inactiveVariant)}`, D)).json.data
  const disabledAll = await put(`admin/products/${idOf(inactiveVariant)}`, { variants: oneOpt.variants.map((v) => ({ id: v.id, name: v.name, sku: v.sku, mrp: v.mrp, selling_price: v.selling_price, is_active: false })) }, D)
  check(disabledAll.status === 422, 'a product must keep at least one active option (422)', status(disabledAll))
  const tagsPublic = await store('tags')
  check(tagsPublic.status === 200 && tagsPublic.json.data.some((t) => t.name === 'Best Seller'), 'GET /tags lists the active tag', tagsPublic.json.data?.map((t) => t.name))

  // ============================================================ C. ordering (guest)
  section('C7', 'Quote: bulk price, tax inclusive and exclusive, delivery fee, free delivery, out of stock, over the limit')
  const setup = await put('admin/settings', { tax_rate: 10, tax_inclusive: true, tax_label: 'VAT', delivery_fee: 5, free_delivery_min_cart: 100, order_number_prefix: 'JHK', default_phone_country_code: '+92', store_country: 'Pakistan', store_name: 'Flow Test Store' }, D)
  check(setup.status === 200, 'developer sets VAT 10% inclusive, delivery 5, free from 100, prefix JHK, phone code +92', status(setup))
  const qBulk = await quote([[deskS, 5]])
  const bl = qBulk.json?.data?.items?.[0]
  const bs = qBulk.json?.data?.summary
  check(qBulk.status === 200 && bl.unit_price === 45 && !!bl.bulk_applied && bl.bulk_applied.price === 45 && bl.line_total === 225, 'bulk price: 5 x Oak Desk Small = unit 45, line 225', { unit: bl?.unit_price, bulk: bl?.bulk_applied, line: bl?.line_total, saving: bl?.saving })
  check(bs.delivery_free === true && bs.delivery_fee === 0 && bs.grand_total === 225, 'free delivery (cart >= 100) and grand total 225', bs)
  const q10 = await quote([[deskS, 10]])
  check(q10.json.data.items[0].unit_price === 40, 'the second tier applies at 10 (unit 40)', q10.json.data.items[0].unit_price)
  const q4 = await quote([[deskS, 4]])
  check(q4.json.data.items[0].unit_price === 50 && q4.json.data.items[0].next_offer, 'at 4 no bulk price yet, with a "next offer" hint', q4.json.data.items[0].next_offer)
  const qSmall = await quote([[mugB, 1]])
  const ss = qSmall.json?.data?.summary
  check(ss.delivery_fee === 5 && ss.delivery_free === false && ss.grand_total === 17 && ss.amount_for_free_delivery === 88, 'delivery fee on a small cart: 12 + 5 = 17, 88 to go for free delivery', ss)
  check(ss.tax_inclusive === true && ss.tax_total === 1.09 && ss.tax_label === 'VAT', 'tax INCLUSIVE: 10% inside 12 = 1.09, total unchanged', { tax_total: ss.tax_total, grand: ss.grand_total })
  await put('admin/settings', { tax_inclusive: false }, D)
  const qEx = (await quote([[mugB, 1]])).json.data.summary
  check(qEx.tax_inclusive === false && qEx.tax_total === 1.2 && qEx.grand_total === 18.2, 'tax EXCLUSIVE: 12 + 1.20 tax + 5 delivery = 18.20', { tax_total: qEx.tax_total, grand: qEx.grand_total })
  await put('admin/settings', { tax_inclusive: true }, D)
  const qOos = await quote([[mugR, 1]])
  const oosW = qOos.json?.data?.items?.[0]?.warnings || []
  check(qOos.status === 200 && qOos.json.data.can_checkout === false && oosW.some((w) => w.code === 'out_of_stock'), 'out of stock: warning out_of_stock, can_checkout false', { can_checkout: qOos.json?.data?.can_checkout, warnings: oosW })
  const qInsuf = await quote([[deskL, 6]])
  check(qInsuf.json.data.can_checkout === false && qInsuf.json.data.items[0].warnings.some((w) => w.code === 'insufficient_stock'), 'more than the stock (6 of 5): insufficient_stock', qInsuf.json.data.items[0].warnings)
  const qOver = await quote([[penId, 4]])
  check(qOver.json.data.can_checkout === false && qOver.json.data.items[0].warnings.some((w) => w.code === 'above_max_qty'), 'over the product limit (4, max 3): above_max_qty', qOver.json.data.items[0].warnings)
  const qHuge = await quote([[penId, 100000]])
  check(qHuge.status === 422, 'a quantity above the global ceiling is a 422 validation error', status(qHuge))
  const qDup = await quote([[penId, 1], [penId, 2]])
  check(qDup.status === 422, 'the same option twice is a 422', status(qDup))
  const qBogus = await quote([[999999, 1]])
  check([200, 422].includes(qBogus.status) && !(qBogus.json?.data?.can_checkout), 'an unknown option cannot be bought', status(qBogus))

  section('C8', 'First order as a new customer: stock, inventory movement, order prefix, customer created')
  const alex = { name: 'Alex Morgan', phone: '+92 300 1234567', email: 'alex@example.com' }
  const stockBefore = variantRow(deskS).stock_qty
  const o1 = await placeOrder(orderBody([[deskS, 5]], alex, { customer_note: 'Call before delivery', expected_total: 225 }))
  const o1d = o1.json?.data
  check(o1.status === 201 && /^JHK-\d{4}-\d{6}$/.test(o1d?.order_number || ''), 'order placed (201) with the JHK prefix', `${o1.status} ${o1d?.order_number}`)
  check(o1d.totals?.grand_total === 225 || o1d.grand_total === 225, 'the order total is the server-priced 225', o1d.totals ?? o1d.grand_total)
  const stockAfter = variantRow(deskS).stock_qty
  check(stockBefore - stockAfter === 5, 'stock was deducted (20 -> 15)', { before: stockBefore, after: stockAfter })
  const mv1 = dbOne(`SELECT type, qty_change, balance_after, reference_type, reference_id FROM inventory_movements WHERE variant_id = ${deskS} ORDER BY id DESC LIMIT 1`)
  check(mv1.type === 'sale' && mv1.qty_change === -5 && mv1.balance_after === 15 && Number(mv1.reference_id) === o1d.id, 'an inventory movement "sale" -5 was written for the order', mv1)
  const cust1 = dbOne("SELECT id, name, phone, email, orders_count, total_spent FROM customers WHERE email = 'alex@example.com'")
  check(cust1 && cust1.phone === '+923001234567' && cust1.orders_count === 1 && Number(cust1.total_spent) === 225, 'the customer was created with the normalised phone +923001234567', cust1)
  const ord1 = dbOne(`SELECT customer_id, status, payment_method, payment_status, shipping_fee, customer_phone FROM orders WHERE id = ${o1d.id}`)
  check(Number(ord1.customer_id) === cust1.id && ord1.status === 'pending' && ord1.payment_method === 'cod' && ord1.payment_status === 'cod_pending', 'the order is linked to the customer: pending, cash on delivery, payment cod_pending', ord1)
  const hist1 = dbOne(`SELECT COUNT(*) n FROM order_status_history WHERE order_id = ${o1d.id}`)
  check(hist1.n >= 1, 'a first history row exists', hist1)
  const mismatch = await placeOrder(orderBody([[mugB, 1]], { name: 'Bad Mail', phone: '+92 300 0000001', email: 'nope' }))
  check(mismatch.status === 422 && mismatch.json?.errors?.['customer.email'], 'an invalid e-mail is refused (422)', status(mismatch))
  const noPhone = await placeOrder(orderBody([[mugB, 1]], { name: 'No Phone', phone: '' }))
  check(noPhone.status === 422 && noPhone.json?.errors?.['customer.phone'], 'a missing phone is refused (422)', status(noPhone))
  const failedCust = dbOne("SELECT COUNT(*) n FROM customers WHERE name IN ('Bad Mail', 'No Phone')")
  check(failedCust.n === 0, 'a refused order leaves no customer behind', failedCust)
  const oos = await placeOrder(orderBody([[mugR, 1]], { name: 'Sold Out', phone: '+92 300 0000002' }))
  check(oos.status === 422 && oos.json?.code === 'cart_invalid', 'an out-of-stock option cannot be ordered (422 cart_invalid)', status(oos))
  const over = await placeOrder(orderBody([[penId, 4]], { name: 'Too Many', phone: '+92 300 0000003' }))
  check(over.status === 422 && over.json?.code === 'cart_invalid', 'an over-limit quantity cannot be ordered (422 cart_invalid)', status(over))
  check(dbOne("SELECT COUNT(*) n FROM customers WHERE name IN ('Sold Out', 'Too Many')").n === 0, 'and no customer or stock change is left behind', variantRow(mugR))

  section('C9', 'Same phone in different formats = ONE customer; orders_count and total_spent updated')
  const o2 = await placeOrder(orderBody([[mugB, 1]], { name: 'Alex Morgan', phone: '0300 1234567' }))
  const o3 = await placeOrder(orderBody([[mugB, 2]], { name: 'Alex M.', phone: '0092 300 1234567' }))
  const o4 = await placeOrder(orderBody([[mugB, 1]], { name: 'Alex Morgan', phone: '+92-300-1234567' }))
  check([o2, o3, o4].every((r) => r.status === 201), 'three more orders: 0300…, 0092 300…, +92-300…', [o2, o3, o4].map((r) => `${r.status} ${r.json?.data?.order_number}`))
  const custs = db("SELECT id, name, phone, email, orders_count, total_spent FROM customers WHERE phone LIKE '%3001234567'")
  check(custs.length === 1 && custs[0].phone === '+923001234567', 'still exactly ONE customer for all four formats', custs)
  const sum = [o1, o2, o3, o4].reduce((s, r) => s + (r.json?.data?.totals?.grand_total ?? r.json?.data?.grand_total ?? 0), 0)
  check(custs[0].orders_count === 4 && Math.abs(Number(custs[0].total_spent) - sum) < 0.005, `orders_count = 4 and total_spent = ${sum}`, custs[0])
  check(custs[0].email === 'alex@example.com', 'an order without e-mail did not wipe the stored one', custs[0].email)
  check(db(`SELECT id FROM orders WHERE customer_id = ${custs[0].id}`).length === 4, 'all four orders are linked to that customer')
  const sameLocal = db("SELECT COUNT(*) n FROM customers")
  check(sameLocal[0].n === 1, 'the customers table has exactly one row so far', sameLocal)

  section('C10', 'Idempotency, price_changed, last-unit race, honeypot, rate limits')
  const key = newKey()
  const bob = { name: 'Bob Idem', phone: '+92 321 7654321' }
  const i1 = await placeOrder(orderBody([[mugB, 1]], bob), { key })
  const i2 = await placeOrder(orderBody([[mugB, 1]], bob), { key })
  check(i1.status === 201 && i2.status === 200 && i2.headers.get('idempotency-replayed') === 'true' && i1.json.data.id === i2.json.data.id, 'same Idempotency-Key: the replay returns the same order (200, Idempotency-Replayed: true)', { first: `${i1.status} #${i1.json?.data?.id}`, replay: `${i2.status} #${i2.json?.data?.id}`, header: i2.headers.get('idempotency-replayed') })
  check(db(`SELECT id FROM orders WHERE idempotency_key = '${key}'`).length === 1, 'only one order row exists for the key')
  const stale = await placeOrder(orderBody([[mugB, 1]], { name: 'Stale', phone: '+92 322 1111111' }, { expected_total: 1 }))
  check(stale.status === 409 && stale.json?.code === 'price_changed' && stale.json?.current_total === 17, 'a stale expected_total is 409 price_changed (with current_total)', status(stale))
  check(dbOne("SELECT COUNT(*) n FROM customers WHERE name = 'Stale'").n === 0, 'and nothing was created')
  const honey = await placeOrder(orderBody([[mugB, 1]], { name: 'Bot', phone: '+92 323 2222222' }, { website: 'http://spam.example' }))
  check(honey.status === 422 && honey.json?.code === 'rejected', 'honeypot filled -> 422 rejected', status(honey))
  check(dbOne("SELECT COUNT(*) n FROM customers WHERE name = 'Bot'").n === 0, 'and no order or customer for the bot')

  // two real PHP processes race for the last unit: 8000 and 8001, three requests each, all at the same moment
  const racers = [0, 1, 2, 3, 4, 5].map((i) => ({ base: i % 2 ? BASE2 : BASE, c: { name: `Racer ${i}`, phone: `+92 330 55500${i}0`.replace(/ /g, ' ') } }))
  await reserve(racers.length)
  const raced = await Promise.all(racers.map((r) => placeOrder(orderBody([[noteA, 1]], r.c, { expected_total: 25 }), { base: r.base, noThrottle: true })))
  const wins = raced.filter((r) => r.status === 201)
  check(wins.length === 1, 'two servers, 6 simultaneous orders for the last unit: exactly one succeeds', raced.map((r) => `${r.status}${r.json?.code ? ` ${r.json.code}` : ''}`))
  check(raced.filter((r) => r.status === 422).length === 5, 'the other five are refused with 422 cart_invalid', raced.map((r) => r.json?.code))
  const noteAStock = variantRow(noteA)
  check(noteAStock.stock_qty === 0, 'the stock is exactly 0 (never negative)', noteAStock)
  check(dbOne("SELECT COUNT(*) n FROM customers WHERE name LIKE 'Racer%'").n === 1, 'only the winner became a customer (losers rolled back)')
  const sales = dbOne(`SELECT COUNT(*) n FROM inventory_movements WHERE variant_id = ${noteA} AND type = 'sale'`)
  check(sales.n === 1, 'exactly one sale movement for that option', sales)
  // a second round on option B with a single shared phone, to prove the idempotency key does not hide races
  await reserve(4)
  const raced2 = await Promise.all([0, 1, 2, 3].map((i) => placeOrder(orderBody([[noteB, 1]], { name: `Dup ${i}`, phone: `+92 331 600000${i}` }, { expected_total: 25 }), { base: i % 2 ? BASE2 : BASE, noThrottle: true, key: newKey() })))
  check(raced2.filter((r) => r.status === 201).length === 1 && raced2.filter((r) => r.status === 422).length === 3 && variantRow(noteB).stock_qty === 0, 'second round (4 requests, two servers): again exactly one winner, three 422, stock 0', raced2.map((r) => r.status))

  // limits (the limiter counts every request, valid or not)
  await sleep(61000)
  orderPosts.length = 0
  const burst = []
  for (let i = 0; i < 12; i++) burst.push(await post('orders', { items: [] }, undefined, { base: `${BASE}/api/v1/store` }))
  const limited429 = burst.filter((r) => r.status === 429)
  check(limited429.length >= 2 && burst.slice(0, 10).every((r) => r.status === 422), 'checkout: the 11th request within a minute is 429 (first 10 answered 422)', burst.map((r) => r.status))
  check(limited429[0]?.headers.get('retry-after'), 'the 429 carries a Retry-After header', limited429[0]?.headers.get('retry-after'))
  const q429 = []
  for (let i = 0; i < 62; i++) { const r = await quote([[mugB, 1]]); q429.push(r.status); if (r.status === 429) break }
  check(q429.at(-1) === 429 && q429.length === 61, 'quote: the 61st request within a minute is 429', `${q429.length} requests, last ${q429.at(-1)}`)
  await sleep(61000)

  section('C11', 'Order lookup: the right phone works; a wrong phone looks exactly like an unknown order')
  const num = o1d.order_number
  const okL = await lookup(num, '+92 300 1234567')
  const okLocal = await lookup(num, '0300 1234567')
  const wrongL = await lookup(num, '+92 300 9999999')
  const unknownL = await lookup('JHK-2026-999999', '+92 300 1234567')
  const noPhoneL = await lookup(num)
  check(okL.status === 200 && okL.json.data.order_number === num && okL.json.data.items?.length, 'right phone -> the order with items, totals and timeline', status(okL).slice(0, 200))
  check(okLocal.status === 200, 'the local form 0300… of the same phone works too', okLocal.status)
  check(wrongL.status === 404 && unknownL.status === 404 && wrongL.text === unknownL.text, 'wrong phone and unknown order: identical 404 answers', { wrong: wrongL.text, unknown: unknownL.text })
  check(noPhoneL.status === 422, 'a missing phone is a 422', status(noPhoneL))
  check(!JSON.stringify(wrongL.json).includes('JHK'), 'the 404 does not reveal anything about the order')
  const lk = []
  for (let i = 0; i < 16; i++) { const r = await lookup('JHK-2026-000777', '+92 300 0000000'); lk.push(r.status) }
  check(lk.at(-1) === 429 && lk.slice(0, 15).every((s) => s === 404), 'lookup: the 16th try on one order number within the hour is 429', lk)
  await sleep(61000)   // the lookup limit is per IP as well (20 a minute)

  // ============================================================ D. admin order handling (client admin, default access)
  section('D12', 'Admin sees the order in Orders and the Dashboard, opens the detail, adds an internal note')
  const orders = await get('admin/orders?per_page=50', A)
  const row = (orders.json?.data || []).find((o) => o.order_number === num)
  check(orders.status === 200 && row, 'the new order is in the Orders list', `${orders.status} ${(orders.json?.data || []).map((o) => o.order_number)}`)
  const search = await get(`admin/orders?q=${encodeURIComponent(num)}`, A)
  check(search.json.data.length === 1, 'search by order number finds it', search.json.data.length)
  const searchPhone = await get('admin/orders?q=0300 1234567', A)
  const searchIntl = await get('admin/orders?q=+92 300 1234567', A)
  check(searchIntl.json.data.length >= 4 && searchPhone.json.data.length === searchIntl.json.data.length, 'search by phone: the +92… and the local 0300… form find the same orders', { intl: searchIntl.json.data.length, local: searchPhone.json.data.length })
  const searchName = await get('admin/orders?q=Alex', A)
  check(searchName.json.data.length >= 4, 'search by customer name finds the four orders', searchName.json.data.length)
  const dash = await get('admin/dashboard', A)
  check(dash.status === 200 && dash.json.orders?.today >= 1 && dash.json.orders?.pending >= 1 && dash.json.orders?.revenue_today > 0, 'dashboard shows orders today, pending and revenue to the client admin', dash.json.orders)
  const det = await get(`admin/orders/${o1d.id}`, A)
  const dd1 = det.json?.data
  check(det.status === 200 && dd1.order_number === num && dd1.items?.length === 1 && dd1.customer?.phone === '+923001234567', 'the detail shows items, customer, addresses, timeline', { status: dd1?.status, items: dd1?.items?.length, customer: dd1?.customer })
  const note = await patch(`admin/orders/${o1d.id}/note`, { admin_note: 'Customer prefers the afternoon.' }, A)
  const det2 = (await get(`admin/orders/${o1d.id}`, A)).json.data
  check(note.status === 200 && JSON.stringify(det2).includes('Customer prefers the afternoon.'), 'an internal note is saved and shown', status(note))
  check(!JSON.stringify((await lookup(num, '0300 1234567')).json).includes('afternoon'), 'the internal note is NOT visible to the customer lookup')
  const dashNote = await get('admin/dashboard', A)
  check((dashNote.json.recent_activity || []).some((a) => a.action === 'order.note_updated' && a.subject_name === num), 'the client admin sees the order activity (with the order number) on the dashboard', (dashNote.json.recent_activity || []).slice(0, 3).map((a) => `${a.action} ${a.subject_name}`))
  check(db("SELECT id FROM audit_logs WHERE action LIKE 'order.%'").length >= 1, 'the note is in the audit log', db("SELECT action FROM audit_logs WHERE action LIKE 'order.%'"))
  // developer after real orders exist: the earlier A2 checks again
  const devOrders = await get(`admin/orders/${o1d.id}`, D)
  const devDash = await get('admin/dashboard', D)
  current = book.get('A2')
  check(devOrders.status === 403 && !JSON.stringify(devOrders.json).includes(num), 'with real orders in place: developer still gets 403 and no order data', status(devOrders))
  check(!('orders' in devDash.json) && !JSON.stringify(devDash.json).includes('JHK-') && !(devDash.json.recent_activity || []).some((a) => /^order\.|^customers\./.test(a.action) || a.subject_type === 'Order'), 'with real orders in place: the developer dashboard has no order figures, no order numbers, no order activity', { keys: Object.keys(devDash.json), activity: (devDash.json.recent_activity || []).map((a) => a.action) })
  check((await get('admin/orders', D)).status === 403 && (await get('admin/customers', D)).status === 403, 'with real orders in place: orders and customers stay 403 for the developer')
  current = book.get('D12')

  section('D13', 'Status flow pending > confirmed > packed > shipped > delivered; COD paid on delivery; history and audit; invalid jumps refused')
  const st = (id, body) => post(`admin/orders/${id}/status`, body, A)
  const jumps = [['shipped', { status: 'shipped', courier: 'TCS', awb_number: 'X1' }], ['delivered', { status: 'delivered' }], ['packed', { status: 'packed' }]]
  for (const [name, b] of jumps) { const r = await st(o1d.id, b); check(r.status === 422, `pending -> ${name} is refused (422)`, status(r)) }
  check((await st(o1d.id, { status: 'pending' })).status === 422, 'moving back to pending is refused (422)')
  const c1 = await st(o1d.id, { status: 'confirmed', note: 'Phone confirmed' })
  check(c1.status === 200 && c1.json.data.status === 'confirmed', 'pending -> confirmed', status(c1).slice(0, 120))
  check((await st(o1d.id, { status: 'shipped', courier: 'TCS', awb_number: 'X1' })).status === 422, 'confirmed -> shipped (skipping packed) is refused')
  const p1 = await st(o1d.id, { status: 'packed' })
  check(p1.status === 200, 'confirmed -> packed', status(p1).slice(0, 120))
  const sNo = await st(o1d.id, { status: 'shipped' })
  check(sNo.status === 422 && sNo.json?.errors?.courier && sNo.json?.errors?.awb_number, 'shipping without courier and tracking number is refused (422)', sNo.json?.errors)
  const sOk = await st(o1d.id, { status: 'shipped', courier: 'TCS', awb_number: 'TCS123456', tracking_url: 'https://track.example.com/TCS123456' })
  check(sOk.status === 200, 'packed -> shipped (courier + AWB + link)', status(sOk).slice(0, 120))
  const trackSeen = await lookup(num, '0300 1234567')
  check(trackSeen.json?.data?.tracking?.awb_number === 'TCS123456' && trackSeen.json.data.tracking.courier === 'TCS', 'the customer lookup now shows courier and tracking number', trackSeen.json?.data?.tracking ?? status(trackSeen))
  check((await st(o1d.id, { status: 'cancelled', reason: 'too late' })).status === 422, 'a shipped order can no longer be cancelled (422)')
  const payBefore = dbOne(`SELECT status, paid_at FROM payments WHERE payable_id = ${o1d.id} AND payable_type LIKE '%Order'`)
  const dl = await st(o1d.id, { status: 'delivered' })
  check(dl.status === 200 && dl.json.data.status === 'delivered', 'shipped -> delivered', status(dl).slice(0, 120))
  const payAfter = dbOne(`SELECT status, paid_at FROM payments WHERE payable_id = ${o1d.id} AND payable_type LIKE '%Order'`)
  const ordPaid = dbOne(`SELECT payment_status, status, delivered_at FROM orders WHERE id = ${o1d.id}`)
  check(payBefore.status === 'cod_pending' && payAfter.status === 'cod_collected' && payAfter.paid_at && ordPaid.payment_status === 'paid', 'COD became paid on delivery (payment row and order)', { before: payBefore, after: payAfter, order: ordPaid })
  check((await st(o1d.id, { status: 'cancelled', reason: 'x' })).status === 422 && (await st(o1d.id, { status: 'confirmed' })).status === 422, 'a delivered order is final: cancel and re-open are refused')
  const hist = db(`SELECT from_status, to_status FROM order_status_history WHERE order_id = ${o1d.id} ORDER BY id`)
  check(JSON.stringify(hist.map((h) => h.to_status)) === JSON.stringify(['pending', 'confirmed', 'packed', 'shipped', 'delivered']), 'history rows: pending, confirmed, packed, shipped, delivered', hist.map((h) => `${h.from_status}>${h.to_status}`))
  const audit = db(`SELECT action, actor_type FROM audit_logs WHERE subject_type = 'Order' AND subject_id = ${o1d.id} ORDER BY id`)
  check(audit.filter((a) => /status/.test(a.action)).length >= 4, 'an audit entry for every status change (and the note)', audit.map((a) => a.action))
  const timeline = (await get(`admin/orders/${o1d.id}`, A)).json.data
  check(JSON.stringify(timeline).includes('delivered') && timeline.status === 'delivered', 'the admin detail shows the delivered status and timeline')
  const stockNow = variantRow(deskS).stock_qty
  check(stockNow === stockBefore - 5, 'delivery does not change the stock again', { stock: stockNow })

  section('D14', 'Cancel an order: stock returns; invoice and packing slip (browser: flow-ui.mjs)')
  const cs = await placeOrder(orderBody([[noteD, 3]], { name: 'Cora Cancel', phone: '+92 345 4443332', email: 'cora@example.com' }, { expected_total: 65 }))
  check(cs.status === 201, 'place a second order (3 x Cancel test = 60 + 5 delivery)', status(cs).slice(0, 160))
  const csId = cs.json?.data?.id
  const sBefore = variantRow(noteD).stock_qty
  const cNoReason = await st(csId, { status: 'cancelled' })
  check(cNoReason.status === 422 && cNoReason.json?.errors?.reason, 'cancelling needs a reason (422)', cNoReason.json?.errors)
  const cc = await st(csId, { status: 'cancelled', reason: 'Customer changed their mind' })
  const sAfter = variantRow(noteD).stock_qty
  check(cc.status === 200 && sBefore === 47 && sAfter === 50, 'cancelled: stock returns (47 -> 50)', { before: sBefore, after: sAfter })
  const rv = dbOne(`SELECT type, qty_change FROM inventory_movements WHERE variant_id = ${noteD} ORDER BY id DESC LIMIT 1`)
  check(rv.qty_change === 3 && rv.type !== 'sale', 'a return movement (+3) was written', rv)
  const coraRow = dbOne("SELECT orders_count, total_spent FROM customers WHERE email = 'cora@example.com'")
  check(coraRow.orders_count === 0 && Number(coraRow.total_spent) === 0, 'the customer totals were reduced again', coraRow)
  check((await st(csId, { status: 'confirmed' })).status === 422, 'a cancelled order cannot be re-opened (422)')
  const csDetail = (await get(`admin/orders/${csId}`, A)).json.data
  check(csDetail.status === 'cancelled', 'the detail shows it as cancelled', csDetail.status)
  const lookupCancelled = await lookup(cs.json.data.order_number, '+92 345 4443332')
  check(lookupCancelled.json?.data?.status === 'cancelled', 'the customer lookup shows it as cancelled')
  const dashAfter = await get('admin/dashboard', A)
  check(dashAfter.json.orders.pending >= 3, 'the dashboard pending count follows (cancelled and delivered are not pending)', dashAfter.json.orders)

  section('D15', 'Customers screen: search by name, phone, e-mail; CSV export matches')
  const cn = await get('admin/customers?q=Alex', A)
  const cp = await get('admin/customers?q=300123', A)
  const cpLocal = await get('admin/customers?q=0300', A)
  const cm = await get('admin/customers?q=cora@', A)
  const cNone = await get('admin/customers?q=zzzzzz', A)
  check(cn.json.data.length === 1 && cn.json.data[0].orders_count === 4, 'search by name', cn.json.data.map((c) => `${c.name}:${c.orders_count}`))
  check(cp.json.data.length === 1 && cpLocal.json.data.length >= 1, 'search by phone (part of it, and the local 0300… form)', { part: cp.json.data.length, local: cpLocal.json.data.length })
  check(cm.json.data.length === 1 && cm.json.data[0].name === 'Cora Cancel', 'search by e-mail', cm.json.data.map((c) => c.name))
  check(cNone.json.data.length === 0, 'a search without a match is empty')
  const all = await get('admin/customers?per_page=100', A)
  const csv = await get('admin/customers/export', A)
  const lines = csv.text.trim().split(/\r?\n/)
  check(csv.status === 200 && /^"?name"?,/i.test(lines[0]) && lines.length - 1 === all.json.data.length, 'CSV export: header + one row per customer', { header: lines[0], rows: lines.length - 1, listed: all.json.data.length })
  const alexLine = lines.find((l) => l.includes('+923001234567'))
  const alexRow = all.json.data.find((c) => c.phone === '+923001234567')
  check(alexLine && alexLine.includes('Alex') && alexLine.includes('alex@example.com') && alexLine.includes(String(alexRow.orders_count)) && alexLine.includes(String(alexRow.total_spent).replace(/\.0+$/, '')), 'the CSV row of Alex matches the list (name, phone, e-mail, orders, spent)', alexLine)
  const csvQ = await get('admin/customers/export?q=cora', A)
  check(csvQ.text.trim().split(/\r?\n/).length === 2 && csvQ.text.includes('Cora'), 'the export follows the search (q=cora)', csvQ.text.trim().split(/\r?\n/).length)
  check(db("SELECT id FROM audit_logs WHERE action = 'customers.exported'").length >= 2, 'exports are audited', db("SELECT action FROM audit_logs WHERE action LIKE 'customers.%'").length)

  // ============================================================ E. settings
  section('E16', 'Every Settings tab changes the public API; a restricted admin cannot open tabs that are off')
  const pub = async () => (await store('settings')).json
  const w = await put('admin/settings', {
    store_name: 'Acme Outlet', store_tagline: 'Everything for home', store_email: 'hello@acme.test', store_phone: '+92 42 111 000', store_address: '1 Main Road, Lahore', store_country: 'Pakistan', default_phone_country_code: '+92',
  }, D)
  let p = await pub()
  check(w.status === 200 && p.store.name === 'Acme Outlet' && p.store.tagline === 'Everything for home' && p.store.email === 'hello@acme.test' && p.store.country === 'Pakistan', 'General tab -> public store profile', p.store)
  check((await put('admin/settings', { default_phone_country_code: '92' }, D)).status === 422, 'General tab validates the phone country code (422 for "92")')

  const lg = await call('POST', 'admin/settings/logo', { token: D, form: formOf({}, { logo: file('logo.png', 300, 100, [20, 20, 120]) }) })
  const fav = await call('POST', 'admin/settings/favicon', { token: D, form: formOf({}, { favicon: file('fav.png', 64, 64, [200, 20, 20]) }) })
  p = await pub()
  const imgOk = async (url) => url && (await fetch(url)).status === 200
  check(lg.status < 300 && fav.status < 300 && (await imgOk(p.store.logo_url)) && (await imgOk(p.store.favicon_url)), 'Branding: logo and favicon upload; public URLs serve the files', { logo: p.store.logo_url, favicon: p.store.favicon_url })
  const svg = await call('POST', 'admin/settings/logo', { token: D, form: formOf({}, { logo: new File(['<svg xmlns="http://www.w3.org/2000/svg"/>'], 'x.svg', { type: 'image/svg+xml' }) }) })
  check(svg.status === 422, 'an SVG logo is refused (422)', status(svg))
  const sh = await call('POST', 'admin/settings/share-image', { token: D, form: formOf({}, { share_image: file('share.png', 1200, 630, [40, 40, 40]) }) })

  // banners
  const day = 86400000
  const iso = (offset) => new Date(Date.now() + offset * day).toISOString()
  const mkBanner = (fields, files = {}) => call('POST', 'admin/banners', { token: D, form: formOf(fields, { desktop_image: file('hero.png', 1200, 400, [220, 80, 20]), ...files }) })
  const b1 = await mkBanner({ placement: 'home_hero', title: 'Summer Sale', subtitle: 'Up to 50% off', button_text: 'Shop now', link_url: '/category/home-goods', is_active: true })
  const b2 = await mkBanner({ placement: 'home_hero', title: 'New Arrivals', link_url: 'https://example.com/new', is_active: true }, { mobile_image: file('mobile.png', 600, 600, [20, 80, 220]) })
  const b3 = await mkBanner({ placement: 'home_hero', title: 'Inactive One', is_active: false })
  const b4 = await mkBanner({ placement: 'home_hero', title: 'Future One', is_active: true, starts_at: iso(5) })
  const b5 = await mkBanner({ placement: 'home_hero', title: 'Expired One', is_active: true, ends_at: iso(-5) })
  const b6 = await mkBanner({ placement: 'home_hero', title: 'Window One', is_active: true, starts_at: iso(-1), ends_at: iso(1) })
  const b7 = await mkBanner({ placement: 'promo_strip', title: 'Free delivery over 100', is_active: true })
  const bids = [b1, b2, b3, b4, b5, b6, b7].map(idOf)
  check(bids.every(Boolean), 'create 7 banners (two placements, active/inactive, future, expired, current window)', bids)
  const titles = async (pl) => ((await store(`banners?placement=${pl}`)).json?.data || []).map((b) => b.title)
  const live = await titles('home_hero')
  check(['Summer Sale', 'New Arrivals', 'Window One'].every((t) => live.includes(t)) && !live.some((t) => ['Inactive One', 'Future One', 'Expired One'].includes(t)), 'public home_hero: only live banners (active, inside the date window)', live)
  check(JSON.stringify(await titles('promo_strip')) === JSON.stringify(['Free delivery over 100']), 'public promo_strip has its own banner', await titles('promo_strip'))
  const bany = (await store('banners')).json.data
  check(bany.length === 4 && bany.every((b) => b.placement), 'without a placement every live banner is returned (4)', bany.length)
  check((await store('banners?placement=nope')).status === 422, 'an unknown placement is a 422')
  const pubB = (await store('banners?placement=home_hero')).json.data
  check(pubB.find((b) => b.title === 'New Arrivals').mobile_image_url && !pubB.find((b) => b.title === 'Summer Sale').mobile_image_url && (await imgOk(pubB[0].image_url)), 'desktop and mobile pictures: absolute URLs that serve, mobile only where uploaded', pubB.map((b) => ({ t: b.title, mobile: !!b.mobile_image_url })))
  const ed = await call('POST', `admin/banners/${bids[0]}`, { token: D, form: formOf({ _method: 'PUT', title: 'Winter Sale', button_text: 'Buy' }) })
  check(ed.status === 200 && (await titles('home_hero')).includes('Winter Sale'), 'edit a banner (title) -> public answer follows', status(ed).slice(0, 100))
  const order0 = await titles('home_hero')
  const live3 = [bids[0], bids[1], bids[5]]
  const ro1 = await put('admin/banners/reorder', { ids: [bids[5], bids[0], bids[1]] }, D)
  const order1 = await titles('home_hero')
  check(ro1.status === 200 && JSON.stringify(order1) === JSON.stringify(['Window One', 'Winter Sale', 'New Arrivals']), 'reorder banners -> public order follows', { before: order0, after: order1 })
  const off = await put(`admin/banners/${bids[1]}`, { is_active: false }, D)
  check(off.status === 200 && !(await titles('home_hero')).includes('New Arrivals'), 'deactivate a banner -> it leaves the public answer')
  await put(`admin/banners/${bids[1]}`, { is_active: true }, D)
  const win = await put(`admin/banners/${bids[1]}`, { starts_at: iso(3) }, D)
  check(win.status === 200 && !(await titles('home_hero')).includes('New Arrivals'), 'a start date in the future hides it', await titles('home_hero'))
  const badWin = await put(`admin/banners/${bids[1]}`, { starts_at: iso(3), ends_at: iso(1) }, D)
  check(badWin.status === 422, 'an end date before the start date is refused (422)', status(badWin))
  await put(`admin/banners/${bids[1]}`, { starts_at: null, ends_at: null }, D)
  check((await titles('home_hero')).includes('New Arrivals'), 'clearing the dates brings it back')
  const badLink = await put(`admin/banners/${bids[1]}`, { link_url: 'javascript:alert(1)' }, D)
  check(badLink.status === 422, 'a javascript: link is refused (422)', status(badLink))
  const delB = await del(`admin/banners/${bids[2]}`, D)
  check(delB.status < 300, 'delete a banner')
  const logoEmpty = await del('admin/settings/favicon', D)
  check(logoEmpty.status < 300 && (await pub()).store.favicon_url === null, 'Branding: removing the favicon empties the public URL')

  // footer & social
  const links = [
    { platform: 'facebook', label: 'Facebook', url: 'https://facebook.com/acme', active: true },
    { platform: 'instagram', label: '', url: 'https://instagram.com/acme', active: true },
    { platform: 'whatsapp', label: 'Chat', url: 'https://wa.me/923001234567', active: true },
    { platform: 'custom', label: 'Blog', url: 'https://blog.acme.test', active: false },
  ]
  const fs = await put('admin/settings', { social_links: links, footer_text: 'Quality homeware since 2020.', footer_copyright: '© 2026 Acme Outlet', support_hours: 'Mon-Sat 9-18', whatsapp_number: '+92 300 1234567' }, D)
  p = await pub()
  check(fs.status === 200 && JSON.stringify(p.social_links.map((l) => l.platform)) === JSON.stringify(['facebook', 'instagram', 'whatsapp']), 'Footer & Social: several links, in order, inactive ones hidden', p.social_links.map((l) => `${l.platform}:${l.label}`))
  check(p.social_links[1].label === 'instagram' || p.social_links[1].label === 'Instagram', 'an empty label falls back to the platform name', p.social_links[1].label)
  check(p.footer.text === 'Quality homeware since 2020.' && p.footer.copyright === '© 2026 Acme Outlet' && p.footer.support_hours === 'Mon-Sat 9-18' && p.footer.whatsapp_url === 'https://wa.me/923001234567', 'footer text, copyright, support hours and the wa.me link', p.footer)
  await put('admin/settings', { social_links: [links[2], links[0], links[1]] }, D)
  check(JSON.stringify((await pub()).social_links.map((l) => l.platform)) === JSON.stringify(['whatsapp', 'facebook', 'instagram']), 'reordering the links changes the public order', (await pub()).social_links.map((l) => l.platform))
  const badUrl = await put('admin/settings', { social_links: [{ platform: 'custom', url: 'javascript:alert(1)' }] }, D)
  check(badUrl.status === 422, 'a javascript: link is refused (422)', status(badUrl))

  // currency & tax, orders & delivery, SEO
  await put('admin/settings', { currency_code: 'PKR', currency_symbol: 'Rs', currency_decimals: 0, tax_rate: 17, tax_inclusive: false, tax_label: 'GST' }, D)
  p = await pub()
  check(p.currency.code === 'PKR' && p.currency.symbol === 'Rs' && p.currency.decimals === 0 && p.tax.rate === 17 && p.tax.inclusive === false && p.tax.label === 'GST', 'Currency & Tax tab -> public currency and tax', { currency: p.currency, tax: p.tax })
  const qCur = (await quote([[mugB, 1]])).json.data.summary
  check(qCur.tax_label === 'GST' && qCur.tax_inclusive === false && Number.isInteger(qCur.grand_total), 'the quote follows (GST, exclusive, 0 decimals)', qCur)
  await put('admin/settings', { currency_code: 'USD', currency_symbol: '$', currency_decimals: 2, tax_rate: 10, tax_inclusive: true, tax_label: 'VAT' }, D)
  await put('admin/settings', { order_number_prefix: 'ZED', delivery_fee: 7.5, free_delivery_min_cart: 150, delivery_days: 3, min_order_amount: 15, cod_max_amount: 100 }, D)
  p = await pub()
  check(p.delivery.fee === 7.5 && p.delivery.free_delivery_min_cart === 150 && p.delivery.days === 3 && p.orders.min_order_amount === 15 && p.orders.cod_max_amount === 100, 'Orders & Delivery tab -> public delivery and order limits', { delivery: p.delivery, orders: p.orders })
  const qMin = (await quote([[mugB, 1]])).json.data
  check(qMin.can_checkout === false && qMin.issues?.some((i) => (i.code ?? i) === 'below_min_order') && qMin.summary.delivery_fee === 7.5, 'the minimum order and the new delivery fee are applied by the quote', qMin.issues)
  const belowMin = await placeOrder(orderBody([[mugB, 1]], { name: 'Min', phone: '+92 346 1010101' }))
  check(belowMin.status === 422 && belowMin.json?.code === 'below_min_order', 'checkout refuses an order under the minimum (422 below_min_order)', status(belowMin).slice(0, 140))
  const zed = await placeOrder(orderBody([[mugB, 2]], { name: 'Zed Prefix', phone: '+92 346 2020202' }))
  check(zed.status === 201 && /^ZED-\d{4}-\d{6}$/.test(zed.json.data.order_number), 'a new order number prefix applies to the next order', zed.json?.data?.order_number)
  const cod = await placeOrder(orderBody([[deskL, 2]], { name: 'Cod Limit', phone: '+92 346 3030303' }))
  check(cod.status === 422 && cod.json?.code === 'cod_not_allowed', 'the cash on delivery limit (100) refuses a 180 order (422 cod_not_allowed)', status(cod).slice(0, 160))
  check(dbOne("SELECT COUNT(*) n FROM customers WHERE name = 'Cod Limit'").n === 0, 'and leaves no customer behind')
  await put('admin/settings', { delivery_fee: 5, free_delivery_min_cart: 100, delivery_days: 5, min_order_amount: 0, cod_max_amount: 0, order_number_prefix: 'JHK' }, D)
  await put('admin/settings', { seo_meta_title: 'Acme Outlet | Home goods', seo_meta_description: 'Quality homeware delivered across Pakistan.' }, D)
  p = await pub()
  check(sh.status < 300 && p.seo.meta_title === 'Acme Outlet | Home goods' && p.seo.meta_description.startsWith('Quality homeware') && (await imgOk(p.seo.share_image_url)), 'SEO tab -> default meta title, description and share image', p.seo)
  check((await put('admin/settings', { seo_meta_title: 'x'.repeat(71) }, D)).status === 422, 'the meta title is limited to 70 characters (422)')

  // restricted admin
  const aTabs = tabKeys(await get('admin/settings', A))
  const tryPut = async (body) => (await put('admin/settings', body, A)).status
  check(JSON.stringify(aTabs.sort()) === JSON.stringify(['delivery', 'footer', 'general', 'orders_delivery', 'payments', 'store']), 'restricted admin: GET settings lists only the 6 open tabs (no Theme: it is the developer\'s)', aTabs)
  check([await tryPut({ currency_code: 'EUR' }), await tryPut({ seo_meta_title: 'x' }), await tryPut({ store_name: 'Mixed', currency_code: 'EUR' })].every((s) => s === 403), 'restricted admin: Currency & Tax, SEO and a mixed request are all 403', 'currency_code, seo_meta_title, mixed')
  check((await call('POST', 'admin/settings/logo', { token: A, form: formOf({}, { logo: file('l.png', 200, 100, [1, 2, 3]) }) })).status === 403, 'restricted admin: the logo upload (Branding) is 403')
  check((await get('admin/banners', A)).status === 403 && (await post('admin/banners', {}, A)).status === 403, 'restricted admin: Banners are 403')
  check((await put('admin/settings', { store_tagline: 'Set by the client' }, A)).status === 200 && (await pub()).store.tagline === 'Set by the client', 'restricted admin: can still edit an open tab (General) and it shows publicly')
  check((await pub()).store.name === 'Acme Outlet', 'the refused mixed request changed nothing (store name unchanged)')
  await put('admin/access-control/admin', { permissions: [...ADMIN_DEFAULTS, 'settings.branding', 'settings.banners'] }, D)
  const aTabs2 = tabKeys(await get('admin/settings', A))
  check(aTabs2.includes('branding') && aTabs2.includes('banners') && (await get('admin/banners', A)).status === 200, 'the developer switches Branding and Banners on -> they open', aTabs2)
  await put('admin/access-control/admin', { permissions: ADMIN_DEFAULTS }, D)
  check(!tabKeys(await get('admin/settings', A)).includes('banners') && (await get('admin/banners', A)).status === 403, 'and off again -> closed')
  check(db("SELECT id FROM audit_logs WHERE action LIKE 'settings.%' OR action LIKE 'banner%'").length >= 5, 'settings and banner changes are in the audit log', db("SELECT DISTINCT action FROM audit_logs WHERE action LIKE 'settings.%' OR action LIKE 'banner%'").map((r) => r.action))

  { // ---- Phase A checks (own scope)
  // ============================================================ F. Phase A: settings, delivery, payments, discounts, coupons, cart, order changes
  const STORE_BASE = `${BASE}/api/v1/store`
  const storePost = (route, body, extra = {}) => post(route, body, undefined, { base: STORE_BASE, ...extra })
  const storeCall = (method, route, opts = {}) => call(method, route, { base: STORE_BASE, ...opts })
  const mk = async (name, sku, price, extra = {}) => {
    const r = await post('admin/products', { category_id: plainId, name, sku, status: 'active', variants: [{ name, sku: `${sku}-V`, mrp: price, selling_price: price, stock: 100, is_default: true }], ...extra }, D)
    const full = await get(`admin/products/${idOf(r)}`, D)
    return { id: idOf(r), variant: full.json?.data?.variants?.[0]?.id, status: r.status }
  }
  const setSettings = (body, token = A) => put('admin/settings', body, token)
  const artisan = (code) => execFileSync('php', ['artisan', 'tinker', '--execute', code], { cwd: ROOT, encoding: 'utf8' })   // the app's own tinker: the flow script only SELECTs by itself
  const variantAdjust = (id, qty) => artisan(`App\\Domain\\Catalog\\Models\\ProductVariant::whereKey(${id})->update(['stock_qty' => ${qty}]);`)
  const money = (n) => Math.round(Number(n) * 100) / 100
  const phoneN = (n) => `+92 347 ${String(1000000 + n).slice(1)}`
  await setSettings({ currency_code: 'USD', currency_symbol: '$', currency_decimals: 2, tax_rate: 0, tax_inclusive: true, delivery_method: 'free_above', delivery_fee: 5, free_delivery_min_cart: 0, min_order_amount: 0, cod_max_amount: 0, delivery_unmatched: 'block', store_open: true }, D)

  section('F17', 'Theme (developer only), Store tab, agency credit, Payments tab')
  const tTabs = tabKeys(await get('admin/settings', D))
  check(tTabs.includes('theme') && !tabKeys(await get('admin/settings', A)).includes('theme'), 'the Theme tab is the developer\'s: the admin does not get it', tTabs)
  check((await put('admin/settings', { theme_primary: '#ABCDEF' }, A)).status === 403, 'admin PUT theme_primary -> 403')
  check((await put('admin/access-control/admin', { permissions: [...ADMIN_DEFAULTS, 'settings.theme'] }, D)).status === 422, 'the developer cannot give settings.theme to the admin (422)')
  const th = await put('admin/settings', { theme_primary: '#112233', theme_accent: '#FF8800', theme_font_heading: 'Playfair Display', theme_radius: '12px', theme_shadow: 'medium' }, D)
  const pubTheme = (await pub()).theme
  check(th.status === 200 && pubTheme.primary === '#112233' && pubTheme.accent === '#FF8800' && pubTheme.font_heading === 'Playfair Display' && pubTheme.radius === '12px' && pubTheme.shadow === 'medium', 'the developer\'s theme reaches GET /store/settings', pubTheme)
  check([(await put('admin/settings', { theme_primary: 'red' }, D)).status, (await put('admin/settings', { theme_font_body: 'Comic Sans' }, D)).status, (await put('admin/settings', { theme_radius: '5px' }, D)).status].every((s) => s === 422), 'bad colour, font outside the list and bad radius -> 422')
  await put('admin/settings', { theme_primary: '#2563EB', theme_accent: '#F59E0B', theme_font_heading: 'Inter', theme_radius: '8px', theme_shadow: 'soft' }, D)

  await setSettings({ store_open: false, store_closed_message: 'We are closed until Monday.', checkout_notice: 'Orders before noon ship today.', delivery_eta_text: '2 to 4 days' })
  const fp = await mk('Flow Phase A Item', 'FPA-1', 100)
  const closedPub = await pub()
  const closedQuote = await quote([[fp.variant, 1]])
  const ordersBefore = dbOne('SELECT COUNT(*) n FROM orders').n
  const closedOrder = await placeOrder(orderBody([[fp.variant, 1]], { name: 'Closed', phone: phoneN(1) }))
  check(closedPub.store.open === false && closedPub.store.closed_message === 'We are closed until Monday.' && closedPub.checkout.notice === 'Orders before noon ship today.' && closedPub.checkout.eta_text === '2 to 4 days', 'Store tab -> public store.open, closed message, checkout notice and ETA text', { store: closedPub.store, checkout: closedPub.checkout })
  check(closedQuote.status === 200 && closedQuote.json.data.can_checkout === true && closedOrder.status === 422 && closedOrder.json?.code === 'store_closed' && closedOrder.json?.message === 'We are closed until Monday.', 'a closed store still quotes, but POST /orders -> 422 store_closed with the message', status(closedOrder).slice(0, 160))
  check(dbOne('SELECT COUNT(*) n FROM orders').n === ordersBefore && variantRow(fp.variant).stock_qty === 100, 'and nothing was stored or deducted')
  await setSettings({ store_open: true })

  const badAgency = await setSettings({ agency_name: 'Example Agency', agency_url: 'http://agency.example' })
  const goodAgency = await setSettings({ agency_name: 'Example Agency', agency_url: 'https://agency.example' })
  check(badAgency.status === 422 && goodAgency.status === 200 && JSON.stringify((await pub()).footer.agency) === JSON.stringify({ name: 'Example Agency', url: 'https://agency.example' }), 'agency credit: https only (422 for http), then public footer.agency', (await pub()).footer.agency)
  await setSettings({ agency_name: '', agency_url: '' })

  const noMethod = await setSettings({ pay_cod_enabled: false })
  const bankNoAccount = await setSettings({ pay_bank_enabled: true })
  const badAccount = await setSettings({ pay_bank_accounts: [{ bank: 'X', number: '', iban: '' }] })
  check(noMethod.status === 422 && bankNoAccount.status === 422 && badAccount.status === 422, 'payments: at least one method stays on, bank needs an active account, an account needs a number or IBAN (all 422)', [noMethod.status, bankNoAccount.status, badAccount.status])
  const bankOn = await setSettings({ pay_bank_enabled: true, pay_card_visible: true, pay_bank_instructions: 'Use your order number as the reference.', pay_bank_accounts: [{ bank: 'Flow Bank', title: 'Flow Shop', number: '0123456789', iban: 'PK36SCBL0000001123456702', active: true }, { bank: 'Hidden Bank', number: '999', active: false }] })
  const payPub = (await pub()).payment
  check(bankOn.status === 200 && payPub.cod.enabled === true && payPub.bank_transfer.enabled === true && payPub.bank_transfer.accounts.length === 1 && payPub.bank_transfer.accounts[0].bank === 'Flow Bank' && payPub.card.visible === true && payPub.card.coming_soon === true, 'bank transfer on: public payment block with the active account only; card only a "coming soon" label', payPub)

  section('F18', 'Delivery methods, zones (matching, order, free-above, minimum), unmatched address, pending fee')
  const dz = await mk('Flow Zone Item', 'FZ-1', 100)
  const lahore = await post('admin/delivery-zones', { name: 'Lahore', match_type: 'city', match_values: ['lahore', ' Faisalabad '], fee: 6, free_above: 150, min_order: 10, eta_text: 'Tomorrow' }, A)
  const karachi = await post('admin/delivery-zones', { name: 'Karachi', match_type: 'postcode', match_values: ['75*', '74000'], fee: 12 }, A)
  check(lahore.status === 201 && karachi.status === 201 && JSON.stringify(lahore.json.data.match_values) === JSON.stringify(['lahore', 'Faisalabad']), 'admin creates a city zone and a postcode zone (values trimmed)', status(lahore).slice(0, 200))
  check((await post('admin/delivery-zones', { name: '', match_type: 'country', match_values: [], fee: -1 }, A)).status === 422, 'a zone without a name / with a bad type / no values / negative fee -> 422')
  await setSettings({ delivery_method: 'zones' })
  const zq = (address) => post('checkout/quote', { items: [{ variant_id: dz.variant, qty: 1 }], ...(address ? { address } : {}) }, undefined, { base: STORE_BASE })
  const noAddr = (await zq()).json.data.summary
  const inLahore = (await zq({ city: '  LAHORE ' })).json.data
  const inKarachi = (await zq({ city: 'Karachi', postcode: '75300' })).json.data.summary
  const outside = (await zq({ city: 'Nowhere', postcode: '99999' })).json.data
  check(noAddr.delivery_fee === null && noAddr.delivery_needs_address === true, 'zones: no address -> delivery_fee null, delivery_needs_address true', { fee: noAddr.delivery_fee, needs: noAddr.delivery_needs_address })
  check(inLahore.summary.delivery_fee === 6 && inLahore.summary.delivery_zone?.name === 'Lahore' && inLahore.summary.delivery_eta_text === 'Tomorrow' && inLahore.summary.grand_total === 106, 'city zone matches case-insensitively: fee 6, zone and ETA, total 106', inLahore.summary.delivery_zone)
  check(inKarachi.delivery_fee === 12 && inKarachi.delivery_zone?.name === 'Karachi', 'postcode prefix 75* matches 75300: fee 12', inKarachi.delivery_zone)
  check(outside.can_checkout === false && outside.issues?.some((i) => i.code === 'delivery_not_available') && outside.summary.delivery_fee === null, 'an address outside every zone: delivery_not_available, no fee (default: block)', outside.issues)
  const big = (await post('checkout/quote', { items: [{ variant_id: dz.variant, qty: 2 }], address: { city: 'Lahore' } }, undefined, { base: STORE_BASE })).json.data.summary
  check(big.delivery_free === true && big.delivery_fee === 0 && big.grand_total === 200, 'the zone\'s own free-above amount (150): 200 ships free', { fee: big.delivery_fee, free: big.delivery_free })
  const zoneOrder = await placeOrder(orderBody([[dz.variant, 1]], { name: 'Zone Buyer', phone: phoneN(2) }))
  check(zoneOrder.status === 201 && zoneOrder.json.data.totals.delivery_fee === 6 && zoneOrder.json.data.grand_total === 106 && dbOne(`SELECT delivery_zone_id z FROM orders WHERE order_number = '${zoneOrder.json.data.order_number}'`).z === lahore.json.data.id, 'an order to Lahore takes the zone fee (6) and remembers the zone', status(zoneOrder).slice(0, 120))
  const blocked = await placeOrder(orderBody([[dz.variant, 1]], { name: 'Nowhere', phone: phoneN(3) }, { address: { ...ADDRESS, city: 'Nowhere', postcode: '99999' } }))
  check(blocked.status === 422 && blocked.json?.code === 'delivery_not_available' && variantRow(dz.variant).stock_qty === 99, 'an order outside every zone -> 422 delivery_not_available, stock untouched', status(blocked).slice(0, 140))
  const minZone = await put(`admin/delivery-zones/${karachi.json.data.id}`, { name: 'Karachi', match_type: 'postcode', match_values: ['75*'], fee: 12, min_order: 300 }, A)
  const karachiMin = (await zq({ postcode: '75300' })).json.data
  check(minZone.status === 200 && karachiMin.can_checkout === false && karachiMin.issues?.some((i) => i.code === 'below_min_order'), 'a zone minimum order (300) replaces the store minimum: 100 is too little for Karachi', karachiMin.issues)
  const order1 = await put('admin/delivery-zones/reorder', { ids: [karachi.json.data.id, lahore.json.data.id] }, A)
  const listed = (await get('admin/delivery-zones', A)).json.data.map((z) => z.name)
  check(order1.status === 200 && JSON.stringify(listed) === JSON.stringify(['Karachi', 'Lahore']), 'zones can be reordered', listed)
  await patch(`admin/delivery-zones/${lahore.json.data.id}/active`, { is_active: false }, A)
  check((await zq({ city: 'Lahore' })).json.data.summary.delivery_zone === null, 'a deactivated zone stops matching at once')
  await patch(`admin/delivery-zones/${lahore.json.data.id}/active`, { is_active: true }, A)

  await setSettings({ delivery_unmatched: 'owner_confirms' })
  const pendQuote = (await zq({ city: 'Nowhere' })).json.data.summary
  check(pendQuote.delivery_pending === true && pendQuote.delivery_fee === null, 'unmatched address with owner_confirms: delivery_pending true, fee null', pendQuote)
  const pend = await placeOrder(orderBody([[dz.variant, 1]], { name: 'Pending Fee', phone: phoneN(4) }, { address: { ...ADDRESS, city: 'Nowhere' } }))
  const pendNo = pend.json?.data?.order_number
  const pendId = dbOne(`SELECT id FROM orders WHERE order_number = '${pendNo}'`)?.id
  check(pend.status === 201 && pend.json.data.delivery_fee_pending === true && pend.json.data.totals.delivery_fee === null && pend.json.data.grand_total === 100, 'the order is placed with the fee unknown (delivery_fee_pending, total without delivery)', status(pend).slice(0, 140))
  const cNo = await post(`admin/orders/${pendId}/status`, { status: 'confirmed' }, A)
  check(cNo.status === 422 && cNo.json?.code === 'delivery_fee_pending', 'confirming is blocked: 422 delivery_fee_pending', status(cNo).slice(0, 140))
  const feeSet = await put(`admin/orders/${pendId}/delivery-fee`, { fee: 20 }, A)
  const feeRow = dbOne(`SELECT shipping_fee, grand_total, delivery_fee_pending p FROM orders WHERE id = ${pendId}`)
  check(feeSet.status === 200 && money(feeRow.shipping_fee) === 20 && money(feeRow.grand_total) === 120 && feeRow.p === 0, 'the admin sets the fee: total recalculated (100 + 20), pending cleared', feeRow)
  const trackAfter = await lookup(pendNo, phoneN(4))
  check(trackAfter.json?.data?.delivery_fee_pending === false && trackAfter.json?.data?.grand_total === 120 && trackAfter.json?.data?.totals?.delivery_fee === 20, 'the customer now sees the final total', { pending: trackAfter.json?.data?.delivery_fee_pending, total: trackAfter.json?.data?.grand_total })
  check((await post(`admin/orders/${pendId}/status`, { status: 'confirmed' }, A)).status === 200 && (await put(`admin/orders/${pendId}/delivery-fee`, { fee: 1 }, A)).status === 422, 'the order can be confirmed now; the fee cannot be set twice (422)')
  check(db("SELECT id FROM audit_logs WHERE action IN ('order.delivery_fee_set', 'delivery_zone.created', 'delivery_zone.reordered')").length >= 3, 'zone and fee changes are in the audit log')

  await setSettings({ delivery_method: 'owner_confirms' })
  const ownerQuote = (await quote([[dz.variant, 1]])).json.data.summary
  check(ownerQuote.delivery_pending === true && ownerQuote.delivery_fee === null, 'method owner_confirms: every quote is pending', ownerQuote.delivery_method)
  await setSettings({ delivery_method: 'flat', delivery_fee: 7, free_delivery_min_cart: 50 })
  const flatQ = (await quote([[dz.variant, 1]])).json.data.summary
  await setSettings({ delivery_method: 'free' })
  const freeQ = (await quote([[dz.variant, 1]])).json.data.summary
  await setSettings({ delivery_method: 'free_above', delivery_fee: 5, free_delivery_min_cart: 100 })
  const aboveQ = (await quote([[dz.variant, 1]])).json.data.summary
  check(flatQ.delivery_fee === 7 && flatQ.delivery_free === false && freeQ.delivery_fee === 0 && freeQ.delivery_free === true && aboveQ.delivery_free === true, 'flat ignores the free-above amount, free is always free, free_above is free from 100', { flat: flatQ.delivery_fee, free: freeQ.delivery_fee, above: aboveQ.delivery_fee })
  await setSettings({ delivery_method: 'free_above', delivery_fee: 5, free_delivery_min_cart: 0, delivery_unmatched: 'block' })

  section('F19', 'Bank transfer: order, private proof upload, wrong-phone 404, reject / re-upload, verify, confirm')
  const bp = await mk('Flow Bank Item', 'FBK-1', 50)
  const bankBody = (n, name) => orderBody([[bp.variant, 1]], { name, phone: phoneN(n) }, { payment_method: 'bank_transfer' })
  const bankOrder1 = await placeOrder(bankBody(10, 'Bank Buyer'))
  const bNo = bankOrder1.json?.data?.order_number
  const bId = dbOne(`SELECT id FROM orders WHERE order_number = '${bNo}'`)?.id
  const bPay = dbOne(`SELECT method, status, proof_status FROM payments WHERE payable_id = ${bId}`)
  check(bankOrder1.status === 201 && bankOrder1.json.data.payment_status === 'awaiting_payment' && bPay.status === 'awaiting_proof' && bPay.proof_status === 'none', 'a bank transfer order is awaiting_payment / awaiting_proof', { order: bankOrder1.json?.data?.payment_status, pay: bPay })
  check(bankOrder1.json.data.payment.accounts?.[0]?.bank === 'Flow Bank' && bankOrder1.json.data.payment.can_upload_proof === true && bankOrder1.json.data.payment.instructions.startsWith('Use your order'), 'the answer carries the accounts and instructions for the customer', bankOrder1.json?.data?.payment)
  const unknownProof = await storeCall('POST', 'orders/ORD-2099-999999/payment-proof?phone=%2B923470000010', { form: formOf({}, { proof: file('p.png', 300, 300, [10, 120, 200]) }) })
  const wrongProof = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(99))}`, { form: formOf({}, { proof: file('p.png', 300, 300, [10, 120, 200]) }) })
  check(wrongProof.status === 404 && unknownProof.status === 404 && wrongProof.json?.message === unknownProof.json?.message, 'a wrong phone is the same 404 as an unknown order', [wrongProof.status, unknownProof.status])
  const tiny = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(10))}`, { form: formOf({}, { proof: file('t.png', 50, 50, [1, 2, 3]) }) })
  const notPic = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(10))}`, { form: formOf({}, { proof: new File(['hello'], 'p.txt', { type: 'text/plain' }) }) })
  check(tiny.status === 422 && notPic.status === 422, 'a 50 px picture and a text file are refused (422)', [tiny.status, notPic.status])
  const up = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(10))}`, { form: formOf({ reference: 'TX-FLOW-1' }, { proof: file('p.png', 300, 300, [10, 120, 200]) }) })
  const media = dbOne(`SELECT disk, file_name FROM media WHERE model_type LIKE '%Payment' AND model_id = (SELECT id FROM payments WHERE payable_id = ${bId})`)
  check(up.status === 200 && up.json.data.payment.proof_status === 'submitted' && media?.disk === 'local' && !JSON.stringify(up.json).includes(media.file_name || 'x?x'), 'the proof is accepted and stored on the PRIVATE disk; the answer never names the file', { proof: up.json?.data?.payment?.proof_status, disk: media?.disk })
  const second = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(10))}`, { form: formOf({}, { proof: file('p2.png', 300, 300, [200, 10, 10]) }) })
  check(second.status === 422 && second.json?.code === 'proof_already_submitted', 'a second proof while one is being checked -> 422 proof_already_submitted', status(second).slice(0, 120))
  const proofAnon = await get(`admin/orders/${bId}/payment/proof`)
  const proofDev = await get(`admin/orders/${bId}/payment/proof`, D)
  const proofAdm = await get(`admin/orders/${bId}/payment/proof`, A)
  check(proofAnon.status === 401 && proofDev.status === 403 && proofAdm.status === 200 && /image\//.test(proofAdm.headers.get('content-type') || '') && /no-store/.test(proofAdm.headers.get('cache-control') || ''), 'the proof opens only through the authenticated admin route (guest 401, developer 403, admin 200, no-store)', [proofAnon.status, proofDev.status, proofAdm.status])
  check(proofAdm.headers.get('content-type') && !(await fetch(`${BASE}/storage/${media?.file_name}`).then((r) => r.ok)), 'and there is no public /storage URL for it')
  const confirmEarly = await post(`admin/orders/${bId}/status`, { status: 'confirmed' }, A)
  check(confirmEarly.status === 422 && confirmEarly.json?.code === 'payment_not_verified', 'confirming before verification -> 422 payment_not_verified', status(confirmEarly).slice(0, 120))
  const noReason = await post(`admin/orders/${bId}/payment/reject-proof`, {}, A)
  const rej = await post(`admin/orders/${bId}/payment/reject-proof`, { reason: 'The amount is not visible' }, A)
  const trk = await lookup(bNo, phoneN(10))
  check(noReason.status === 422 && rej.status === 200 && trk.json.data.payment.proof_status === 'rejected' && trk.json.data.payment.reject_reason === 'The amount is not visible' && trk.json.data.payment.can_upload_proof === true && trk.json.data.status === 'pending', 'reject-proof needs a reason; the customer sees it and may upload again; the order stays pending', trk.json?.data?.payment)
  const again = await storeCall('POST', `orders/${bNo}/payment-proof?phone=${encodeURIComponent(phoneN(10))}`, { form: formOf({ reference: 'TX-FLOW-2' }, { proof: file('p3.png', 320, 320, [10, 200, 10]) }) })
  check(again.status === 200 && dbOne(`SELECT COUNT(*) n FROM media WHERE model_type LIKE '%Payment' AND model_id = (SELECT id FROM payments WHERE payable_id = ${bId})`).n === 1, 'a new proof replaces the old picture (one file kept)', status(again).slice(0, 100))
  const ver = await post(`admin/orders/${bId}/payment/verify`, {}, A)
  const verRow = dbOne(`SELECT o.payment_status ps, p.status pst, p.proof_status pr, p.verified_by vb FROM orders o JOIN payments p ON p.payable_id = o.id WHERE o.id = ${bId}`)
  check(ver.status === 200 && verRow.ps === 'paid' && verRow.pst === 'paid' && verRow.pr === 'verified' && verRow.vb, 'verify: payment and order are paid, who verified it is recorded', verRow)
  check((await post(`admin/orders/${bId}/payment/verify`, {}, A)).status === 422, 'verifying twice -> 422')
  check((await post(`admin/orders/${bId}/status`, { status: 'confirmed' }, A)).status === 200, 'now the order can be confirmed')
  const noProofOrder = await placeOrder(bankBody(11, 'No Proof'))
  const npId = dbOne(`SELECT id FROM orders WHERE order_number = '${noProofOrder.json?.data?.order_number}'`)?.id
  check((await post(`admin/orders/${npId}/payment/verify`, {}, A)).status === 422, 'an order without a proof cannot be verified (422)')
  const codB = await placeOrder(orderBody([[bp.variant, 1]], { name: 'Cod Again', phone: phoneN(12) }))
  check(codB.status === 201 && codB.json.data.payment_method === 'cod' && codB.json.data.payment_status === 'cod_pending', 'cash on delivery is unchanged (default method)', codB.json?.data?.payment_status)
  await setSettings({ pay_bank_enabled: false })
  const offBank = await placeOrder(bankBody(13, 'Bank Off'))
  check(offBank.status === 422 && offBank.json?.code === 'payment_method_unavailable', 'with bank transfer switched off -> 422 payment_method_unavailable', status(offBank).slice(0, 120))
  await setSettings({ pay_bank_enabled: true })
  const cardTry = await placeOrder(orderBody([[bp.variant, 1]], { name: 'Card', phone: phoneN(14) }, { payment_method: 'card' }))
  check(cardTry.status === 422, 'card can never be chosen (422)', status(cardTry).slice(0, 100))
  const devPay = await Promise.all([post(`admin/orders/${bId}/payment/verify`, {}, D), post(`admin/orders/${bId}/payment/reject-proof`, { reason: 'x' }, D), put(`admin/orders/${bId}/delivery-fee`, { fee: 1 }, D)])
  check(devPay.every((r) => r.status === 403), 'the developer is blocked from every payment endpoint (403)', devPay.map((r) => r.status))

  section('F20', 'Discounts and coupons: engine, best one wins, usage counter, the last use across two PHP processes, release on cancel')
  const dp = await mk('Flow Promo Item', 'FPR-1', 100)
  const disc = await post('admin/discounts', { name: 'Flow ten', type: 'percent', value: 10, scope: 'products', target_ids: [dp.id] }, A)
  const q1 = (await quote([[dp.variant, 1]])).json.data
  check(disc.status === 201 && q1.items[0].discount_amount === 10 && q1.items[0].discount_source === 'discount' && q1.summary.discount_total === 10 && q1.summary.grand_total === 95, 'a 10 % automatic discount on the product: 90 + 5 delivery', q1.summary)
  const card = (await store('products?per_page=100')).json.data.find((c) => c.id === dp.id)
  check(card.price === 90 && card.compare_price === 100 && card.discount_label === '10% off', 'the product card shows the discounted price, the old price and the label', { price: card.price, compare: card.compare_price, label: card.discount_label })
  const cp = await post('admin/coupons', { code: 'flow20', name: 'Flow twenty', type: 'percent', value: 20, scope: 'store', usage_limit: 1 }, A)
  const dupCp = await post('admin/coupons', { code: 'FLOW20', name: 'Dup', type: 'percent', value: 5, scope: 'store' }, A)
  check(cp.status === 201 && cp.json.data.code === 'FLOW20' && dupCp.status === 422, 'coupon codes are stored in capitals and unique whatever the case', [cp.json?.data?.code, dupCp.status])
  const q2 = (await post('checkout/quote', { items: [{ variant_id: dp.variant, qty: 1 }], coupon_code: 'flow20' }, undefined, { base: STORE_BASE })).json.data
  check(q2.summary.coupon_code === 'FLOW20' && q2.summary.coupon_discount === 20 && q2.summary.discount_total === 0 && q2.items[0].discount_source === 'coupon', 'the 20 % coupon beats the 10 % discount on the line (never both)', q2.summary)
  const q3 = (await post('checkout/quote', { items: [{ variant_id: dp.variant, qty: 1 }], coupon_code: 'NOPE' }, undefined, { base: STORE_BASE })).json.data
  check(q3.summary.coupon_error?.code === 'not_found' && q3.can_checkout === true, 'a wrong code is explained in the quote and does not block it', q3.summary.coupon_error)
  const val = await storePost('coupons/validate', { code: 'NOPE', items: [{ variant_id: dp.variant, qty: 1 }] })
  check(val.status === 422 && val.json?.code === 'coupon_invalid' && val.json?.coupon_error?.code === 'not_found', 'POST /coupons/validate with a wrong code -> 422 coupon_invalid', status(val).slice(0, 140))
  const badCoupon = await placeOrder(orderBody([[dp.variant, 1]], { name: 'Bad Code', phone: phoneN(20) }, { coupon_code: 'NOPE' }))
  check(badCoupon.status === 422 && badCoupon.json?.code === 'coupon_invalid' && badCoupon.json?.reason === 'not_found', 'the ORDER is refused for a wrong code (422 coupon_invalid)', status(badCoupon).slice(0, 140))
  const stockBefore = variantRow(dp.variant).stock_qty
  const used1 = await placeOrder(orderBody([[dp.variant, 1]], { name: 'Coupon Buyer', phone: phoneN(21) }, { coupon_code: 'FLOW20' }))
  const used1Id = dbOne(`SELECT id FROM orders WHERE order_number = '${used1.json?.data?.order_number}'`)?.id
  const cRow = dbOne(`SELECT used_count FROM coupons WHERE code = 'FLOW20'`)
  check(used1.status === 201 && used1.json.data.totals.coupon_discount === 20 && used1.json.data.grand_total === 85 && cRow.used_count === 1 && dbOne(`SELECT COUNT(*) n FROM coupon_usages WHERE order_id = ${used1Id}`).n === 1, 'the order uses the coupon: total 85, used_count 1, one usage row', { total: used1.json?.data?.grand_total, used: cRow.used_count })
  const used2 = await placeOrder(orderBody([[dp.variant, 1]], { name: 'Too Late', phone: phoneN(22) }, { coupon_code: 'FLOW20' }))
  check(used2.status === 422 && used2.json?.reason === 'usage_limit' && variantRow(dp.variant).stock_qty === stockBefore - 1, 'a second order on the used-up coupon -> 422 usage_limit, no stock taken', status(used2).slice(0, 140))
  const delUsed = await del(`admin/coupons/${cp.json.data.id}`, A)
  check(delUsed.status === 422 && delUsed.json?.code === 'coupon_has_usages', 'a coupon that was used cannot be deleted (422 coupon_has_usages)', status(delUsed).slice(0, 120))
  const usagesA = await get(`admin/coupons/${cp.json.data.id}/usages`, A)
  const usagesD = await get(`admin/coupons/${cp.json.data.id}/usages`, D)
  check(usagesA.json?.data?.[0]?.order_number === used1.json?.data?.order_number && usagesD.status === 200 && !JSON.stringify(usagesD.json).includes(used1.json.data.order_number) && !('order_id' in (usagesD.json.data[0] || {})), 'the usage list shows the order number to the admin; the developer sees dates and amounts only', [usagesA.json?.data?.[0]?.order_number, Object.keys(usagesD.json?.data?.[0] || {})])
  const relCancel = await post(`admin/orders/${used1Id}/status`, { status: 'cancelled', reason: 'Flow: give the coupon back' }, A)
  const afterCancel = dbOne(`SELECT used_count FROM coupons WHERE code = 'FLOW20'`)
  check(relCancel.status === 200 && afterCancel.used_count === 0 && dbOne(`SELECT released_at r FROM coupon_usages WHERE order_id = ${used1Id}`).r && variantRow(dp.variant).stock_qty === stockBefore, 'cancelling the order gives the coupon use and the stock back', { used: afterCancel.used_count, stock: variantRow(dp.variant).stock_qty })

  // the last use of a coupon, 4 simultaneous orders over two PHP processes
  const raceCp = await post('admin/coupons', { code: 'FLOWRACE', name: 'Race', type: 'percent', value: 30, scope: 'store', usage_limit: 1 }, A)
  const rp = await mk('Flow Race Item', 'FRC-1', 100)
  await reserve(4)
  const raced = await Promise.all([0, 1, 2, 3].map((i) => placeOrder(orderBody([[rp.variant, 1]], { name: `Coupon Racer ${i}`, phone: phoneN(30 + i) }, { coupon_code: 'FLOWRACE' }), { base: i % 2 ? BASE2 : BASE, noThrottle: true })))
  const wins = raced.filter((r) => r.status === 201)
  const losses = raced.filter((r) => r.status === 422 && r.json?.code === 'coupon_invalid')
  const raceRow = dbOne(`SELECT used_count FROM coupons WHERE code = 'FLOWRACE'`)
  check(raceCp.status === 201 && wins.length === 1 && losses.length === 3 && raceRow.used_count === 1 && dbOne(`SELECT COUNT(*) n FROM coupon_usages WHERE coupon_id = ${raceCp.json.data.id}`).n === 1 && variantRow(rp.variant).stock_qty === 99, 'the last use across two PHP processes: exactly one 201, three 422 coupon_invalid, used_count 1, stock 99', raced.map((r) => `${r.status}:${r.json?.reason || ''}`))
  const winner = wins[0]?.json?.data
  check(winner && winner.totals.coupon_discount === 30 && winner.grand_total === 75, 'the winner got the discount (30 off 100, + 5 delivery)', winner?.totals)

  section('F21', 'Persistent cart: token, lines, live re-check, quote and order by cart_token, expiry and prune, rate limit')
  const cartCall = (method, route = '', { token, body } = {}) => storeCall(method, `cart${route}`, { headers: token ? { 'X-Cart-Token': token } : {}, body })
  const cartA = await cartCall('GET')
  const tok = cartA.json?.data?.token
  check(cartA.status === 200 && tok?.length === 40 && cartA.headers.get('x-cart-token') === tok && cartA.json.data.items.length === 0, 'GET /cart without a token creates a cart: 40-character token in data.token and X-Cart-Token', { token: tok?.length })
  const cp1 = await mk('Flow Cart Item', 'FCT-1', 100, { })
  await cartCall('POST', '/items', { token: tok, body: { variant_id: cp1.variant, qty: 2 } })
  const addOn = await cartCall('POST', '/items', { token: tok, body: { variant_id: cp1.variant, qty: 1 } })
  check(addOn.json?.data?.items?.[0]?.qty === 3 && addOn.json.data.summary.subtotal === 300, 'POST /cart/items adds to the quantity (2 + 1 = 3); the cart is priced on the server', addOn.json?.data?.summary?.subtotal)
  const setQ = await cartCall('PUT', `/items/${cp1.variant}`, { token: tok, body: { qty: 2 } })
  check(setQ.json?.data?.items?.[0]?.qty === 2, 'PUT /cart/items/{id} sets the quantity')
  const persisted = await cartCall('GET', '', { token: tok })
  check(persisted.json?.data?.token === tok && persisted.json.data.items.length === 1, 'the cart is still there on the next request (the token is the only key)')
  const contact = await cartCall('PUT', '/contact', { token: tok, body: { name: 'Cart Person', phone: '0300 1234500', email: 'Cart@Example.test' } })
  check(contact.status === 200 && contact.json.data.contact.email === 'cart@example.test' && contact.json.data.contact.name === 'Cart Person', 'PUT /cart/contact saves the contact (e-mail lower-cased)', contact.json?.data?.contact)
  const cartCoupon = await post('admin/coupons', { code: 'FLOWCART', name: 'Cart coupon', type: 'percent', value: 10, scope: 'store' }, A)
  const badC = await cartCall('PUT', '/coupon', { token: tok, body: { code: 'NOPE' } })
  const goodC = await cartCall('PUT', '/coupon', { token: tok, body: { code: 'flowcart' } })
  check(cartCoupon.status === 201 && badC.status === 422 && goodC.json?.data?.summary?.coupon_discount === 20, 'PUT /cart/coupon: a wrong code is refused (422) and not saved, a good one is applied (20 off 200)', goodC.json?.data?.summary)
  const qCart = await storePost('checkout/quote', { cart_token: tok })
  check(qCart.status === 200 && qCart.json.data.summary.coupon_code === 'FLOWCART' && qCart.json.data.items[0].qty === 2, 'POST /checkout/quote accepts cart_token (the cart\'s coupon comes with it)', qCart.json?.data?.summary?.coupon_code)
  variantAdjust(cp1.variant, 1)
  const stale = await cartCall('GET', '', { token: tok })
  check(stale.json?.data?.can_checkout === false && stale.json.data.items[0].warnings?.some((w) => w.code === 'insufficient_stock' && w.blocking) && stale.json.data.items.length === 1, 'stock changed under the cart: the line stays with a blocking insufficient_stock warning', stale.json?.data?.items?.[0]?.warnings)
  variantAdjust(cp1.variant, 100)
  const cartOrder = await placeOrder({ customer: { name: 'Cart Person', phone: phoneN(40) }, address: ADDRESS, cart_token: tok })
  const cartLeft = dbOne(`SELECT (SELECT COUNT(*) FROM cart_items WHERE cart_id = (SELECT id FROM carts WHERE token = '${tok}')) items, (SELECT coupon_code FROM carts WHERE token = '${tok}') coupon`)
  check(cartOrder.status === 201 && cartOrder.json.data.items_count === 2 && cartOrder.json.data.totals.coupon_discount === 20 && cartLeft.items === 0 && cartLeft.coupon === null, 'POST /orders with cart_token: order from the cart, the cart\'s coupon applied, the cart emptied in the same transaction', { items: cartLeft.items, coupon: cartLeft.coupon, total: cartOrder.json?.data?.grand_total })
  const cartAgain = await placeOrder({ customer: { name: 'Cart Person', phone: phoneN(41) }, address: ADDRESS, cart_token: tok })
  check(cartAgain.status === 422 && cartAgain.json?.code === 'cart_invalid', 'the empty cart cannot be ordered again (422 cart_invalid)', status(cartAgain).slice(0, 120))
  const other = (await cartCall('GET')).json.data.token
  const garbage = await cartCall('GET', '', { token: 'x'.repeat(40) })
  check(other !== tok && garbage.json?.data?.token !== 'x'.repeat(40) && garbage.json.data.items.length === 0, 'another browser and an unknown token each get their own, new cart')
  const old = (await cartCall('GET')).json.data.token
  await cartCall('POST', '/items', { token: old, body: { variant_id: cp1.variant, qty: 1 } })
  artisan(`App\\Domain\\Cart\\Models\\Cart::where('token', '${old}')->update(['expires_at' => now()->subDay()]);`)
  const expired = await cartCall('GET', '', { token: old })
  check(expired.json?.data?.token !== old && expired.json.data.items.length === 0, 'a cart past its 30 days is gone: the old token starts a new, empty cart', expired.json?.data?.token === old)
  const before = dbOne('SELECT COUNT(*) n FROM carts').n
  const pruned = execFileSync('php', ['artisan', 'shop:prune-carts'], { cwd: ROOT, encoding: 'utf8' })
  const after = dbOne('SELECT COUNT(*) n FROM carts').n
  check(/Deleted [1-9]\d* expired cart/.test(pruned) && after < before && dbOne(`SELECT COUNT(*) n FROM carts WHERE token = '${old}'`).n === 0, 'php artisan shop:prune-carts deletes expired carts (with their lines)', pruned.trim())
  check(dbOne('SELECT COUNT(*) n FROM cart_items WHERE cart_id NOT IN (SELECT id FROM carts)').n === 0 && execFileSync('php', ['artisan', 'schedule:list'], { cwd: ROOT, encoding: 'utf8' }).includes('shop:prune-carts'), 'no orphan cart lines, and the command is on the schedule')
  await sleep(61000)   // a fresh minute for the 60-a-minute cart limit
  let last = 200
  for (let i = 0; i < 62 && last !== 429; i++) last = (await cartCall('GET')).status
  check(last === 429, 'the cart calls are limited to 60 a minute per IP (429)', last)

  section('F22', 'Order changes: reject with a reason, the admin_note fix, list filters and counts, developer blocked from new order endpoints')
  const rp1 = await mk('Flow Reject Item', 'FRJ-1', 40)
  const rejOrder = await placeOrder(orderBody([[rp1.variant, 3]], { name: 'To Reject', phone: phoneN(50) }))
  const rNo = rejOrder.json?.data?.order_number
  const rId = dbOne(`SELECT id FROM orders WHERE order_number = '${rNo}'`)?.id
  check(rejOrder.status === 201 && variantRow(rp1.variant).stock_qty === 97, 'an order for 3 units takes the stock (97 left)', variantRow(rp1.variant))
  const rejNoReason = await post(`admin/orders/${rId}/status`, { status: 'rejected' }, A)
  const rejOk = await post(`admin/orders/${rId}/status`, { status: 'rejected', reason: 'We cannot deliver to that area' }, A)
  const rejCustomer = dbOne(`SELECT orders_count FROM customers WHERE phone = '${phoneN(50).replace(/ /g, '')}'`)
  check(rejNoReason.status === 422 && rejOk.status === 200 && rejOk.json.data.status === 'rejected' && variantRow(rp1.variant).stock_qty === 100 && rejCustomer.orders_count === 0, 'reject needs a reason; the order is rejected, the stock is back (100), the customer totals are reversed', { stock: variantRow(rp1.variant).stock_qty, orders: rejCustomer.orders_count })
  const rejTrack = await lookup(rNo, phoneN(50))
  check(rejTrack.json?.data?.status === 'rejected' && rejTrack.json.data.reject_reason === 'We cannot deliver to that area' && rejTrack.json.data.timeline.at(-1).note.includes('We cannot deliver'), 'the customer sees "rejected" with the reason on the tracking answer', { status: rejTrack.json?.data?.status, reason: rejTrack.json?.data?.reject_reason })
  const rejAgain = await post(`admin/orders/${rId}/status`, { status: 'cancelled', reason: 'x' }, A)
  check(rejAgain.status === 422 && rejAgain.json?.code === 'invalid_status_change', 'a rejected order is final (422)', status(rejAgain).slice(0, 100))
  const rej2 = await placeOrder(orderBody([[rp1.variant, 1]], { name: 'Confirmed One', phone: phoneN(51) }))
  const r2Id = dbOne(`SELECT id FROM orders WHERE order_number = '${rej2.json?.data?.order_number}'`)?.id
  await post(`admin/orders/${r2Id}/status`, { status: 'confirmed' }, A)
  const lateReject = await post(`admin/orders/${r2Id}/status`, { status: 'rejected', reason: 'Too late' }, A)
  check(lateReject.status === 422 && lateReject.json?.code === 'invalid_status_change', 'only a pending order can be rejected (422 on a confirmed one)', status(lateReject).slice(0, 100))
  const listRej = await get('admin/orders?status=rejected&per_page=100', A)
  const listCounts = await get('admin/orders?payment_method=cod', A)
  check(listRej.json?.data?.some((o) => o.order_number === rNo) && listRej.json.data.every((o) => o.status === 'rejected') && typeof listCounts.json?.counts?.rejected === 'number', 'the order list filters by status=rejected and its counts include rejected', listCounts.json?.counts)
  const bankFilter = await get('admin/orders?payment_method=bank_transfer&per_page=100', A)
  const awaitFilter = await get('admin/orders?payment_status=awaiting_payment&per_page=100', A)
  check(bankFilter.json.data.length >= 2 && bankFilter.json.data.every((o) => o.payment_method === 'bank_transfer') && awaitFilter.json.data.every((o) => o.payment_status === 'awaiting_payment') && awaitFilter.json.data.length >= 1, 'filters payment_method=bank_transfer and payment_status=awaiting_payment work', [bankFilter.json.data.length, awaitFilter.json.data.length])
  const bySearch = await get(`admin/orders?search=${encodeURIComponent(rNo)}`, A)
  check(bySearch.json?.data?.length === 1 && bySearch.json.data[0].order_number === rNo, 'search=<order number> finds the order', bySearch.json?.data?.length)
  await patch(`admin/orders/${r2Id}/note`, { admin_note: 'Call before delivery' }, A)
  const noteBad = await patch(`admin/orders/${r2Id}/note`, { note: 'typo in the field name' }, A)
  const noteKept = dbOne(`SELECT admin_note FROM orders WHERE id = ${r2Id}`)
  check(noteBad.status === 422 && noteKept.admin_note === 'Call before delivery', 'REGRESSION: PATCH note without admin_note -> 422 and the stored note is kept (it used to be cleared)', { status: noteBad.status, note: noteKept.admin_note })
  check((await patch(`admin/orders/${r2Id}/note`, { admin_note: null }, A)).status === 200 && dbOne(`SELECT admin_note FROM orders WHERE id = ${r2Id}`).admin_note === null, 'clearing the note is explicit (admin_note: null)')
  const devNew = await Promise.all([
    get(`admin/orders/${r2Id}/payment/proof`, D), post(`admin/orders/${r2Id}/status`, { status: 'rejected', reason: 'x' }, D),
    get('admin/orders?status=rejected&payment_method=bank_transfer&search=ORD', D), put(`admin/orders/${r2Id}/delivery-fee`, { fee: 1 }, D),
  ])
  check(devNew.every((r) => r.status === 403), 'the developer is blocked from every new order endpoint and filter (403)', devNew.map((r) => r.status))
  const devDash = await get('admin/dashboard', D)
  check(!('orders' in (devDash.json || {})) && !JSON.stringify(devDash.json).includes(rNo), 'the developer dashboard still has no order figures or order numbers', Object.keys(devDash.json || {}))

  section('F23', 'Rate limits of the new public endpoints')
  await sleep(61000)
  let couponLast = 0
  for (let i = 0; i < 32 && couponLast !== 429; i++) couponLast = (await storePost('coupons/validate', { code: 'NOPE', items: [{ variant_id: dp.variant, qty: 1 }] })).status
  check(couponLast === 429, 'POST /coupons/validate is limited to 30 a minute per IP (429 with Retry-After)', couponLast)
  await sleep(61000)
  const proofLimit = []
  for (let i = 0; i < 12; i++) proofLimit.push((await storeCall('POST', `orders/${bNo}/payment-proof?phone=0`, { form: formOf({}, { proof: file('x.png', 120, 120, [1, 1, 1]) }) })).status)
  check(proofLimit[0] === 404 && proofLimit.includes(429), 'payment-proof uploads are limited to 10 a minute per IP (404 for a wrong phone, then 429)', proofLimit)
  const hp = await storePost('coupons/validate', { code: 'X', website: 'bot', items: [{ variant_id: dp.variant, qty: 1 }] })
  check(hp.status === 422 && hp.json?.code === 'rejected' || hp.status === 429, 'the honeypot field is refused on the new public POSTs', status(hp).slice(0, 100))
  // leave the store as the scripts expect it
  await setSettings({ pay_bank_enabled: false, pay_card_visible: false, pay_bank_accounts: [], pay_bank_instructions: '', delivery_method: 'free_above', store_open: true, checkout_notice: '', delivery_eta_text: '', store_closed_message: '' }, D)
  }
} catch (e) {
  failures++
  console.log(`\nSCRIPT ERROR: ${e.stack || e}`)
  if (current) current.items.push({ ok: false, label: 'script error', actual: String(e.message || e) })
}

function furnitureSlug(tree, id) { return (tree.json?.data || []).find((c) => c.id === id)?.slug }

// ------------------------------------------------------------------ summary
console.log('\n================ SUMMARY ================')
for (const [id, s] of book) {
  const bad = s.items.filter((i) => !i.ok)
  console.log(`${bad.length ? 'FAIL' : 'PASS'}  ${id}  ${s.title}  (${s.items.length - bad.length}/${s.items.length})`)
  for (const b of bad) console.log(`        x ${b.label} -> ${b.actual}`)
}
console.log(failures ? `\n${failures} assertion(s) failed` : '\nAll assertions passed')
process.exit(failures ? 1 : 0)

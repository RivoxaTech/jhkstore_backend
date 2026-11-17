// Adversarial check of the REAL app over real HTTP, by three kinds of people: a customer who tries to cheat or break checkout, an admin who makes
// mistakes (or does not have the right), and the person who runs the server. Every check prints PASS / FAIL with the actual answer; exit code 1 on a failure.
//
//   cd backend && php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder      (scratch database, never the one you work in)
//   php artisan serve --port=8012  and a SECOND server on 8013 (races need two PHP processes)      with the same environment as scripts/flow-check.mjs
//   node scripts/adversarial.mjs
//
// Environment: FLOW_BASE / FLOW_BASE2 (default 8002 / 8003), FLOW_DEV_USER / FLOW_DEV_PASSWORD, FLOW_ADMIN_USER / FLOW_ADMIN_PASSWORD, MYSQL (the mysql
// client, to age carts and expire tokens in the scratch database), DB_DATABASE (must be the scratch database).
// The checks that need a stopped queue worker / a broken mail server are marked [ops] and use the second server (8013), which must be started with
// MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=1 SHOP_JOBS_WITHOUT_WORKER=true.
import { execFileSync } from 'node:child_process'
import zlib from 'node:zlib'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')   // the Laravel app (this folder is backend/scripts)
const BASE = process.env.FLOW_BASE || 'http://127.0.0.1:8002'
const BASE2 = process.env.FLOW_BASE2 || 'http://127.0.0.1:8003'
const DEV = [process.env.FLOW_DEV_USER || 'developer', process.env.FLOW_DEV_PASSWORD || 'developer']
const ADM = [process.env.FLOW_ADMIN_USER || 'admin', process.env.FLOW_ADMIN_PASSWORD || 'admin']
const MYSQL = process.env.MYSQL || 'mysql'
const DBNAME = process.env.DB_DATABASE || 'jhkstore_shots'
const RUN = Date.now().toString(36).toUpperCase()

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))
const short = (v, n = 200) => { const s = typeof v === 'string' ? v : JSON.stringify(v); return s && s.length > n ? `${s.slice(0, n)}…` : s }
let failures = 0
let passes = 0
const notes = []
function check(ok, label, actual) {
  if (ok) passes++; else failures++
  console.log(`  ${ok ? 'PASS' : 'FAIL'}  ${label}${actual === undefined ? '' : `  -> ${short(actual, 260)}`}`)
}
const note = (label, actual) => { notes.push(`${label}: ${short(actual, 200)}`); console.log(`  NOTE  ${label}  -> ${short(actual, 260)}`) }
const section = (id, title) => console.log(`\n[${id}] ${title}`)

async function call(method, route, { token, body, form, headers = {}, base = BASE, raw = false } = {}) {
  const h = { Accept: 'application/json', ...headers }
  let payload
  if (form) payload = form
  else if (body !== undefined) { h['Content-Type'] = 'application/json'; payload = typeof body === 'string' ? body : JSON.stringify(body) }
  if (token) h.Authorization = `Bearer ${token}`
  const r = await fetch(`${base}/api/v1/${route}`, { method, headers: h, body: payload })
  const text = await r.text()
  let json = null
  try { json = JSON.parse(text) } catch { /* not JSON */ }
  return { status: r.status, json, text, headers: r.headers }
}
const get = (route, token, extra) => call('GET', route, { token, ...extra })
const post = (route, body, token, extra) => call('POST', route, { token, body, ...extra })
const put = (route, body, token, extra) => call('PUT', route, { token, body, ...extra })
const patch = (route, body, token, extra) => call('PATCH', route, { token, body, ...extra })
const del = (route, token, extra) => call('DELETE', route, { token, ...extra })
const store = (route, extra) => get(`store/${route}`, undefined, extra)
const idOf = (r) => r.json?.data?.id ?? r.json?.id
const st = (r) => `HTTP ${r.status} ${short(r.json ?? r.text, 150)}`

function db(sql) { return JSON.parse(execFileSync('php', [path.join(ROOT, 'scripts', 'flow-db.php'), sql], { cwd: ROOT, encoding: 'utf8' })) }
const dbOne = (sql) => db(sql)[0]
// writes are only for aging data in the SCRATCH database (carts, tokens): refuse any other
function dbw(sql) {
  if (!/jhkstore_shots|scratch/.test(DBNAME)) throw new Error('dbw only runs against a scratch database')
  execFileSync(MYSQL, ['-uroot', DBNAME, '-e', sql], { encoding: 'utf8' })
}

const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
const crc32 = (buf) => { let c = 0xffffffff; for (const b of buf) c = crcTable[(c ^ b) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0 }
function chunk(type, data) { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const body = Buffer.concat([Buffer.from(type), data]); const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(body)); return Buffer.concat([len, body, crc]) }
function png(w, h, [r, g, b]) {
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array.from({ length: w }, () => [r, g, b]).flat())])
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(Buffer.concat(Array.from({ length: h }, () => row)))), chunk('IEND', Buffer.alloc(0))])
}
const pngFile = (name, w = 300, h = 300, colour = [200, 80, 40]) => new File([png(w, h, colour)], name, { type: 'image/png' })
const form = (fields, files = {}) => {
  const f = new FormData()
  for (const [k, v] of Object.entries(fields)) if (v !== undefined && v !== null) f.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v))
  for (const [k, v] of Object.entries(files)) for (const x of [].concat(v)) f.append(k, x)
  return f
}

let seq = 0
const phone = () => `+9231${String(Date.now()).slice(-6)}${(++seq) % 10}${(seq * 7) % 10}`.slice(0, 14)
const ADDRESS = { line1: '12 Test Street', city: 'Lahore', area: 'Gulberg', postcode: '54000', country: 'Pakistan' }
const orderBody = (items, extra = {}) => ({ customer: { name: `Adv ${RUN}`, phone: phone() }, address: ADDRESS, items: items.map(([variant_id, qty]) => ({ variant_id, qty })), ...extra })
const placeOrder = (body, { key = `adv-${RUN}-${++seq}-xxxxxxxx`, base = BASE } = {}) => post('store/orders', body, undefined, { base: undefined, headers: key ? { 'Idempotency-Key': key } : {}, ...(base !== BASE ? { base } : {}) })
const stock = (id) => dbOne(`SELECT stock_qty, reserved_qty, selling_price, mrp FROM product_variants WHERE id = ${Number(id)}`)

async function login([u, p]) { const r = await post('admin/auth/login', { login: u, password: p }); return r.json?.token }
const D = await login(DEV)
const A = await login(ADM)
if (!D || !A) { console.error('cannot log in: check FLOW_*_USER / PASSWORD'); process.exit(2) }

// admin gets the catalogue screens switched on (the developer decides): the default admin has no products
const ADMIN_ALL = (await get('admin/access-control', D)).json
void ADMIN_ALL

// ------------------------------------------------------------------ a clean product to attack
async function makeProduct({ name = `Adv Toy ${RUN}`, price = 100, mrp = 120, stock: qty = 10, sku, extra = {}, variantExtra = {} } = {}) {
  const cat = (await get('admin/categories/options', D)).json?.data?.[0]
  const body = { category_id: cat.id, name, sku: sku || `ADV-${RUN}-${++seq}`, short_description: 'adversarial', description: 'x', status: 'active', variants: [{ name: 'Default', sku: `${sku || `ADV-${RUN}`}-${++seq}-V`, is_default: true, mrp, selling_price: price, stock: qty, low_stock_threshold: 1, ...variantExtra }], ...extra }
  const r = await post('admin/products', body, D)
  const id = idOf(r)
  const full = id ? (await get(`admin/products/${id}`, D)).json?.data : null
  return { r, id, full, variantId: full?.variants?.[0]?.id, slug: full?.slug }
}

try {
  // ================================================================== CUSTOMER
  section('C1', 'Customer: double click on Place Order, the same request twice, a refresh in the middle')
  const p1 = await makeProduct({ stock: 10 })
  check(p1.id && p1.variantId, 'a product to attack exists', st(p1.r))
  const v1 = p1.variantId
  const key = `adv-double-${RUN}`
  const body1 = orderBody([[v1, 2]])
  const [a, b] = await Promise.all([placeOrder(body1, { key }), placeOrder(body1, { key, base: BASE2 })])
  const ok = [a, b].filter((r) => r.status === 201)
  check(ok.length >= 1 && new Set(ok.map((r) => r.json.data.order_number)).size === 1, 'the same Idempotency-Key from two servers at once gives ONE order number', [a.status, b.status, a.json?.data?.order_number, b.json?.data?.order_number])
  check(Number((stock(v1)).stock_qty) === 8, 'stock went down by 2, not 4', stock(v1))
  const again = await placeOrder(body1, { key })
  check(again.status < 300 && again.json?.data?.order_number === a.json?.data?.order_number, 'a refresh / retry with the same key returns the same order (no second order)', st(again))
  const same = await placeOrder({ ...body1, customer: { ...body1.customer } }, { key })
  check(Number((stock(v1)).stock_qty) === 8, 'still 8 in stock after three submits of the same order', stock(v1))
  void same
  const noKey1 = orderBody([[v1, 1]])
  const [n1, n2] = await Promise.all([placeOrder(noKey1, { key: null }), placeOrder(noKey1, { key: null, base: BASE2 })])
  note('two identical submits WITHOUT an Idempotency-Key (the storefront always sends one)', [n1.status, n2.status, n1.json?.data?.order_number, n2.json?.data?.order_number])

  section('C2', 'Customer: absurd quantities, junk types, empty cart')
  const bad = []
  for (const q of [0, -1, -999, 1.5, '1e9', 'abc', 99999, null, [], {}, 501]) {
    const r = await placeOrder(orderBody([[v1, q]]), { key: null })
    bad.push([JSON.stringify(q), r.status])
    if (![422, 400].includes(r.status)) check(false, `quantity ${JSON.stringify(q)} must be refused`, st(r))
  }
  check(bad.every(([, s]) => [422, 400].includes(s)), 'every bad quantity is refused with 422', bad.map(([q, s]) => `${q}:${s}`).join(' '))
  const empty = await placeOrder({ ...orderBody([]), items: [] }, { key: null })
  check(empty.status === 422, 'an empty cart is refused', st(empty))
  const tooMany = await placeOrder(orderBody(Array.from({ length: 40 }, (_, i) => [v1 + i, 1])), { key: null })
  check(tooMany.status === 422, 'more than 30 lines is refused', st(tooMany))
  const unknownVariant = await placeOrder(orderBody([[99999999, 1]]), { key: null })
  check([422, 404, 409].includes(unknownVariant.status), 'an unknown option id is refused (not a 500)', st(unknownVariant))
  const dupLines = await placeOrder(orderBody([[v1, 1], [v1, 1]]), { key: null })
  note('the same option twice in one order', st(dupLines))
  check(dupLines.status < 500, 'duplicate lines never crash', dupLines.status)
  const string = await placeOrder({ customer: 'x', address: 'y', items: 'z' }, { key: null })
  check(string.status === 422, 'wrong body types give 422, not 500', st(string))
  const notJson = await call('POST', 'store/orders', { body: '{"broken": ', headers: { 'Content-Type': 'application/json' } })
  check(notJson.status >= 400 && notJson.status < 500 && notJson.json, 'broken JSON gives a 4xx JSON answer', st(notJson))

  section('C3', 'Customer: out of stock, more than stock, sold out switch, a product that was deleted')
  const lowStock = await makeProduct({ stock: 3 })
  const over = await placeOrder(orderBody([[lowStock.variantId, 4]]), { key: null })
  check(over.status === 422 && /stock|available/i.test(JSON.stringify(over.json)), 'asking for more than the stock is refused with a clear message', st(over))
  const zero = await makeProduct({ stock: 0 })
  const oos = await placeOrder(orderBody([[zero.variantId, 1]]), { key: null })
  check(oos.status === 422 || oos.status === 409, 'an out-of-stock option cannot be ordered', st(oos))
  const q0 = await store('checkout/quote', {}).catch(() => null)
  void q0
  const quoteOos = await post('store/checkout/quote', { items: [{ variant_id: zero.variantId, qty: 1 }] })
  check(quoteOos.status === 200 && JSON.stringify(quoteOos.json).match(/out_of_stock|sold_out|stock/i), 'the quote says the option is out of stock (no crash)', st(quoteOos))
  const sold = await makeProduct({ stock: 5 })
  await patch(`admin/products/${sold.id}/sold-out`, { value: true }, D)
  const soldOrder = await placeOrder(orderBody([[sold.variantId, 1]]), { key: null })
  check([409, 422].includes(soldOrder.status), 'a product switched to "sold out" cannot be ordered', st(soldOrder))
  const sixInStock = await makeProduct({ stock: 6 })
  const racers = await Promise.all(Array.from({ length: 4 }, (_, i) => placeOrder(orderBody([[sixInStock.variantId, 2]]), { key: null, base: i % 2 ? BASE2 : BASE })))
  const won = racers.filter((r) => r.status === 201).length
  check(won === 3 && Number(stock(sixInStock.variantId).stock_qty) === 0, 'four buyers of 2 for 6 in stock: exactly three win, stock is 0 (never negative)', { won, stock: stock(sixInStock.variantId), statuses: racers.map((r) => r.status) })

  section('C4', 'Customer: a price changes while the item is in the cart; tampered prices')
  const pc = await makeProduct({ price: 100, mrp: 100, stock: 20 })
  const q1 = await post('store/checkout/quote', { items: [{ variant_id: pc.variantId, qty: 1 }], address: ADDRESS })
  const seen = Number(q1.json?.data?.summary?.grand_total)   // what the customer saw on the checkout page (with the delivery address)
  check(Number.isFinite(seen) && seen > 0, 'the quote with an address gives the total the customer sees', seen)
  const fullPc = (await get(`admin/products/${pc.id}`, D)).json.data
  const upd = await put(`admin/products/${pc.id}`, { ...productPayload(fullPc), variants: fullPc.variants.map((v) => ({ ...variantPayload(v), selling_price: 150, mrp: 150 })) }, D)
  check(upd.status === 200, 'admin raises the price from 100 to 150', st(upd))
  const stale = await placeOrder(orderBody([[pc.variantId, 1]], { expected_total: seen }), { key: null })
  check(stale.status === 409 && stale.json?.code === 'price_changed', 'ordering with the OLD total gets 409 price_changed (nothing is charged at the new price silently)', st(stale))
  const tamper = await placeOrder({ ...orderBody([[pc.variantId, 1]]), items: [{ variant_id: pc.variantId, qty: 1, price: 1, unit_price: 1, selling_price: 1, line_total: 1 }], grand_total: 1, discount_total: 99, shipping_fee: 0, coupon_discount: 50, status: 'delivered', payment_status: 'paid' }, { key: null })
  check(tamper.status === 201 && Number(tamper.json.data.totals.items_total) >= 150 && tamper.json.data.status === 'pending' && tamper.json.data.payment_status !== 'paid', 'a tampered request (price, total, discount, status, payment_status) is ignored: the server price and states apply', { items_total: tamper.json?.data?.totals?.items_total, status: tamper.json?.data?.status, pay: tamper.json?.data?.payment_status })

  section('C5', 'Customer: coupons (expired, not started, inactive, minimum, limits, reuse)')
  const base0 = await makeProduct({ price: 100, mrp: 100, stock: 200 })
  const iso = (d) => new Date(Date.now() + d * 3600000).toISOString().slice(0, 19)
  const mk = async (code, extra) => post('admin/coupons', { code, name: code, type: 'percent', value: 10, scope: 'store', ...extra }, A)
  const cExpired = await mk(`EXP${RUN}`, { starts_at: iso(-72), ends_at: iso(-24) })
  check(cExpired.status === 201 || cExpired.status === 200, 'admin can create a coupon whose end date is in the past (it just never works)', st(cExpired))
  const cFuture = await mk(`FUT${RUN}`, { starts_at: iso(48), ends_at: iso(96) })
  const cOff = await mk(`OFF${RUN}`, { is_active: false })
  const cMin = await mk(`MIN${RUN}`, { min_order: 500 })
  const cOne = await mk(`ONE${RUN}`, { usage_limit: 1 })
  const cPer = await mk(`PER${RUN}`, { per_customer_limit: 1 })
  const cFree = await mk(`ALL${RUN}`, { value: 100, type: 'percent' })
  const cBad1 = await mk(`BAD${RUN}`, { value: 150 })
  const cBad2 = await mk(`BAD2${RUN}`, { value: -5 })
  const cBad3 = await mk(`BAD3${RUN}`, { starts_at: iso(10), ends_at: iso(1) })
  const cDup = await mk(`one${RUN}`, {})
  check(cBad1.status === 422 && cBad2.status === 422 && cBad3.status === 422, 'a percent above 100, a negative value and an end before the start are refused (422)', [cBad1.status, cBad2.status, cBad3.status])
  check(cDup.status === 422, 'a duplicate coupon code (any letter case) is refused', st(cDup))
  const withCoupon = (code, qty = 1, extra = {}) => orderBody([[base0.variantId, qty]], { coupon_code: code, ...extra })
  const tries = {}
  for (const [name, code] of [['expired', `EXP${RUN}`], ['not started', `FUT${RUN}`], ['inactive', `OFF${RUN}`], ['minimum', `MIN${RUN}`], ['unknown', 'NOPE-NOPE']]) {
    const r = await placeOrder(withCoupon(code), { key: null })
    tries[name] = [r.status, r.json?.code]
    check(r.status === 422 && r.json?.code === 'coupon_invalid', `the ${name} coupon is refused with coupon_invalid`, st(r))
  }
  const v1c = await post('store/coupons/validate', { code: `EXP${RUN}`, items: [{ variant_id: base0.variantId, qty: 1 }] })
  check([200, 422].includes(v1c.status) && JSON.stringify(v1c.json).length > 10, 'coupon check for an expired code answers sensibly (no 500)', st(v1c))
  const o1 = await placeOrder(withCoupon(`ONE${RUN}`), { key: null })
  const o2 = await placeOrder(withCoupon(`ONE${RUN}`), { key: null })
  check(o1.status === 201 && o2.status === 422, 'a coupon with a limit of 1 works once, the second order is refused', [o1.status, o2.status, o2.json?.code])
  const usedBefore = dbOne(`SELECT used_count FROM coupons WHERE code = 'ONE${RUN}'`)
  check(Number(usedBefore.used_count) === 1, 'the use is counted once', usedBefore)
  const cancelUse = await post(`admin/orders/${o1.json.data.id}/status`, { status: 'cancelled' }, A)
  const usedAfter = dbOne(`SELECT used_count FROM coupons WHERE code = 'ONE${RUN}'`)
  check(cancelUse.status === 200 && Number(usedAfter.used_count) === 0, 'cancelling the order gives the coupon use back', usedAfter)
  const o3 = await placeOrder(withCoupon(`ONE${RUN}`), { key: null })
  check(o3.status === 201, 'the coupon can be used again after the cancel', st(o3))
  const samePhone = phone()
  const per1 = await placeOrder(withCoupon(`PER${RUN}`, 1, { customer: { name: 'Per Customer', phone: samePhone } }), { key: null })
  const per2 = await placeOrder(withCoupon(`PER${RUN}`, 1, { customer: { name: 'Per Customer', phone: samePhone } }), { key: null })
  check(per1.status === 201 && per2.status === 422, 'a per-customer limit of 1 (same phone) refuses the second order', [per1.status, per2.status])
  const free = await placeOrder(withCoupon(`ALL${RUN}`, 1, { payment_method: 'cod' }), { key: null })
  note('a 100% coupon: order total and delivery', { status: free.status, total: free.json?.data?.totals })
  check(free.status === 201 ? Number(free.json.data.totals.grand_total) >= 0 : free.status === 422, 'a 100% coupon never gives a negative total', st(free))

  section('C6', 'Customer: other people\'s orders, enumeration, tampered ids')
  const mine = await placeOrder(orderBody([[base0.variantId, 1]]), { key: null })
  const myNo = mine.json.data.order_number
  const myPhone = mine.json.data.customer.phone
  const looked = await store(`orders/${myNo}?phone=${encodeURIComponent(myPhone)}`)
  check(looked.status === 200, 'the owner can look the order up', st(looked))
  const wrongPhone = await store(`orders/${myNo}?phone=${encodeURIComponent('+923009999999')}`)
  const missing = await store('orders/ORD-2099-999999?phone=%2B923009999999')
  check(wrongPhone.status === 404 && missing.status === 404 && JSON.stringify(wrongPhone.json) === JSON.stringify(missing.json), 'a wrong phone looks exactly like a missing order (404, same body)', [wrongPhone.status, missing.status])
  const idLookup = await store(`orders/${mine.json.data.id}?phone=${encodeURIComponent(myPhone)}`)
  check(idLookup.status === 404, 'an order cannot be fetched by its numeric id', st(idLookup))
  const noPhone = await store(`orders/${myNo}`)
  check([404, 422].includes(noPhone.status), 'no phone = no order', st(noPhone))
  const adminNoAuth = await get(`admin/orders/${mine.json.data.id}`)
  check(adminNoAuth.status === 401, 'the admin order API without a token is 401', st(adminNoAuth))
  const proofWrong = await call('POST', `store/orders/${myNo}/payment-proof`, { form: form({ phone: '+923009999999' }, { proof: pngFile('p.png') }) })
  check(proofWrong.status === 404, 'uploading a proof for someone else\'s order is the same 404', st(proofWrong))

  section('C7', 'Customer: junk phone / e-mail / names / HTML')
  const junk = [
    ['letters', { name: 'Adv', phone: 'abcdefghij' }], ['plus signs', { name: 'Adv', phone: '++++++++' }], ['too short', { name: 'Adv', phone: '12345' }], ['too long', { name: 'Adv', phone: '1'.repeat(40) }],
    ['emoji', { name: 'Adv', phone: '📞📞📞📞📞📞📞' }], ['sql', { name: 'Adv', phone: "0300'; DROP TABLE orders;--" }], ['bad e-mail', { name: 'Adv', phone: phone(), email: 'not-an-email' }],
    ['e-mail with header injection', { name: 'Adv', phone: phone(), email: 'a@b.com\r\nBcc: evil@x.com' }], ['name 1 char', { name: 'A', phone: phone() }], ['name 5000 chars', { name: 'x'.repeat(5000), phone: phone() }], ['name empty', { name: '', phone: phone() }],
  ]
  for (const [label, customer] of junk) {
    const r = await placeOrder(orderBody([[base0.variantId, 1]], { customer }), { key: null })
    check(r.status === 422, `junk contact (${label}) is refused with 422`, st(r))
  }
  const xss = await placeOrder(orderBody([[base0.variantId, 1]], { customer: { name: '<script>alert(1)</script> "Quote" & Co', phone: phone(), email: `xss${RUN}@example.test`.toLowerCase() }, customer_note: '<img src=x onerror=alert(1)>' }), { key: null })
  check(xss.status === 201, 'HTML in the name and the note is accepted as plain text', st(xss))
  const xssOrder = (await get(`admin/orders/${xss.json?.data?.id}`, A)).json?.data
  check(xssOrder?.customer?.name?.includes('<script>'), 'it is stored as text (escaping is the screen\'s job; the panel uses text bindings)', xssOrder?.customer?.name)
  const longAddr = await placeOrder(orderBody([[base0.variantId, 1]], { address: { ...ADDRESS, line1: 'x'.repeat(300), postcode: '<<>>' } }), { key: null })
  check(longAddr.status === 422, 'an address line over 255 characters / a junk postcode is refused', st(longAddr))

  section('C8', 'Customer: honeypot (a filled trap is refused; browser autofill of "website" is not)')
  const trap = await placeOrder(orderBody([[base0.variantId, 1]], { hp_extra: 'http://spam.example' }), { key: null })
  check(trap.status === 422 || trap.status === 400, 'a filled hp_extra field is refused', st(trap))
  const autofill = await placeOrder(orderBody([[base0.variantId, 1]], { website: 'https://example.com', url: 'x', company: 'Acme', address2: 'Flat 2' }), { key: null })
  check(autofill.status === 201, 'autofill-style fields (website, url, company) do NOT trip the honeypot', st(autofill))
  const emptyTrap = await placeOrder(orderBody([[base0.variantId, 1]], { hp_extra: '' }), { key: null })
  check(emptyTrap.status === 201, 'an empty hp_extra is fine', st(emptyTrap))
  const trapQuote = await post('store/checkout/quote', { items: [{ variant_id: base0.variantId, qty: 1 }], hp_extra: 'x' })
  check([400, 422].includes(trapQuote.status), 'the quote and coupon check have the trap too', st(trapQuote))

  section('C9', 'Customer: bank transfer proof upload (wrong type, oversize, fake image, wrong order)')
  const bankOn = await put('admin/settings', { pay_bank_enabled: true, pay_bank_proof_required: true, pay_bank_accounts: [{ bank: 'Test Bank', title: 'Shop', number: '123456', iban: 'PK00TEST' }] }, D)
  check(bankOn.status === 200, 'bank transfer switched on (developer token)', st(bankOn))
  const noProof = await placeOrder(orderBody([[base0.variantId, 1]], { payment_method: 'bank_transfer' }), { key: null })
  check(noProof.status === 422 && noProof.json?.errors?.proof, 'when the shop requires the screenshot, a bank transfer order without one is refused', st(noProof))
  await put('admin/settings', { pay_bank_proof_required: false }, D)
  const bank = await placeOrder(orderBody([[base0.variantId, 1]], { payment_method: 'bank_transfer' }), { key: null })
  check(bank.status === 201, 'a bank transfer order is placed', st(bank))
  const bno = bank.json?.data?.order_number; const bphone = bank.json?.data?.customer?.phone
  const upload = (file, extra = {}) => call('POST', `store/orders/${bno}/payment-proof`, { form: form({ phone: bphone, ...extra }, { proof: file }) })
  const txt = await upload(new File(['hello'], 'proof.png', { type: 'image/png' }))
  check(txt.status === 422, 'a text file named proof.png is refused (content is checked)', st(txt))
  const svg = await upload(new File(['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'], 'p.svg', { type: 'image/svg+xml' }))
  check(svg.status === 422, 'an SVG is refused', st(svg))
  const php = await upload(new File(['<?php echo 1;'], 'shell.php', { type: 'application/x-php' }))
  check(php.status === 422, 'a PHP file is refused', st(php))
  const big = await upload(new File([Buffer.concat([png(300, 300, [1, 2, 3]), Buffer.alloc(5 * 1024 * 1024)])], 'big.png', { type: 'image/png' }))
  check(big.status === 422 || big.status === 413, 'a 5 MB file is refused (limit 4 MB)', st(big))
  const tiny = await upload(pngFile('tiny.png', 10, 10))
  check(tiny.status === 422, 'a 10x10 picture (too small to be a screenshot) is refused', st(tiny))
  const empty0 = await upload(new File([], 'empty.png', { type: 'image/png' }))
  check(empty0.status === 422, 'an empty file is refused', st(empty0))
  const good = await upload(pngFile('good.png', 400, 400), { reference: 'TXN123' })
  check(good.status === 200 || good.status === 201, 'a real picture is accepted', st(good))
  const cod = await upload(pngFile('good2.png', 400, 400))
  void cod
  const codOrder = await placeOrder(orderBody([[base0.variantId, 1]]), { key: null })
  const codProof = await call('POST', `store/orders/${codOrder.json.data.order_number}/payment-proof`, { form: form({ phone: codOrder.json.data.customer.phone }, { proof: pngFile('c.png', 400, 400) }) })
  check(codProof.status === 422 || codProof.status === 409, 'a proof cannot be uploaded to a cash-on-delivery order', st(codProof))
  const publicUrl = JSON.stringify((await store(`orders/${bno}?phone=${encodeURIComponent(bphone)}`)).json)
  check(!/\/storage\/.*good\.png/.test(publicUrl), 'the proof has no public URL in the customer answer', publicUrl.slice(0, 120))

  section('C10', 'Customer: wholesale form (junk, honeypot, spam)')
  const w = (extra = {}) => post('store/wholesale-requests', { name: 'Bulk Buyer', phone: phone(), product_name: 'Wooden blocks', quantity: 100, message: 'Need a price', ...extra })
  const w1 = await w()
  check(w1.status === 201, 'a normal wholesale request is accepted', st(w1))
  const wBad = await w({ phone: 'abc', quantity: -5 })
  check(wBad.status === 422, 'junk phone / negative quantity refused', st(wBad))
  const wTrap = await w({ hp_extra: 'spam' })
  check([400, 422].includes(wTrap.status), 'a filled honeypot is refused', st(wTrap))
  const wFill = await w({ website: 'https://example.com' })
  check(wFill.status === 201, 'browser autofill of "website" does not trip it', st(wFill))
  const wLong = await w({ message: 'x'.repeat(10000) })
  check(wLong.status === 422, 'a 10 000 character message is refused', st(wLong))
  const spamPhone = phone()
  const spam = []
  for (let i = 0; i < 7; i++) spam.push((await w({ phone: spamPhone })).status)
  check(spam.slice(5).every((s) => s === 429) && spam.slice(0, 5).every((s) => s === 201), 'the same phone can send 5 an hour, then 429', spam)

  section('C11', 'Customer: the server cart in two tabs, and an abandoned cart that is recovered')
  const abPhone = '+92300' + String(Date.now()).slice(-7)
  const tokenA = (await get('store/cart', undefined, { headers: {} })).headers.get('x-cart-token') || (await get('store/cart')).json?.token
  note('cart token source', tokenA ? 'header X-Cart-Token' : 'none')
  const c0 = await post('store/cart/items', { variant_id: base0.variantId, qty: 1 })
  const ctoken = c0.headers.get('x-cart-token') || c0.json?.data?.token || c0.json?.token
  check(c0.status < 300 && ctoken, 'a new cart is made on the first add and a token is returned', st(c0))
  const H = { 'X-Cart-Token': ctoken }
  const [t1, t2] = await Promise.all([post('store/cart/items', { variant_id: base0.variantId, qty: 2 }, undefined, { headers: H }), post('store/cart/items', { variant_id: lowStock.variantId, qty: 1 }, undefined, { headers: H })])
  const cartNow = await get('store/cart', undefined, { headers: H })
  const lines = cartNow.json?.data?.items || cartNow.json?.items || []
  check(t1.status < 300 && t2.status < 300 && lines.length === 2, 'two tabs adding at once: both lines are in the cart', { s: [t1.status, t2.status], lines: lines.map((l) => [l.variant_id, l.qty]) })
  const qtyLine = lines.find((l) => l.variant_id === base0.variantId)
  check(qtyLine && qtyLine.qty === 3, 'the same option added from two tabs adds up (1 + 2 = 3)', qtyLine)
  const setQty = await put(`store/cart/items/${base0.variantId}`, { qty: 9999 }, undefined, { headers: H })
  check(setQty.status === 422 || (setQty.json?.data?.items || []).every((l) => l.qty <= 500), 'a huge quantity in the cart is refused or clamped', st(setQty))
  const negQty = await put(`store/cart/items/${base0.variantId}`, { qty: -1 }, undefined, { headers: H })
  check([422, 400].includes(negQty.status), 'a negative quantity in the cart is refused', st(negQty))
  const withContact = await put('store/cart/contact', { name: 'Abandoned Anna', phone: abPhone, email: `anna${RUN}@example.test`.toLowerCase() }, undefined, { headers: H })
  check(withContact.status < 300, 'the cart keeps the contact (this is what makes it recoverable)', st(withContact))
  const cartId = dbOne(`SELECT id FROM carts WHERE customer_phone = '${abPhone}' ORDER BY id DESC LIMIT 1`)?.id
  note('cart row', cartId)
  const abandonedNow = await get('admin/customers/abandoned-carts', A)
  check(!(abandonedNow.json?.data || []).some((r) => JSON.stringify(r).includes(abPhone)), 'a cart that is active right now is NOT abandoned yet', abandonedNow.status)
  if (cartId) dbw(`UPDATE carts SET updated_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR, last_activity_at = UTC_TIMESTAMP() - INTERVAL 3 HOUR WHERE id = ${Number(cartId)}`)
  const abandoned = await get('admin/customers/abandoned-carts', A)
  const row = (abandoned.json?.data || []).find((r) => JSON.stringify(r).includes(abPhone))
  check(!!row, 'after 3 hours of silence the cart shows in the abandoned list (admin can recover it by WhatsApp / e-mail)', abandoned.json ? short(abandoned.json, 240) : abandoned.status)
  const recover = await get('store/cart', undefined, { headers: H })
  check(recover.status === 200 && (recover.json?.data?.items || recover.json?.items || []).length === 2, 'the customer comes back with the same token: the cart is still there (recovered)', st(recover))
  const fromCart = await post('store/orders', { cart_token: ctoken, customer: { name: 'Abandoned Anna', phone: abPhone }, address: ADDRESS }, undefined, { headers: { 'Idempotency-Key': `adv-cart-${RUN}-12345` } })
  check(fromCart.status === 201, 'the recovered cart can be ordered', st(fromCart))
  const abandonedAfter = await get('admin/customers/abandoned-carts', A)
  check(!(abandonedAfter.json?.data || []).some((r) => JSON.stringify(r).includes(abPhone)), 'after the order it is no longer in the abandoned list', abandonedAfter.status)
  const afterCart = await get('store/cart', undefined, { headers: H })
  check((afterCart.json?.data?.items || afterCart.json?.items || []).length === 0, 'and the cart is empty', st(afterCart))

  // ================================================================== ADMIN
  section('A1', 'Admin: categories (delete one that has products, duplicates, blank names, shows in the dropdown at once)')
  const catName = `Adv Cat ${RUN}`
  const cat = await post('admin/categories', { name: catName, is_active: true }, D)
  check(cat.status === 201 && idOf(cat), 'create a category', st(cat))
  const opts = await get('admin/categories/options', D)
  check((opts.json?.data || []).some((c) => c.id === idOf(cat)), 'it is in the product dropdown (categories/options) immediately', opts.status)
  const catPub = await store('categories')
  check((catPub.json?.data || []).some((c) => c.name === catName), 'and in the public category list at once (cache cleared by the write)', catPub.status)
  const blank = await post('admin/categories', { name: '   ' }, D)
  check(blank.status === 422, 'a blank category name is refused', st(blank))
  const dupCat = await post('admin/categories', { name: catName }, D)
  note('a second category with the same name', st(dupCat))
  check(dupCat.status < 500, 'a duplicate category name never crashes', dupCat.status)
  const inCat = await makeProduct({ extra: { category_id: idOf(cat) } })
  const delCat = await del(`admin/categories/${idOf(cat)}`, D)
  check(delCat.status === 422 || delCat.status === 409, 'deleting a category that has products is refused with a message (products are not orphaned)', st(delCat))
  const prodAfter = await get(`admin/products/${inCat.id}`, D)
  check(prodAfter.status === 200 && (prodAfter.json?.data?.category?.id ?? prodAfter.json?.data?.category_id) === idOf(cat), 'the product still has its category', st(prodAfter))
  const emptyCat = await post('admin/categories', { name: `Adv Empty ${RUN}` }, D)
  const delEmpty = await del(`admin/categories/${idOf(emptyCat)}`, D)
  check(delEmpty.status === 200 || delEmpty.status === 204, 'an empty category can be deleted', delEmpty.status)
  const optsAfter = await get('admin/categories/options', D)
  check(!(optsAfter.json?.data || []).some((c) => c.id === idOf(emptyCat)), 'and it leaves the dropdown at once', optsAfter.status)

  section('A2', 'Admin: products (blank fields, duplicate SKU / slug, bad numbers, optional MRP, automatic SEO, arrange)')
  const catFirst = (await get('admin/categories/options', D)).json.data[0].id
  const base = (o = {}) => ({ category_id: catFirst, name: `Adv P ${RUN} ${++seq}`, sku: `ADVP-${RUN}-${seq}`, status: 'active', variants: [{ name: 'One', sku: `ADVV-${RUN}-${seq}`, is_default: true, mrp: 50, selling_price: 40, stock: 5 }], ...o })
  const noName = await post('admin/products', base({ name: '' }), D)
  check(noName.status === 422 && noName.json?.errors?.name, 'a product without a name is refused', st(noName))
  const noVariant = await post('admin/products', base({ variants: [] }), D)
  check(noVariant.status === 422, 'a product without any option is refused', st(noVariant))
  const negPrice = await post('admin/products', base({ variants: [{ name: 'x', sku: `NEG-${RUN}`, mrp: 10, selling_price: -5, stock: 1 }] }), D)
  const negStock = await post('admin/products', base({ variants: [{ name: 'x', sku: `NEGS-${RUN}`, mrp: 10, selling_price: 5, stock: -3 }] }), D)
  const zeroPrice = await post('admin/products', base({ variants: [{ name: 'x', sku: `ZP-${RUN}`, mrp: 10, selling_price: 0, stock: 3 }] }), D)
  check(negPrice.status === 422 && negStock.status === 422, 'a negative price or stock is refused', [negPrice.status, negStock.status])
  note('a selling price of 0', st(zeroPrice))
  const above = await post('admin/products', base({ variants: [{ name: 'x', sku: `ABV-${RUN}`, mrp: 10, selling_price: 50, stock: 3 }] }), D)
  note('selling price ABOVE the MRP', st(above))
  check(above.status < 500, 'selling above MRP never crashes', above.status)
  const huge = await post('admin/products', base({ variants: [{ name: 'x', sku: `HUGE-${RUN}`, mrp: 1e12, selling_price: 1e11, stock: 99999999999 }] }), D)
  check(huge.status === 422, 'absurdly large numbers are refused (not a database error)', st(huge))
  const p1x = await post('admin/products', base(), D)
  const dupSku = await post('admin/products', base({ sku: p1x.json?.data?.sku }), D)
  check(dupSku.status === 422 && dupSku.json?.errors, 'a duplicate product SKU is refused', st(dupSku))
  const dupVariantSku = await post('admin/products', base({ variants: [{ name: 'x', sku: p1x.json?.data?.variants?.[0]?.sku, mrp: 10, selling_price: 5, stock: 3 }] }), D)
  check(dupVariantSku.status === 422, 'a duplicate option SKU is refused', st(dupVariantSku))
  const sameName = await post('admin/products', base({ name: p1x.json?.data?.name }), D)
  check(sameName.status === 201 && sameName.json?.data?.slug !== p1x.json?.data?.slug, 'the same product name twice gives two different slugs', [p1x.json?.data?.slug, sameName.json?.data?.slug])
  const noMrp = await post('admin/products', base({ variants: [{ name: 'x', sku: `NOMRP-${RUN}`, is_default: true, mrp: null, selling_price: 77, stock: 4 }] }), D)
  check(noMrp.status === 201 && Number(noMrp.json?.data?.variants?.[0]?.mrp) === 77, 'a blank MRP is saved as the selling price', noMrp.json?.data?.variants?.[0])
  const disc20 = await post('admin/discounts', { name: `Adv20 ${RUN}`, type: 'percent', value: 20, scope: 'products', target_ids: [noMrp.json.data.id] }, A)
  check(disc20.status === 201, 'a 20% discount on that product', st(disc20))
  const card = await store(`products/${noMrp.json?.data?.slug}`)
  const cardV = card.json?.data
  note('storefront price fields of a product without MRP and a 20% discount', { price: cardV?.price, mrp: cardV?.mrp, compare_at: cardV?.compare_at_price, old: cardV?.old_price, pct: cardV?.discount_percent, label: cardV?.discount_label })
  check([61.6, 62].includes(Number(cardV?.price)), 'the price to pay is 77 - 20% = 61.60 (62 when the shop shows no decimals)', cardV?.price)
  check(Number(cardV?.mrp ?? cardV?.compare_at_price ?? cardV?.old_price) === 77, 'the crossed-out price is the price BEFORE the discount (77)', { mrp: cardV?.mrp, compare_at: cardV?.compare_at_price, old: cardV?.old_price })
  const seoP = await post('admin/products', base({ name: `Auto SEO Rocket ${RUN}`, short_description: 'A rocket toy for small astronauts', meta_title: '', meta_description: '' }), D)
  const seoShop = await store(`products/${seoP.json?.data?.slug}`)
  check(/Auto SEO Rocket/.test(JSON.stringify(seoShop.json?.data?.seo || {})), 'automatic SEO: an empty meta title becomes the product name on the storefront', seoShop.json?.data?.seo)
  const ids = [p1x.json.data.id, sameName.json.data.id, noMrp.json.data.id]
  const reorder = await put('admin/products/reorder', { ids: [ids[2], ids[0], ids[1]] }, D)
  check(reorder.status === 200 || reorder.status === 204, 'drag-to-arrange saves an order', st(reorder))
  const listed = (await get('admin/products?per_page=100', D)).json.data.map((p) => p.id)
  check(listed.indexOf(ids[2]) < listed.indexOf(ids[0]) && listed.indexOf(ids[0]) < listed.indexOf(ids[1]), 'and the admin list follows it', listed.slice(0, 8))
  const badReorder = await put('admin/products/reorder', { ids: [ids[0], ids[0], 99999999] }, D)
  check([422, 400].includes(badReorder.status), 'a reorder with a duplicate / unknown id is refused', st(badReorder))
  const shopOrder = (await store('products?per_page=60&sort=popularity')).json.data.map((p) => p.id)
  note('storefront default order follows the arrangement', { admin: ids, shop_positions: ids.map((i) => shopOrder.indexOf(i)) })

  section('A3', 'Admin: delete a product that is in open orders and in carts')
  const open = await makeProduct({ stock: 9 })
  const openOrder = await placeOrder(orderBody([[open.variantId, 2]]), { key: null })
  const cartP = await post('store/cart/items', { variant_id: open.variantId, qty: 1 })
  const cartTok = cartP.headers.get('x-cart-token')
  const delP = await del(`admin/products/${open.id}`, D)
  note('deleting a product that is in an open order', st(delP))
  check(delP.status < 500, 'it never crashes', delP.status)
  const orderAfterDel = await get(`admin/orders/${openOrder.json.data.id}`, A)
  check(orderAfterDel.status === 200 && (orderAfterDel.json?.data?.items || []).length === 1, 'the order still opens with its line', st(orderAfterDel))
  const cartAfterDel = await get('store/cart', undefined, { headers: { 'X-Cart-Token': cartTok } })
  check(cartAfterDel.status === 200, 'the cart that held the deleted product still loads (the line is flagged, no 500)', st(cartAfterDel))
  const orderFromCart = await post('store/orders', { cart_token: cartTok, customer: { name: 'Deleted Product', phone: phone() }, address: ADDRESS }, undefined, { headers: { 'Idempotency-Key': `adv-del-${RUN}-1234567` } })
  check(orderFromCart.status === 422, 'checking out a cart with a deleted product is refused (cart_invalid)', st(orderFromCart))
  const cancelOpen = await post(`admin/orders/${openOrder.json.data.id}/status`, { status: 'cancelled' }, A)
  check(cancelOpen.status === 200, 'cancelling the order of a deleted product works', st(cancelOpen))

  section('A4', 'Admin: discounts (100 %, more than the price, overlapping, past end date, wrong values)')
  const dp = await makeProduct({ price: 100, mrp: 100, stock: 50 })
  const mkd = (extra) => post('admin/discounts', { name: `AD ${RUN} ${++seq}`, type: 'percent', value: 10, scope: 'products', target_ids: [dp.id], ...extra }, A)
  const d100 = await mkd({ value: 100 })
  const price100 = (await store(`products/${dp.slug}`)).json?.data?.price
  note('a 100% discount: the shown price', { status: d100.status, price: price100 })
  check(d100.status === 201 ? Number(price100) >= 0 : d100.status === 422, '100% never shows a negative price', price100)
  if (d100.status === 201) {
    const zeroOrder = await placeOrder(orderBody([[dp.variantId, 1]], { payment_method: 'cod' }), { key: null })
    note('an order at a 100% discount', { status: zeroOrder.status, total: zeroOrder.json?.data?.totals?.grand_total })
    check(zeroOrder.status === 201 ? Number(zeroOrder.json.data.totals.grand_total) >= 0 : zeroOrder.status === 422, 'a zero-price order is accepted or refused cleanly, never negative', st(zeroOrder))
    await del(`admin/discounts/${idOf(d100)}`, A)
  }
  const dOver = await mkd({ type: 'fixed', value: 500 })
  const priceOver = (await store(`products/${dp.slug}`)).json?.data?.price
  check(dOver.status === 422 || Number(priceOver) >= 0, 'a fixed discount larger than the price never makes it negative', { status: dOver.status, price: priceOver })
  if (dOver.status === 201) await del(`admin/discounts/${idOf(dOver)}`, A)
  const dNeg = await mkd({ value: -10 }); const dBig = await mkd({ value: 101 }); const dZero = await mkd({ value: 0 })
  check(dNeg.status === 422 && dBig.status === 422, 'a negative or >100% discount is refused', [dNeg.status, dBig.status])
  note('a discount of 0', dZero.status)
  if (dZero.status === 201) await del(`admin/discounts/${idOf(dZero)}`, A)
  const dRange = await mkd({ starts_at: iso(48), ends_at: iso(24) })
  check(dRange.status === 422, 'an end date before the start date is refused', st(dRange))
  const dPast = await mkd({ starts_at: iso(-72), ends_at: iso(-24), value: 90 })
  const pricePast = (await store(`products/${dp.slug}`)).json?.data?.price
  check(dPast.status === 201 && Number(pricePast) === 100, 'a discount that already ended does nothing', pricePast)
  const dA = await mkd({ value: 10 }); const dB = await mkd({ value: 30 }); const dC = await mkd({ value: 20, scope: 'store', target_ids: undefined })
  const priceMix = (await store(`products/${dp.slug}`)).json?.data?.price
  check(Number(priceMix) === 70, 'overlapping discounts (10 %, 30 %, store 20 %): the best one applies, never stacked (100 -> 70)', priceMix)
  const orderMix = await placeOrder(orderBody([[dp.variantId, 1]]), { key: null })
  check(Number(orderMix.json?.data?.totals?.items_total) === 100 && Number(orderMix.json?.data?.totals?.subtotal) === 70, 'and the order uses the same 70', orderMix.json?.data?.totals)
  for (const d of [dA, dB, dC, dPast]) if (idOf(d)) await del(`admin/discounts/${idOf(d)}`, A)
  const delD = await del(`admin/discounts/${idOf(dA)}`, A)
  check([404, 200, 204].includes(delD.status), 'deleting a discount twice is a clean 404', delD.status)

  section('A5', 'Admin: order status in the wrong order, cancel a delivered order, two admins at once, stock and totals')
  const sp = await makeProduct({ price: 50, mrp: 50, stock: 10 })
  const phoneS = phone()
  const place = async (qty = 1) => (await placeOrder(orderBody([[sp.variantId, qty]], { customer: { name: 'Status Tester', phone: phoneS } }), { key: null })).json.data
  const setStatus = (id, status, tk = A, base = BASE) => call('POST', `admin/orders/${id}/status`, { token: tk, body: { status }, base })
  const o = await place(2)
  check(Number(stock(sp.variantId).stock_qty) === 8, 'stock 10 - 2 = 8 after the order', stock(sp.variantId))
  for (const bad of ['delivered', 'shipped', 'packed']) {
    const r = await setStatus(o.id, bad)
    check(r.status === 422 && r.json?.code === 'invalid_status_change', `pending -> ${bad} is refused (one step at a time)`, st(r))
  }
  const junkStatus = await setStatus(o.id, 'banana')
  check(junkStatus.status === 422, 'an unknown status is refused', st(junkStatus))
  check((await setStatus(o.id, 'confirmed')).status === 200, 'pending -> confirmed works with no extra data', 200)
  check((await setStatus(o.id, 'confirmed')).status === 422, 'confirmed twice is refused', 422)
  check((await setStatus(o.id, 'rejected')).status === 422, 'a confirmed order cannot be rejected', 422)
  check((await setStatus(o.id, 'packed')).status === 200, 'confirmed -> packed', 200)
  const ship = await setStatus(o.id, 'shipped')
  check(ship.status === 200, 'packed -> shipped needs no courier details', st(ship))
  check((await setStatus(o.id, 'cancelled')).status === 422, 'a shipped order cannot be cancelled', 422)
  check((await setStatus(o.id, 'delivered')).status === 200, 'shipped -> delivered', 200)
  const cancelDelivered = await setStatus(o.id, 'cancelled')
  check(cancelDelivered.status === 422, 'a delivered order cannot be cancelled', st(cancelDelivered))
  const delivered = (await get(`admin/orders/${o.id}`, A)).json.data
  check(delivered.payment.status === 'paid', 'delivery marks cash on delivery as paid', delivered.payment)
  const cust = dbOne(`SELECT orders_count, total_spent FROM customers WHERE phone = '${o.customer.phone}'`)
  check(Number(cust.orders_count) === 1 && Number(cust.total_spent) === Number(o.totals.grand_total), 'customer history: 1 order, total spent = the order total', cust)
  const o2x = await place(3)
  check(Number(stock(sp.variantId).stock_qty) === 5, 'second order: stock 8 - 3 = 5', stock(sp.variantId))
  const [r1, r2] = await Promise.all([setStatus(o2x.id, 'confirmed', A, BASE), setStatus(o2x.id, 'confirmed', A, BASE2)])
  check([r1.status, r2.status].sort().join() === '200,422', 'two admins pressing Confirm at the same moment: one wins, the other gets a clear 422', [r1.status, r2.status])
  const [c1, c2] = await Promise.all([setStatus(o2x.id, 'cancelled', A, BASE), setStatus(o2x.id, 'cancelled', A, BASE2)])
  check([c1.status, c2.status].sort().join() === '200,422', 'two admins cancelling at once: one cancel only', [c1.status, c2.status])
  check(Number(stock(sp.variantId).stock_qty) === 8, 'stock returns ONCE (5 + 3 = 8, never 11)', stock(sp.variantId))
  const cust2 = dbOne(`SELECT orders_count, total_spent FROM customers WHERE phone = '${o.customer.phone}'`)
  check(Number(cust2.orders_count) === 1 && Number(cust2.total_spent) === Number(o.totals.grand_total), 'the cancelled order is taken out of the customer totals once', cust2)
  const hist = (await get(`admin/orders/${o2x.id}`, A)).json.data
  check((hist.timeline || []).filter((h) => /cancel/i.test(JSON.stringify(h))).length === 1, 'the timeline has one cancel entry', (hist.timeline || []).map((h) => h.status || h.label || h.to_status))
  const rej = await place(1)
  const rejR = await setStatus(rej.id, 'rejected')
  check(rejR.status === 200 && Number(stock(sp.variantId).stock_qty) === 8, 'reject needs no reason and returns the stock', [rejR.status, stock(sp.variantId)])
  const gone = await setStatus(rej.id, 'confirmed')
  check(gone.status === 422, 'a rejected order is final', st(gone))
  const unknownOrder = await setStatus(99999999, 'confirmed')
  check(unknownOrder.status === 404, 'an unknown order id is 404', unknownOrder.status)

  section('A6', 'Admin: a role with limited permissions, by URL and by API; tokens')
  const limit = await put('admin/access-control/admin', { permissions: ['dashboard.view', 'orders.view'] }, D)
  check(limit.status === 200, 'the developer limits the admin to Dashboard + view orders', st(limit))
  const LA = await login(ADM)
  const forbidden = {}
  for (const [method, route, body] of [['GET', 'admin/products'], ['GET', 'admin/customers'], ['GET', 'admin/coupons'], ['GET', 'admin/discounts'], ['GET', 'admin/wholesale'], ['GET', 'admin/settings'], ['GET', 'admin/access-control'], ['GET', 'admin/categories'], ['POST', `admin/orders/${o.id}/status`, { status: 'confirmed' }], ['GET', 'admin/orders/export'], ['PUT', 'admin/settings', { store_name: 'Hacked' }], ['POST', 'admin/coupons', { code: 'X', name: 'x', type: 'percent', value: 5, scope: 'store' }], ['DELETE', `admin/products/${p1x.json.data.id}`], ['PUT', 'admin/access-control/admin', { permissions: ['access.manage'] }]]) {
    const r = await call(method, route, { token: LA, body })
    forbidden[`${method} ${route}`] = r.status
  }
  check(Object.values(forbidden).every((s) => s === 403), 'every other page / API the limited admin tries is 403', forbidden)
  check((await get('admin/orders', LA)).status === 200, 'but the allowed one works', 200)
  const devOrders = await get('admin/orders', D)
  check(devOrders.status === 403, 'the developer can never read orders', devOrders.status)
  check((await get('admin/orders', 'garbage-token')).status === 401 && (await get('admin/orders', `${LA.slice(0, -3)}xyz`)).status === 401, 'a garbage or tampered token is 401', 401)
  await put('admin/access-control/admin', { permissions: ['dashboard.view', 'orders.view', 'orders.manage', 'orders.export', 'customers.view', 'customers.export', 'settings.general', 'settings.store', 'settings.delivery', 'settings.payments', 'settings.notifications', 'settings.website', 'discounts.view', 'discounts.manage', 'coupons.view', 'coupons.manage', 'wholesale.view', 'wholesale.manage'] }, D)
  const EXP = await login(ADM)
  dbw(`UPDATE personal_access_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ${Number(EXP.split('|')[0])}`)
  const expired = await get('admin/orders', EXP)
  check(expired.status === 401 && expired.json?.message, 'an expired token is a clean 401 JSON (the panel then returns to the login page)', st(expired))
  const logout = await post('admin/auth/logout', {}, await login(ADM))
  check(logout.status < 300, 'logout works', logout.status)

  section('A7', 'Admin: bad images / videos')
  const imgUrl = `admin/products/${p1x.json.data.id}/images`
  const imgs = {}
  imgs.text = (await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': new File(['not an image'], 'a.png', { type: 'image/png' }) }) })).status
  imgs.svg = (await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': new File(['<svg xmlns="http://www.w3.org/2000/svg"/>'], 'a.svg', { type: 'image/svg+xml' }) }) })).status
  imgs.empty = (await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': new File([], 'e.png', { type: 'image/png' }) }) })).status
  imgs.tiny = (await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': pngFile('t.png', 20, 20) }) })).status
  imgs.huge = (await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': new File([Buffer.concat([png(300, 300, [9, 9, 9]), Buffer.alloc(9 * 1024 * 1024)])], 'h.png', { type: 'image/png' }) }) })).status
  check(Object.values(imgs).every((s) => [422, 413].includes(s)), 'wrong type, SVG, empty, tiny and 9 MB pictures are all refused cleanly', imgs)
  const goodImg = await call('POST', imgUrl, { token: D, form: form({}, { 'images[]': pngFile('ok.png', 400, 400) }) })
  check(goodImg.status === 201 || goodImg.status === 200, 'a good picture is accepted', st(goodImg))
  const videoUrl = `admin/products/${p1x.json.data.id}/videos`
  const vtxt = await call('POST', videoUrl, { token: D, form: form({}, { video: new File(['not a video at all'], 'v.mp4', { type: 'video/mp4' }) }) })
  const vexe = await call('POST', videoUrl, { token: D, form: form({}, { video: new File([Buffer.from('MZ')], 'v.exe', { type: 'application/octet-stream' }) }) })
  check(vtxt.status === 422 && vexe.status === 422, 'a fake mp4 and an exe are refused as videos', [vtxt.status, vexe.status])
  const badUrl = await put(`admin/products/${p1x.json.data.id}`, { ...productPayload(p1x.json.data), video_url: 'javascript:alert(1)', variants: p1x.json.data.variants.map(variantPayload) }, D)
  check(badUrl.status === 422, 'a javascript: video link is refused', st(badUrl))

  section('A8', 'Admin: two admins edit the same product')
  const ep = await makeProduct({ price: 10, mrp: 10 })
  const epFull = ep.full
  const pay = (name) => ({ ...productPayload(epFull), name, variants: epFull.variants.map(variantPayload) })
  const [e1, e2] = await Promise.all([put(`admin/products/${ep.id}`, pay(`Edit A ${RUN}`), D, { base: BASE }), put(`admin/products/${ep.id}`, pay(`Edit B ${RUN}`), D, { base: BASE2 })])
  check(e1.status === 200 && e2.status === 200, 'two simultaneous edits both succeed (last write wins, no 500, no deadlock)', [e1.status, e2.status])
  const finalName = (await get(`admin/products/${ep.id}`, D)).json.data.name
  note('last write wins; there is no "someone else changed this" warning', finalName)
} catch (e) {
  failures++
  console.log(`\nFAIL  the run stopped with an exception: ${e.stack || e}`)
}

function productPayload(p) {
  return { category_id: p.category_id ?? p.category?.id, sub_category_id: p.sub_category_id ?? null, name: p.name, sku: p.sku, short_description: p.short_description, description: p.description, status: p.status, tax_rate: p.tax_rate ?? null, min_order_qty: p.min_order_qty ?? 1, max_order_qty: p.max_order_qty ?? 100 }
}
function variantPayload(v) {
  return { id: v.id, name: v.name, sku: v.sku, is_default: v.is_default, mrp: v.mrp, selling_price: v.selling_price, stock: v.stock_qty ?? v.stock, low_stock_threshold: v.low_stock_threshold ?? 1 }
}

console.log(`\n${passes} passed, ${failures} failed${notes.length ? `, ${notes.length} notes` : ''}`)
process.exit(failures ? 1 : 0)

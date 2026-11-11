// Get a screen ready BEFORE it is opened: the moment the pointer rests on a link (or the finger touches it) and again when the panel is idle after the
// first screen, the code of the screen is loaded and the answer of the call it will make is fetched. The click then finds both in memory.
// The calls here use the same parameters the screens use on a plain visit (first page, the remembered rows per page), so the screen's own call is
// answered from the same request (see `api.get` / `api.peek` / `api.prefetch` in api/client.js).
import { api } from '@/api/client.js'
import { storedPerPage } from '@/utils/perPage.js'

const list = (path, extra = {}) => () => api.prefetch(path, { ...extra, page: 1, per_page: storedPerPage() })

/** The data call(s) of each screen by route name (the first page of its default view). */
const DATA = {
  dashboard: () => api.prefetch('admin/dashboard', { range: '30d' }),
  orders: () => { list('admin/orders')(); api.prefetch('admin/orders/options', undefined, 10 * 60 * 1000) },
  products: list('admin/products'),
  categories: list('admin/categories'),
  customers: list('admin/customers'),
  wholesale: list('admin/wholesale'),
  discounts: list('admin/discounts'),
  coupons: list('admin/coupons'),
  settings: () => api.prefetch('admin/settings'),
  'access-control': () => api.prefetch('admin/access-control'),
}

/** Detail screens, from a link that carries the id. */
const DETAIL = {
  order: (id) => api.prefetch(`admin/orders/${id}`),
  'product-edit': (id) => api.prefetch(`admin/products/${id}`),
  'wholesale-request': (id) => api.prefetch(`admin/wholesale/${id}`),
  'discount-edit': (id) => api.prefetch(`admin/discounts/${id}`),
  'coupon-edit': (id) => api.prefetch(`admin/coupons/${id}`),
}

const loaded = new Set()

/** Load the code of the screens behind `route` (a resolved route). Importing the same module twice costs nothing. */
function loadChunks(route) {
  for (const record of route.matched) {
    const component = record.components?.default
    if (typeof component === 'function' && !loaded.has(record)) {
      loaded.add(record)
      Promise.resolve(component()).catch(() => loaded.delete(record))   // an offline moment: the real navigation tries again
    }
  }
}

/** `target` is a router location (`{ name }`, or `{ name, params: { id } }`). Safe to call on every hover: repeats are cheap. */
export function warm(router, target, auth) {
  try {
    const route = router.resolve(target)
    const permission = route.matched.map((r) => r.meta.permission).find(Boolean)
    if (permission && auth && !auth.can(permission)) return
    loadChunks(route)
    const id = route.params?.id
    if (id && DETAIL[route.name]) DETAIL[route.name](id)
    else if (DATA[route.name]) DATA[route.name]()
  } catch { /* a link that cannot be resolved is simply not warmed */ }
}

/** After the first screen, when the browser is idle: the code of the screens people use most, so the first click on them does not wait for a download. */
export function warmIdle(router, auth) {
  const run = () => ['orders', 'order', 'products', 'customers', 'discounts', 'settings'].forEach((name) => {
    try { loadChunks(router.resolve(name === 'order' ? { name, params: { id: 1 } } : { name })) } catch { /* not for this account */ }
  })
  if ('requestIdleCallback' in window) requestIdleCallback(run, { timeout: 4000 }); else setTimeout(run, 2500)
}

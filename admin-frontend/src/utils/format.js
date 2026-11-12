// Locale: everything uses the browser default (undefined) for number grouping and dates.
// Time zone: every date is shown, typed and compared in the STORE's time zone (APP_TIMEZONE, sent by GET /admin/auth/me), never the browser's,
// so "ends 23:59" means the same moment for everyone. Until it is known the browser's zone is used.
let zone
export function setTimezone(name) {
  try {
    new Intl.DateTimeFormat(undefined, { timeZone: name })
    zone = name || undefined
  } catch {
    zone = undefined
  }
}
export const timezone = () => zone || Intl.DateTimeFormat().resolvedOptions().timeZone

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

export function formatDate(value, withTime = false) {
  if (!value) return '-'
  const dateOnly = typeof value === 'string' && DATE_ONLY.test(value)   // a calendar day (e.g. the estimated delivery): no zone shift
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString(undefined, {
    day: '2-digit', month: 'short', year: 'numeric',
    ...(withTime && !dateOnly ? { hour: '2-digit', minute: '2-digit' } : {}),
    timeZone: dateOnly ? 'UTC' : zone,
  })
}

/** The calendar parts of an instant in the store's time zone. */
function shopParts(date) {
  const out = {}
  for (const p of new Intl.DateTimeFormat('en-US', {
    timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
  }).formatToParts(date)) out[p.type] = p.value
  return out
}

/** Today's date in the store's time zone as YYYY-MM-DD (the value of a date input). */
export function todayISO(date = new Date()) {
  const p = shopParts(date)
  return `${p.year}-${p.month}-${p.day}`
}

/** An instant (ISO text from the API) as the value of a datetime-local input: YYYY-MM-DDTHH:mm in the store's time zone. */
export function toInputValue(value) {
  if (!value) return ''
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return ''
  const p = shopParts(d)
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

/** The calendar day N days before today in the store's time zone (YYYY-MM-DD). */
export function daysAgoISO(days) {
  const [y, m, d] = todayISO().split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d - days)).toISOString().slice(0, 10)
}

export function timeAgo(value) {
  if (!value) return ''
  const seconds = Math.round((Date.now() - new Date(value).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  const steps = [[60, 'minute'], [3600, 'hour'], [86400, 'day'], [2592000, 'month']]
  for (let i = steps.length - 1; i >= 0; i--) {
    if (seconds >= steps[i][0]) {
      const n = Math.floor(seconds / steps[i][0])
      return `${n} ${steps[i][1]}${n > 1 ? 's' : ''} ago`
    }
  }
  return ''
}

export function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB']
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1)
  return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`
}

export function initials(name = '') {
  // the first letter of the first two words; a lone "&" or "-" is not a word
  return name.split(/\s+/).filter(p => /[\p{L}\p{N}]/u.test(p[0] || '')).slice(0, 2).map(p => p[0].toUpperCase()).join('') || '?'
}

/** wa.me link for a phone number (digits only) with a ready message. Null when the number has no digits. */
export function whatsappUrl(phone, text = '') {
  const digits = String(phone || '').replace(/\D+/g, '')
  if (digits.length < 7) return null
  return `https://wa.me/${digits}${text ? `?text=${encodeURIComponent(text)}` : ''}`
}

export const ACTION_LABELS = {
  'auth.password_changed': 'changed their password',
  'orders.exported': 'downloaded the orders list',
  'wholesale.updated': 'updated wholesale request',
  'wholesale.exported': 'downloaded the wholesale requests',
  'notifications.test_email': 'sent a test e-mail',
  'product.sold_out_changed': 'changed the sold-out switch of product',
  'product.featured_changed': 'changed the featured switch of product',
  'product.video_added': 'added a video to product',
  'product.video_deleted': 'deleted a video of product',
  'product.video_poster_changed': 'changed a video poster of product',
  'product.videos_reordered': 'reordered the videos of product',
  'customers.abandoned_carts_exported': 'downloaded the abandoned carts',
  'category.created': 'added category',
  'category.updated': 'updated category',
  'category.deleted': 'deleted category',
  'subcategory.created': 'added sub-category',
  'subcategory.updated': 'updated sub-category',
  'subcategory.deleted': 'deleted sub-category',
  'product.created': 'added product',
  'product.updated': 'updated product',
  'product.deleted': 'deleted product',
  'product.status_changed': 'changed the status of product',
  'product.tags_changed': 'changed the tags of product',
  'product.images_added': 'added pictures to product',
  'product.image_deleted': 'deleted a picture of product',
  'product.images_reordered': 'reordered the pictures of product',
  'banner.created': 'added banner',
  'banner.updated': 'updated banner',
  'banner.deleted': 'deleted banner',
  'banner.reordered': 'reordered the banners',
  'tag.created': 'added tag',
  'tag.updated': 'updated tag',
  'tag.deleted': 'deleted tag',
  'settings.updated': 'updated the store settings',
  'settings.file_updated': 'changed a store picture (logo, favicon or share image)',
  'settings.file_removed': 'removed a store picture',
  'access.updated': 'changed what a role may access',
  'customers.exported': 'downloaded the customers list',
  'order.status_changed': 'changed the status of order',
  'order.cancelled': 'cancelled order',
  'order.note_updated': 'updated the note of order',
  'inventory.low_stock': 'was told that an option is running low after a sale',
}

// Money: the symbol and number of decimals come from the store's currency settings (loaded from the API).
let currency = { symbol: '$', decimals: 2 }
export function setCurrency(next) {
  if (!next) return
  const decimals = Number(next.decimals)
  currency = {
    symbol: next.symbol || currency.symbol,
    decimals: Number.isInteger(decimals) && decimals >= 0 && decimals <= 6 ? decimals : currency.decimals,
  }
}
export const currencySymbol = () => currency.symbol

export function money(value) {
  if (value === null || value === undefined || value === '') return '-'
  const n = Number(value)
  if (Number.isNaN(n)) return '-'
  const text = Math.abs(n).toLocaleString(undefined, { minimumFractionDigits: currency.decimals, maximumFractionDigits: currency.decimals })
  const gap = /[A-Za-z]$/.test(currency.symbol) ? ' ' : '' // "USD 5.00" but "$5.00"
  return `${n < 0 ? '-' : ''}${currency.symbol}${gap}${text}`
}

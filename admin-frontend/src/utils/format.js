// Locale: everything uses the browser default (undefined) for number grouping and dates.
export function formatDate(value, withTime = false) {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString(undefined, {
    day: '2-digit', month: 'short', year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  })
}

/** Today's date in the browser's time zone as YYYY-MM-DD (the value of a date input). */
export function todayISO() {
  const d = new Date()
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
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

export const ACTION_LABELS = {
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
  'settings.logo_updated': 'changed the store logo',
  'settings.logo_removed': 'removed the store logo',
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

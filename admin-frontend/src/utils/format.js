export function formatDate(value, withTime = false) {
  if (!value) return '-'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return '-'
  return d.toLocaleString('en-IN', {
    day: '2-digit', month: 'short', year: 'numeric',
    ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
  })
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
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map(p => p[0]?.toUpperCase()).join('') || '?'
}

export const ACTION_LABELS = {
  'company.registered': 'registered a new company request',
  'company.created': 'created company',
  'company.approved': 'approved company',
  'company.rejected': 'rejected company',
  'company.suspended': 'suspended company',
  'company.updated': 'updated company',
  'company.deleted': 'deleted company',
  'brand.created': 'added brand',
  'brand.updated': 'updated brand',
  'brand.deleted': 'deleted brand',
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
  'tag.created': 'added tag',
  'tag.updated': 'updated tag',
  'tag.deleted': 'deleted tag',
  'settings.delivery_updated': 'updated delivery settings',
  'settings.commerce_updated': 'updated the shop settings',
  'order.status_changed': 'changed the status of order',
  'order.cancelled': 'cancelled order',
  'order.note_updated': 'updated the note of order',
  'inventory.low_stock': 'was told that an option is running low after a sale',
}

// Money: the symbol comes from the site settings (currency_symbol, default Rs for PKR) and is set once when the product screens load.
let currencySymbol = 'Rs'
export const setCurrency = (symbol) => { if (symbol) currencySymbol = symbol }
export function money(value) {
  if (value === null || value === undefined || value === '') return '-'
  return `${currencySymbol} ${Number(value).toLocaleString('en-PK', { maximumFractionDigits: 2 })}`
}

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
  'settings.delivery_updated': 'updated delivery settings',
}

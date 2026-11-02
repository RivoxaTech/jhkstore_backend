// Fetch wrapper for the Laravel API.
//
// Authentication uses an HttpOnly session cookie set by the backend (JavaScript never sees it).
// State-changing requests also send the CSRF token (XSRF-TOKEN cookie -> X-XSRF-TOKEN header).
//
// In dev, Vite proxies /api and /sanctum to the backend (see vite.config.js), so the browser talks to
// one origin and the cookie just works. In production Laravel serves this app at /admin, also one origin.
const BASE = (import.meta.env.VITE_API_URL || '/api/v1').replace(/\/$/, '')
const CSRF_URL = (BASE.startsWith('http') ? new URL(BASE).origin : '') + '/sanctum/csrf-cookie'

export class ApiError extends Error {
  constructor(status, data) {
    super(data?.message || `Request failed (${status})`)
    this.status = status
    this.errors = data?.errors || {}
    this.data = data
  }
  /** First validation message of a field (works for nested keys like "variants.0.name"). */
  firstError(field) {
    return this.errors?.[field]?.[0] || ''
  }
}

let unauthorizedHandler = () => {}
export const onUnauthorized = (fn) => { unauthorizedHandler = fn }

const readCookie = (name) => {
  const match = document.cookie.split('; ').find(c => c.startsWith(name + '='))
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfPromise = null
async function ensureCsrf(force = false) {
  if (!force && readCookie('XSRF-TOKEN')) return
  csrfPromise ||= fetch(CSRF_URL, { credentials: 'include', headers: { Accept: 'application/json' } })
    .finally(() => { csrfPromise = null })
  await csrfPromise
}

function buildUrl(path, query) {
  const url = new URL(`${BASE}/${path.replace(/^\//, '')}`, window.location.origin)
  Object.entries(query || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, v)
  })
  return url.toString()
}

async function request(method, path, { query, json, form, raw, retried } = {}) {
  const isWrite = method !== 'GET'
  if (isWrite) await ensureCsrf()

  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (isWrite) headers['X-XSRF-TOKEN'] = readCookie('XSRF-TOKEN') || ''

  let body
  if (form) body = form // the browser sets the multipart boundary
  else if (json !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json) }

  let res
  try {
    res = await fetch(buildUrl(path, query), { method, headers, body, credentials: 'include' })
  } catch {
    throw new ApiError(0, { message: 'Cannot reach the server. Check that the backend is running.' })
  }

  // 419 = CSRF token expired/missing: fetch a fresh one and retry once.
  if (res.status === 419 && !retried) {
    await ensureCsrf(true)
    return request(method, path, { query, json, form, raw, retried: true })
  }

  if (raw && res.ok) return res

  const data = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) {
    if (res.status === 401) unauthorizedHandler(path)
    throw new ApiError(res.status, data)
  }
  return data
}

export const api = {
  get: (path, query) => request('GET', path, { query }),
  post: (path, json) => request('POST', path, { json }),
  put: (path, json) => request('PUT', path, { json }),
  patch: (path, json) => request('PATCH', path, { json }),
  del: (path) => request('DELETE', path),
  /** multipart POST (use `_method=PUT` inside the FormData when updating with files) */
  postForm: (path, form) => request('POST', path, { form }),
  /** Download a private file (the cookie authenticates it) and hand it to the browser. */
  async download(path, fallbackName = 'file') {
    const res = await request('GET', path, { raw: true })
    const blob = await res.blob()
    const disposition = res.headers.get('Content-Disposition') || ''
    const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
    const name = match ? decodeURIComponent(match[1]) : fallbackName
    const link = document.createElement('a')
    link.href = URL.createObjectURL(blob)
    link.download = name
    document.body.appendChild(link)
    link.click()
    link.remove()
    setTimeout(() => URL.revokeObjectURL(link.href), 4000)
  },
}

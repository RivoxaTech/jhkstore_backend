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

// The last answer of every GET, kept in memory for this visit (never persisted; a logout or an expired session empties it). A screen shows the last
// answer at once (`api.peek`) and the fresh one replaces it a moment later: coming back to a screen never waits on a skeleton. Identical GETs that are
// still in flight share one request, so a prefetch (hover, idle) and the screen that opens a moment later cost one call, not two.
const MEMO_MAX = 120
const memo = new Map()      // url -> { at, data }
const inflight = new Map()  // url -> Promise
const remember = (url, data) => { memo.delete(url); memo.set(url, { at: Date.now(), data }); if (memo.size > MEMO_MAX) memo.delete(memo.keys().next().value) }
export const forgetAnswers = () => { memo.clear(); inflight.clear() }
/** A write to `admin/discounts/5` makes every remembered answer about `admin/discounts` out of date (the screen that comes next must not show it first). */
function forgetFamily(path) {
  const [a, b] = String(path).replace(/^\//, '').split('/')
  const needle = `/${a}/${b}`
  for (const key of [...memo.keys()]) if (key.includes(needle)) memo.delete(key)
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
  if (isWrite) { inflight.clear(); await ensureCsrf() }   // a write may change what a GET that is already on its way will answer: nobody may share that one

  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
  if (isWrite) headers['X-XSRF-TOKEN'] = readCookie('XSRF-TOKEN') || ''

  let body
  if (form) body = form // the browser sets the multipart boundary
  else if (json !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json) }

  let res
  try {
    res = await fetch(buildUrl(path, query), { method, headers, body, credentials: 'include', cache: 'no-store' })   // the panel never reads a cached answer (the public settings are cacheable for the storefront)
  } catch {
    throw new ApiError(0, { message: 'Cannot reach the server. Check that the backend is running.' })
  }

  // 419 = CSRF token expired/missing: fetch a fresh one and retry once.
  if (res.status === 419 && !retried) {
    await ensureCsrf(true)
    return request(method, path, { query, json, form, raw, retried: true })
  }

  if (isWrite) { inflight.clear(); if (res.ok) forgetFamily(path) }
  if (raw && res.ok) return res

  const data = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) {
    if (res.status === 401) { forgetAnswers(); unauthorizedHandler(path) }
    throw new ApiError(res.status, data)
  }
  return data
}

async function cachedGet(path, query) {
  const key = buildUrl(path, query)
  if (inflight.has(key)) return inflight.get(key)
  const p = request('GET', path, { query }).then((data) => { remember(key, data); return data }).finally(() => { if (inflight.get(key) === p) inflight.delete(key) })
  inflight.set(key, p)
  return p
}

export const api = {
  get: (path, query) => cachedGet(path, query),
  /** The last answer of this GET in this visit (a copy: change it freely), or undefined. Use it to show something at once, then call `get` for the fresh one. */
  peek(path, query) {
    const hit = memo.get(buildUrl(path, query))
    return hit ? structuredClone(hit.data) : undefined
  },
  /** Warm the answer of a GET that is likely to be needed next (a link under the pointer). Skipped when the last answer is younger than `maxAge` ms. Never throws. */
  prefetch(path, query, maxAge = 4000) {
    const hit = memo.get(buildUrl(path, query))
    if (hit && Date.now() - hit.at < maxAge) return Promise.resolve()
    return cachedGet(path, query).then(() => {}, () => {})
  },
  post: (path, json) => request('POST', path, { json }),
  put: (path, json) => request('PUT', path, { json }),
  patch: (path, json) => request('PATCH', path, { json }),
  del: (path) => request('DELETE', path),
  /** multipart POST (use `_method=PUT` inside the FormData when updating with files) */
  postForm: (path, form) => request('POST', path, { form }),
  /**
   * Like postForm, but reports the upload progress (0 to 100) to `onProgress` (fetch cannot): for a large file such as a product video.
   * Answers like the other calls (parsed JSON, or an ApiError with the validation messages).
   */
  async postFormProgress(path, form, onProgress) {
    inflight.clear()
    await ensureCsrf()
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest()
      xhr.open('POST', buildUrl(path))
      xhr.withCredentials = true
      xhr.setRequestHeader('Accept', 'application/json')
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest')
      xhr.setRequestHeader('X-XSRF-TOKEN', readCookie('XSRF-TOKEN') || '')
      xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(Math.round((e.loaded / e.total) * 100)) }
      xhr.onerror = () => reject(new ApiError(0, { message: 'Cannot reach the server. Check that the backend is running.' }))
      xhr.onload = () => {
        let data = null
        try { data = JSON.parse(xhr.responseText) } catch { /* not JSON */ }
        if (xhr.status >= 200 && xhr.status < 300) return resolve(data)
        if (xhr.status === 401) unauthorizedHandler(path)
        // a file larger than the PHP limits is refused before Laravel sees it: say so in plain words
        if (xhr.status === 413) return reject(new ApiError(413, { message: 'The file is larger than the server allows. Ask your developer to raise upload_max_filesize and post_max_size.' }))
        reject(new ApiError(xhr.status, data))
      }
      xhr.send(form)
    })
  },
  /** A private file (the cookie authenticates it) as a Blob, e.g. to show a payment proof in an <img> through an object URL. */
  async blob(path) {
    const res = await request('GET', path, { raw: true })
    return res.blob()
  },
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

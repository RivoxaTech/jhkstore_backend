/*
 * Service worker of the admin panel (scope /admin/). Two jobs:
 *  1. It makes the panel an installable app (PWA): when the panel is open and the network is gone, a page load shows a small "You are offline"
 *     page (admin-offline.html, kept in its own cache) instead of the browser's error. Nothing else is cached or touched: the API, the scripts,
 *     the pictures and every order always come from the network, so the panel never shows old data.
 *  2. It shows the push notifications the server sends (new order, payment proof to check, new wholesale request, the test notification) and
 *     opens the right screen when one is clicked. The browser asks for permission only when an admin presses the bell button.
 *
 * A notification stays on screen until it is clicked (`requireInteraction`), carries the store logo as its icon when the server sends one, and has
 * a `tag` per order (a newer message about the same order replaces the older one). The sound is the system's own: a site cannot change it. So the
 * worker also tells every open panel tab about the message, and the panel plays its own chime (see AdminLayout.vue).
 */
const CACHE = 'admin-offline-v1'
const OFFLINE = '/admin-offline.html'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))).catch(() => { /* no offline page: the browser's own message shows */ }).then(() => self.skipWaiting()),
  )
})
self.addEventListener('activate', (event) => event.waitUntil(
  caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
))

// only the loading of a page is handled (and only to show the offline page when the network is gone); every other request is left to the browser
self.addEventListener('fetch', (event) => {
  if (event.request.mode !== 'navigate') return
  event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE).then((page) => page || Response.error())))
})

// a path of this site only (never //other-site), else the panel's start page
const safePath = (value) => (typeof value === 'string' && /^\/(?!\/)/.test(value) ? value : '/admin/')
// an icon is the store logo: an http(s) address or a path of this site
const safeIcon = (value) => (typeof value === 'string' && /^(https?:\/\/|\/(?!\/))/i.test(value) ? value : undefined)

// the open tabs of the panel (their address is inside the scope this worker was registered for)
const panelTabs = () => self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((tabs) => tabs.filter((t) => t.url.startsWith(self.registration.scope)))

self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch { data = { body: event.data ? event.data.text() : '' } }
  const icon = safeIcon(data.icon)
  const url = safePath(data.url)

  event.waitUntil(Promise.all([
    self.registration.showNotification(data.title || 'New notification', {
      body: data.body || '',
      tag: data.tag || undefined,
      renotify: Boolean(data.tag),         // a newer message with the same tag replaces the older one and alerts again
      requireInteraction: true,            // stays until it is clicked or dismissed
      icon,
      badge: icon,
      data: { url },
    }),
    panelTabs().then((tabs) => tabs.forEach((tab) => tab.postMessage({ type: 'push', title: data.title || '', body: data.body || '', url, tag: data.tag || '' }))),
  ]))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = safePath(event.notification.data?.url)

  event.waitUntil(panelTabs().then((tabs) => {
    const open = tabs.find((t) => t.visibilityState === 'visible') || tabs[0]
    if (open) {
      // focus the tab that is already open and let the panel go to the order (no second tab)
      return open.focus().then(() => open.postMessage({ type: 'navigate', url })).catch(() => self.clients.openWindow(new URL(url, self.location.origin).href))
    }
    return self.clients.openWindow(new URL(url, self.location.origin).href)
  }))
})

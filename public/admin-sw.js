/*
 * Service worker of the admin panel (scope /admin/): shows the push notifications the server sends (new order, payment proof to check, new
 * wholesale request) and opens the right screen when one is clicked. It caches nothing and does not touch any request.
 * The panel registers it only when an admin presses the bell button (that is also when the browser asks for permission).
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch { data = { body: event.data ? event.data.text() : '' } }

  event.waitUntil(self.registration.showNotification(data.title || 'New notification', {
    body: data.body || '',
    tag: data.tag || undefined,          // a newer message with the same tag replaces the older one
    // a path on this site only, never //other-site
    data: { url: typeof data.url === 'string' && /^\/(?!\/)/.test(data.url) ? data.url : '/admin/' },
  }))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/admin/', self.location.origin).href

  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
    const open = windows.find((w) => new URL(w.url).pathname.startsWith('/admin'))
    if (open) {
      return open.focus().then(() => ('navigate' in open ? open.navigate(url) : undefined))
    }
    return self.clients.openWindow(url)
  }))
})

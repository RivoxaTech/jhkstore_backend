import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router, { updateDocumentTitle } from './router/index.js'
import { onUnauthorized } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'
import { useUiStore } from '@/stores/ui.js'
import { installPopovers } from '@/utils/popovers.js'
import './assets/styles.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// If the session expires while the admin is working, send them back to the login page.
onUnauthorized((path) => {
  if (String(path).includes('auth/login')) return
  const auth = useAuthStore()
  if (!auth.isLoggedIn) return
  auth.clear()
  router.replace({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
})

// Public store settings (name, logo, currency): loaded once before login to label the panel.
const site = useSiteStore()
site.load()
watch(() => site.rawName, () => updateDocumentTitle())

// On a phone every table is drawn as cards: each cell carries the text of its column heading (CSS shows it as a small label). Done here once for every table.
const phone = window.matchMedia('(max-width: 720px)')
let labelling = 0
function labelTables() {
  labelling = 0
  if (!phone.matches) return
  document.querySelectorAll('table.table').forEach((t) => {
    const heads = [...t.querySelectorAll('thead th')].map((th) => th.textContent.trim())
    t.querySelectorAll('tbody tr').forEach((tr) => [...tr.children].forEach((td, i) => { if (heads[i] !== undefined && td.getAttribute('data-label') !== heads[i]) td.setAttribute('data-label', heads[i]) }))
  })
}
new MutationObserver(() => { if (!labelling) labelling = requestAnimationFrame(labelTables) }).observe(document.body, { childList: true, subtree: true })
phone.addEventListener?.('change', labelTables)

installPopovers()   // the panel's own drop-down list and calendar (utils/popovers.js)

// The panel is an installable app (PWA): the browser offers "Install" through this event (the button sits in the top bar), and the service worker
// (the offline page and the push notifications) is registered as soon as the panel opens.
const ui = useUiStore()
window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); ui.installEvent = e })
window.addEventListener('appinstalled', () => { ui.installEvent = null })
if (import.meta.env.PROD && window.isSecureContext && 'serviceWorker' in navigator) {
  window.addEventListener('load', () => navigator.serviceWorker.register('/admin-sw.js', { scope: '/admin/' }).catch(() => { /* the panel works without it */ }))
}

app.mount('#app')

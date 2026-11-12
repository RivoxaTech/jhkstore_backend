import { createApp, watch } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router, { updateDocumentTitle } from './router/index.js'
import { onUnauthorized } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'
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

app.mount('#app')

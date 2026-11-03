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
watch(() => site.name, () => updateDocumentTitle())

app.mount('#app')

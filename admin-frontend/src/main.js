import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router/index.js'
import { onUnauthorized } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
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

app.mount('#app')

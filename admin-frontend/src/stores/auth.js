import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'

/** Login state lives in the HttpOnly session cookie; this store only mirrors "who am I" for the UI. */
export const useAuthStore = defineStore('auth', () => {
  const admin = ref(null)
  const ready = ref(false) // true after the first "who am I" check finished

  const isLoggedIn = computed(() => Boolean(admin.value))
  const initials = computed(() => (admin.value?.name || admin.value?.username || 'A').slice(0, 2).toUpperCase())

  async function login(username, password) {
    admin.value = (await api.post('admin/auth/login', { login: username, password })).admin
    ready.value = true
  }

  /** Asks the server whether the cookie session is valid. Runs once on app start. */
  async function restore() {
    if (ready.value) return
    try {
      admin.value = (await api.get('admin/auth/me')).admin
    } catch {
      admin.value = null
    } finally {
      ready.value = true
    }
  }

  async function logout() {
    try { await api.post('admin/auth/logout') } catch { /* session may already be gone */ }
    clear()
  }

  function clear() {
    admin.value = null
  }

  return { admin, ready, isLoggedIn, initials, login, restore, logout, clear }
})

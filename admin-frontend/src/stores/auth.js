import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'
import { setTimezone } from '@/utils/format.js'
import { forgetLists } from '@/utils/listCache.js'

/** Login state lives in the HttpOnly session cookie; this store only mirrors "who am I" for the UI. */
export const useAuthStore = defineStore('auth', () => {
  const admin = ref(null)
  const ready = ref(false) // true after the first "who am I" check finished

  const isLoggedIn = computed(() => Boolean(admin.value))
  const role = computed(() => admin.value?.roles?.[0] || '')
  const isDeveloper = computed(() => role.value === 'developer')
  const permissions = computed(() => admin.value?.permissions || [])
  /** What this admin may do (the server decides; this only hides what would answer 403). */
  const can = (permission) => (permission.endsWith('.*') ? permissions.value.some(p => p.startsWith(permission.slice(0, -1))) : permissions.value.includes(permission))
  const initials = computed(() => (admin.value?.name || admin.value?.username || 'A').slice(0, 2).toUpperCase())

  async function login(username, password) {
    admin.value = (await api.post('admin/auth/login', { login: username, password })).admin
    setTimezone(admin.value?.timezone)
    ready.value = true
  }

  /** Asks the server whether the cookie session is valid. Runs once on app start. */
  async function restore() {
    if (ready.value) return
    try {
      admin.value = (await api.get('admin/auth/me')).admin
      setTimezone(admin.value?.timezone)
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
    forgetLists()   // the remembered lists belong to the account that just left
  }

  return { admin, ready, isLoggedIn, role, isDeveloper, permissions, can, initials, login, restore, logout, clear }
})

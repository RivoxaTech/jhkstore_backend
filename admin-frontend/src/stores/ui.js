import { defineStore } from 'pinia'
import { ref } from 'vue'

let nextId = 1

const read = (key) => { try { return localStorage.getItem(key) } catch { return null } }
const write = (key, value) => { try { localStorage.setItem(key, value) } catch { /* private window: the choice is kept for this visit only */ } }

export const useUiStore = defineStore('ui', () => {
  const sidebarCollapsed = ref(read('admin_sidebar') === '1')
  const mobileSidebarOpen = ref(false)
  const toasts = ref([])
  const pendingOrders = ref(0) // pending orders + payment proofs to check: badge on the Orders item and the bell
  const newWholesale = ref(0)  // wholesale requests nobody has looked at yet: badge on the Wholesale item
  // Counters that go up whenever the server says orders / wholesale requests changed: the screens that list them watch these and reload quietly (real time)
  const ordersVersion = ref(0)
  const wholesaleVersion = ref(0)
  const productsVersion = ref(0)   // moves when pictures finish uploading in the background: an open product list reloads quietly
  // In-panel alert sound: a short chime when something new arrives while the panel is open. The choice is kept in this browser only.
  const soundMuted = ref(read('admin_sound_muted') === '1')
  const soundReady = ref(typeof navigator !== 'undefined' && navigator.userActivation?.hasBeenActive === true) // browsers play sound only after a click on the page

  // light or dark panel: kept in this browser, applied to <html data-theme> (index.html applies it before the first paint)
  const theme = ref(read('admin_theme') === 'dark' ? 'dark' : 'light')
  function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark'
    write('admin_theme', theme.value)
    document.documentElement.dataset.theme = theme.value
  }

  function toggleSidebar() {
    if (window.innerWidth < 960) {
      mobileSidebarOpen.value = !mobileSidebarOpen.value
    } else {
      sidebarCollapsed.value = !sidebarCollapsed.value
      write('admin_sidebar', sidebarCollapsed.value ? '1' : '0')
    }
  }

  function setMuted(value) {
    soundMuted.value = Boolean(value)
    write('admin_sound_muted', soundMuted.value ? '1' : '0')
  }

  function toast(message, type = 'success', ms = 4000) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    setTimeout(() => dismiss(id), ms)
  }

  const dismiss = (id) => { toasts.value = toasts.value.filter(t => t.id !== id) }

  return { sidebarCollapsed, mobileSidebarOpen, toasts, pendingOrders, newWholesale, ordersVersion, wholesaleVersion, productsVersion, soundMuted, soundReady, theme, toggleTheme, setMuted, toggleSidebar, toast, dismiss }
})

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
  // In-panel alert sound: a short chime when something new arrives while the panel is open. The choice is kept in this browser only.
  const soundMuted = ref(read('admin_sound_muted') === '1')
  const soundReady = ref(typeof navigator !== 'undefined' && navigator.userActivation?.hasBeenActive === true) // browsers play sound only after a click on the page

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

  return { sidebarCollapsed, mobileSidebarOpen, toasts, pendingOrders, newWholesale, soundMuted, soundReady, setMuted, toggleSidebar, toast, dismiss }
})

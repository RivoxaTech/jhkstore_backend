import { defineStore } from 'pinia'
import { ref } from 'vue'

let nextId = 1

export const useUiStore = defineStore('ui', () => {
  const sidebarCollapsed = ref(localStorage.getItem('admin_sidebar') === '1')
  const mobileSidebarOpen = ref(false)
  const toasts = ref([])
  const pendingOrders = ref(0) // pending orders + payment proofs to check: badge on the Orders item and the bell
  const newWholesale = ref(0)  // wholesale requests nobody has looked at yet: badge on the Wholesale item

  function toggleSidebar() {
    if (window.innerWidth < 960) {
      mobileSidebarOpen.value = !mobileSidebarOpen.value
    } else {
      sidebarCollapsed.value = !sidebarCollapsed.value
      localStorage.setItem('admin_sidebar', sidebarCollapsed.value ? '1' : '0')
    }
  }

  function toast(message, type = 'success', ms = 4000) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    setTimeout(() => dismiss(id), ms)
  }

  const dismiss = (id) => { toasts.value = toasts.value.filter(t => t.id !== id) }

  return { sidebarCollapsed, mobileSidebarOpen, toasts, pendingOrders, newWholesale, toggleSidebar, toast, dismiss }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'

let nextId = 1

export const useUiStore = defineStore('ui', () => {
  const sidebarCollapsed = ref(localStorage.getItem('admin_sidebar') === '1')
  const mobileSidebarOpen = ref(false)
  const toasts = ref([])
  const pendingOrders = ref(0) // orders waiting for a decision, badge on the Orders item and the bell

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

  return { sidebarCollapsed, mobileSidebarOpen, toasts, pendingOrders, toggleSidebar, toast, dismiss }
})

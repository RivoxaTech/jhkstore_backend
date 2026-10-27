<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { Bell, Building2, ChevronsLeft, ChevronsRight, LayoutDashboard, LogOut, Menu, Search, Stethoscope } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { api } from '@/api/client.js'

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()

const query = ref('')
const menuOpen = ref(false)
const menuEl = ref(null)

// Keeps the "pending requests" badge fresh (sidebar + bell). One light call every minute.
async function refreshPending() {
  try {
    const res = await api.get('admin/companies', { status: 'pending', per_page: 1 })
    ui.pendingCount = res.counts?.pending ?? 0
  } catch { /* the badge is optional */ }
}
let timer
onMounted(() => {
  refreshPending()
  timer = setInterval(refreshPending, 60000)
  document.addEventListener('click', closeMenu)
})
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('click', closeMenu) })
watch(() => route.fullPath, () => { ui.mobileSidebarOpen = false; menuOpen.value = false })

const closeMenu = (e) => { if (menuEl.value && !menuEl.value.contains(e.target)) menuOpen.value = false }

function search() {
  const q = query.value.trim()
  router.push({ name: 'companies', query: q ? { q } : {} })
}

async function logout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-shell" :class="{ 'is-collapsed': ui.sidebarCollapsed, 'mobile-open': ui.mobileSidebarOpen }">
    <aside class="sidebar" aria-label="Main navigation">
      <div class="sidebar__brand">
        <div class="brand-mark"><Stethoscope :size="20" /></div>
        <div class="brand-text">Dentalkart<small>Admin</small></div>
      </div>
      <nav class="sidebar__nav">
        <p class="nav-label">Main</p>
        <RouterLink :to="{ name: 'dashboard' }" class="nav-item" :class="{ 'is-active': route.name === 'dashboard' }" exact-active-class="" active-class="">
          <LayoutDashboard :size="19" /><span>Dashboard</span>
        </RouterLink>

        <p class="nav-label" style="margin-top: 18px">Catalogue</p>
        <RouterLink :to="{ name: 'companies' }" class="nav-item" :class="{ 'is-active': String(route.name).startsWith('compan') }" exact-active-class="" active-class="">
          <Building2 :size="19" /><span>Companies</span>
          <b v-if="ui.pendingCount" class="nav-badge" :title="`${ui.pendingCount} pending requests`">{{ ui.pendingCount }}</b>
        </RouterLink>
      </nav>
      <div class="sidebar__foot">
        <button class="collapse-btn" type="button" :title="ui.sidebarCollapsed ? 'Expand menu' : 'Collapse menu'" :aria-label="ui.sidebarCollapsed ? 'Expand menu' : 'Collapse menu'" @click="ui.toggleSidebar()">
          <ChevronsRight v-if="ui.sidebarCollapsed" :size="18" /><ChevronsLeft v-else :size="18" />
          <span>Collapse</span>
        </button>
      </div>
    </aside>
    <div class="backdrop" @click="ui.mobileSidebarOpen = false" />

    <div class="main">
      <header class="topbar">
        <!-- Phones only: opens the sidebar drawer. On desktop the collapse button lives inside the sidebar. -->
        <button class="icon-btn mobile-only" type="button" aria-label="Open menu" @click.stop="ui.toggleSidebar()"><Menu :size="18" /></button>
        <form class="search" role="search" @submit.prevent="search">
          <input v-model="query" type="search" placeholder="Search companies by name, GST, email..." aria-label="Quick search (press Enter)" />
          <Search :size="18" />
        </form>
        <div class="topbar__space" />
        <RouterLink class="icon-btn" :to="{ name: 'companies', query: { status: 'pending' } }" :title="`${ui.pendingCount} pending requests`" aria-label="Pending requests">
          <Bell :size="17" />
          <span v-if="ui.pendingCount" class="dot">{{ ui.pendingCount > 99 ? '99+' : ui.pendingCount }}</span>
        </RouterLink>
        <div ref="menuEl" class="user-menu">
          <button class="user-btn" type="button" aria-haspopup="menu" :aria-expanded="menuOpen" @click.stop="menuOpen = !menuOpen">
            <span class="avatar">{{ auth.initials }}</span>
            <strong>{{ auth.admin?.name || auth.admin?.username }}</strong>
          </button>
          <div v-if="menuOpen" class="dropdown" role="menu">
            <div class="dropdown__head">
              <strong>{{ auth.admin?.name }}</strong>
              <small>@{{ auth.admin?.username }}</small>
            </div>
            <button type="button" role="menuitem" @click="logout"><LogOut :size="17" /> Sign out</button>
          </div>
        </div>
      </header>

      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

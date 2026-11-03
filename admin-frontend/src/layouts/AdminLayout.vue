<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { BadgePercent, Bell, ChevronsLeft, ChevronsRight, FolderTree, KeyRound, LayoutDashboard, LogOut, Menu, Package, Search, Settings, ShoppingBag, Ticket, Users } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import StoreMark from '@/components/StoreMark.vue'
import { api } from '@/api/client.js'

const auth = useAuthStore()
const ui = useUiStore()
const catalog = useCatalogStore()
const site = useSiteStore()
const route = useRoute()
const router = useRouter()

const query = ref('')
const menuOpen = ref(false)
const menuEl = ref(null)

// Keeps the "pending orders" badge fresh (sidebar + bell). One light call every minute.
async function refreshPending() {
  if (!auth.can('orders.view')) return
  try {
    const orders = await api.get('admin/orders', { per_page: 1 })
    ui.pendingOrders = orders.counts?.pending ?? 0
  } catch { /* the badges are optional */ }
}
let timer
onMounted(() => {
  if (auth.can('products.view') || auth.can('categories.view')) catalog.load().catch(() => { /* the features fall back to the public store settings */ })
  refreshPending()
  timer = setInterval(refreshPending, 60000)
  document.addEventListener('click', closeMenu)
})
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('click', closeMenu) })
watch(() => route.fullPath, () => { ui.mobileSidebarOpen = false; menuOpen.value = false })

const closeMenu = (e) => { if (menuEl.value && !menuEl.value.contains(e.target)) menuOpen.value = false }

function search() {
  const q = query.value.trim()
  router.push({ name: 'products', query: q ? { q } : {} })
}

const roleLabel = computed(() => ({ developer: 'Developer', admin: 'Admin' })[auth.role] || auth.role)

async function logout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-shell" :class="{ 'is-collapsed': ui.sidebarCollapsed, 'mobile-open': ui.mobileSidebarOpen }">
    <aside class="sidebar" aria-label="Main navigation">
      <div class="sidebar__brand">
        <StoreMark />
        <div class="brand-text">{{ site.name }}<small>Admin</small></div>
      </div>
      <nav class="sidebar__nav">
        <RouterLink v-if="auth.can('dashboard.view')" :to="{ name: 'dashboard' }" class="nav-item" :class="{ 'is-active': route.name === 'dashboard' }" exact-active-class="" active-class="">
          <LayoutDashboard :size="19" /><span>Dashboard</span>
        </RouterLink>
        <RouterLink v-if="auth.can('orders.view')" :to="{ name: 'orders' }" class="nav-item" :class="{ 'is-active': route.name === 'orders' || route.name === 'order' }" exact-active-class="" active-class="">
          <ShoppingBag :size="19" /><span>Orders</span>
          <b v-if="ui.pendingOrders" class="nav-badge" :title="`${ui.pendingOrders} orders waiting`">{{ ui.pendingOrders }}</b>
        </RouterLink>
        <RouterLink v-if="auth.can('products.view')" :to="{ name: 'products' }" class="nav-item" :class="{ 'is-active': String(route.name).startsWith('product') }" exact-active-class="" active-class="">
          <Package :size="19" /><span>Products</span>
        </RouterLink>
        <RouterLink v-if="auth.can('categories.view')" :to="{ name: 'categories' }" class="nav-item" :class="{ 'is-active': route.name === 'categories' }" exact-active-class="" active-class="">
          <FolderTree :size="19" /><span>Categories</span>
        </RouterLink>
        <RouterLink v-if="auth.can('customers.view')" :to="{ name: 'customers' }" class="nav-item" :class="{ 'is-active': route.name === 'customers' }" exact-active-class="" active-class="">
          <Users :size="19" /><span>Customers</span>
        </RouterLink>
        <RouterLink v-if="auth.can('discounts.view')" :to="{ name: 'discounts' }" class="nav-item" :class="{ 'is-active': route.name === 'discounts' }" exact-active-class="" active-class="">
          <BadgePercent :size="19" /><span>Discounts</span>
        </RouterLink>
        <RouterLink v-if="auth.can('coupons.view')" :to="{ name: 'coupons' }" class="nav-item" :class="{ 'is-active': route.name === 'coupons' }" exact-active-class="" active-class="">
          <Ticket :size="19" /><span>Coupons</span>
        </RouterLink>
        <RouterLink v-if="auth.can('settings.*')" :to="{ name: 'settings' }" class="nav-item" :class="{ 'is-active': route.name === 'settings' }" exact-active-class="" active-class="">
          <Settings :size="19" /><span>Settings</span>
        </RouterLink>
        <RouterLink v-if="auth.can('access.manage')" :to="{ name: 'access-control' }" class="nav-item" :class="{ 'is-active': route.name === 'access-control' }" exact-active-class="" active-class="">
          <KeyRound :size="19" /><span>Access Control</span>
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
        <form v-if="auth.can('products.view')" class="search" role="search" @submit.prevent="search">
          <input v-model="query" type="search" placeholder="Search products by name or SKU..." aria-label="Search products (press Enter)" />
          <Search :size="18" />
        </form>
        <div class="topbar__space" />
        <RouterLink v-if="auth.can('orders.view')" class="icon-btn" :to="{ name: 'orders', query: { status: 'pending' } }" :title="`${ui.pendingOrders} pending orders`" aria-label="Pending orders">
          <Bell :size="17" />
          <span v-if="ui.pendingOrders" class="dot">{{ ui.pendingOrders > 99 ? '99+' : ui.pendingOrders }}</span>
        </RouterLink>
        <div ref="menuEl" class="user-menu">
          <button class="user-btn" type="button" aria-haspopup="menu" :aria-expanded="menuOpen" @click.stop="menuOpen = !menuOpen">
            <span class="avatar">{{ auth.initials }}</span>
            <strong>{{ auth.admin?.name || auth.admin?.username }}</strong>
          </button>
          <div v-if="menuOpen" class="dropdown" role="menu">
            <div class="dropdown__head">
              <strong>{{ auth.admin?.name }}</strong>
              <small>@{{ auth.admin?.username }}<template v-if="auth.role"> · {{ roleLabel }}</template></small>
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

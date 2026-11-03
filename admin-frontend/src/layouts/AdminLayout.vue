<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { BadgePercent, Bell, ChevronsLeft, ChevronsRight, FolderTree, KeyRound, LayoutDashboard, LogOut, Menu, Package, Search, Settings, ShoppingBag, Store, Ticket, Users } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import StoreMark from '@/components/StoreMark.vue'
import PushToggle from '@/components/PushToggle.vue'
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

// Keeps the badges fresh: pending orders + payment proofs to check (sidebar + bell) and new wholesale requests. Two light calls every 30 seconds,
// only for what this admin may see (also the fallback when browser push is not available).
async function refreshBadges() {
  if (document.hidden) return
  if (auth.can('orders.view')) {
    try { ui.pendingOrders = (await api.get('admin/orders/pending-count')).count ?? 0 } catch { /* the badges are optional */ }
  }
  if (auth.can('wholesale.view')) {
    try { ui.newWholesale = (await api.get('admin/wholesale/new-count')).count ?? 0 } catch { /* the badges are optional */ }
  }
}
const BADGE_POLL_MS = 30000
let timer
onMounted(() => {
  if (auth.can('products.view') || auth.can('categories.view')) catalog.load().catch(() => { /* the features fall back to the public store settings */ })
  refreshBadges()
  timer = setInterval(refreshBadges, BADGE_POLL_MS)
  document.addEventListener('click', closeMenu)
  document.addEventListener('visibilitychange', refreshBadges)
})
onBeforeUnmount(() => { clearInterval(timer); document.removeEventListener('click', closeMenu); document.removeEventListener('visibilitychange', refreshBadges) })
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
      <div class="sidebar__head">
        <StoreMark />
        <div class="store-text">{{ site.name }}<small>Admin</small></div>
      </div>
      <nav class="sidebar__nav">
        <RouterLink v-if="auth.can('dashboard.view')" :to="{ name: 'dashboard' }" class="nav-item" :class="{ 'is-active': route.name === 'dashboard' }" exact-active-class="" active-class="">
          <LayoutDashboard :size="19" /><span>Dashboard</span>
        </RouterLink>
        <RouterLink v-if="auth.can('orders.view')" :to="{ name: 'orders' }" class="nav-item" :class="{ 'is-active': route.name === 'orders' || route.name === 'order' }" exact-active-class="" active-class="">
          <ShoppingBag :size="19" /><span>Orders</span>
          <b v-if="ui.pendingOrders" class="nav-badge" data-testid="orders-badge" :title="`${ui.pendingOrders} orders or payment proofs waiting`">{{ ui.pendingOrders }}</b>
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
        <RouterLink v-if="auth.can('wholesale.view')" :to="{ name: 'wholesale' }" class="nav-item" :class="{ 'is-active': route.name === 'wholesale' }" exact-active-class="" active-class="">
          <Store :size="19" /><span>Wholesale</span>
          <b v-if="ui.newWholesale" class="nav-badge" data-testid="wholesale-badge" :title="`${ui.newWholesale} new requests`">{{ ui.newWholesale }}</b>
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
        <PushToggle v-if="auth.can('orders.view') || auth.can('wholesale.view')" />
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

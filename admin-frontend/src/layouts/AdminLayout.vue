<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { Activity, BadgePercent, BellRing, ChevronsLeft, ChevronsRight, FolderTree, KeyRound, LayoutDashboard, LogOut, Menu, Moon, Package, Search, Settings, ShoppingBag, Store, Sun, Ticket, Users, Volume2, VolumeX } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import BrandLogo from '@/components/BrandLogo.vue'

import PushToggle from '@/components/PushToggle.vue'
import NotificationStatusCard from '@/components/NotificationStatusCard.vue'
import { api } from '@/api/client.js'
import * as chime from '@/utils/chime.js'
import { warm, warmIdle } from '@/utils/prefetch.js'

const auth = useAuthStore()
const ui = useUiStore()
const catalog = useCatalogStore()
const site = useSiteStore()
const route = useRoute()
const router = useRouter()

const query = ref('')
const menuOpen = ref(false)
const menuEl = ref(null)
const statusOpen = ref(false)
const statusEl = ref(null)

// Keeps the badges fresh: pending orders + payment proofs to check (sidebar + bell) and new wholesale requests. One light call every 5 seconds,
// only for what this admin may see (also the fallback when browser push is not available). When a number goes UP while the panel is open, the
// panel chimes and shows a toast (unless sound is muted): that is the in-panel alert, because a site cannot change the sound of a push notification.
const seen = { orders: null, wholesale: null }
let quietUntil = 0   // a push message just alerted: the poll that follows must not alert a second time

// A system notification from the panel itself, for a tab that is not in front (another tab or window): the polled change arrives even when no push
// is subscribed. Skipped when this device is subscribed to push (the push message already shows its own notification).
async function systemNotice(title, body) {
  try {
    if (!('Notification' in window) || Notification.permission !== 'granted' || !document.hidden) return
    const reg = 'serviceWorker' in navigator ? await navigator.serviceWorker.getRegistration() : null
    if (reg && (await reg.pushManager.getSubscription())) return
    const options = { body, tag: 'panel-news', renotify: true, icon: site.logoUrl || undefined }
    if (reg) await reg.showNotification(title, options); else new Notification(title, options)
  } catch { /* a notification is a bonus: the toast and the chime still work */ }
}

async function announce(kind, delta) {
  if (Date.now() < quietUntil) return
  systemNotice(kind === 'orders' ? 'New order' : 'New wholesale request', kind === 'orders' ? `${delta} new order${delta === 1 ? '' : 's'} or payment proof${delta === 1 ? '' : 's'} waiting.` : `${delta} new wholesale request${delta === 1 ? '' : 's'}.`)
  ui.toast(kind === 'orders' ? `${delta} new order${delta === 1 ? '' : 's'} or payment proof${delta === 1 ? '' : 's'} waiting.` : `${delta} new wholesale request${delta === 1 ? '' : 's'}.`, 'info', 6000)
  if (!ui.soundMuted) await playChime()
}

async function playChime() {
  const ok = await chime.play()
  if (!ok && !ui.soundReady) ui.soundReady = false   // keeps the "click once" hint visible: the browser refused
}

const versions = { orders: null, wholesale: null }
function version(kind, v) {
  if (!v) return
  if (versions[kind] !== null && versions[kind] !== v) { if (kind === 'orders') ui.ordersVersion++; else ui.wholesaleVersion++ }
  versions[kind] = v
}

function track(kind, count) {
  if (seen[kind] !== null && count > seen[kind]) announce(kind, count - seen[kind])
  seen[kind] = count
}

async function refreshBadges() {
  // (also in a tab that is not in front: the browser slows it down to about once a minute, but a new order is still announced)
  // ONE call for both badges: every call occupies the (single-threaded, on a developer machine) server for its whole boot time
  if (!auth.can('orders.view') && !auth.can('wholesale.view')) return
  try {
    const r = await api.get('admin/badges')
    if (r.orders) { const n = r.orders.count ?? 0; ui.pendingOrders = n; track('orders', n); version('orders', r.orders.version) }
    if (r.wholesale) { const n = r.wholesale.count ?? 0; ui.newWholesale = n; track('wholesale', n); version('wholesale', r.wholesale.version) }
  } catch { /* the badges are optional */ }
}
const BADGE_POLL_MS = 5000   // one tiny call: new orders, changes and badges show within seconds, without a reload
let timer

// The service worker tells open panel tabs about a push message (so the chime plays even while the system shows its own notification),
// and asks the panel to open the order that was clicked.
async function onWorkerMessage(event) {
  const m = event.data || {}
  if (m.type === 'push') {
    quietUntil = Date.now() + 8000
    ui.toast([m.title, m.body].filter(Boolean).join(': '), 'info', 6000)
    if (!ui.soundMuted) await playChime()
    refreshBadges()
  } else if (m.type === 'navigate' && typeof m.url === 'string' && /^\/admin\//.test(m.url)) {
    router.push(m.url.replace(/^\/admin/, '') || '/')
  }
}

function unlockSound() {
  chime.unlock().then((ok) => { if (ok) ui.soundReady = true })
  document.removeEventListener('pointerdown', unlockSound)
  document.removeEventListener('keydown', unlockSound)
}

onMounted(() => {
  if (auth.can('products.view') || auth.can('categories.view')) catalog.load().catch(() => { /* the features fall back to the public store settings */ })
  refreshBadges()
  timer = setInterval(refreshBadges, BADGE_POLL_MS)
  warmIdle(router, auth)   // the code of the busiest screens loads while the browser is idle
  document.addEventListener('click', closeMenus)
  document.addEventListener('visibilitychange', refreshBadges)
  document.addEventListener('pointerdown', unlockSound)
  document.addEventListener('keydown', unlockSound)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', onWorkerMessage)
    navigator.serviceWorker.startMessages?.()
  }
})
onBeforeUnmount(() => {
  clearInterval(timer)
  document.removeEventListener('click', closeMenus)
  document.removeEventListener('visibilitychange', refreshBadges)
  document.removeEventListener('pointerdown', unlockSound)
  document.removeEventListener('keydown', unlockSound)
  if ('serviceWorker' in navigator) navigator.serviceWorker.removeEventListener('message', onWorkerMessage)
})
watch(() => route.fullPath, () => { ui.mobileSidebarOpen = false; menuOpen.value = false; statusOpen.value = false })

const closeMenus = (e) => {
  if (menuEl.value && !menuEl.value.contains(e.target)) menuOpen.value = false
  if (statusEl.value && !statusEl.value.contains(e.target)) statusOpen.value = false
}

function toggleSound() {
  ui.setMuted(!ui.soundMuted)
  if (!ui.soundMuted) playChime()   // a preview, and the click that lets the browser play sound
}

function search() {
  const q = query.value.trim()
  router.push({ name: 'products', query: q ? { q } : {} })
}

const roleLabel = computed(() => ({ developer: 'Developer', admin: 'Admin' })[auth.role] || auth.role)
const canNotify = computed(() => auth.can('orders.view') || auth.can('wholesale.view'))

// "Turn on notifications?" banner: browsers only ask for the permission after a click, so the panel asks once (a day of silence after "Not now")
const pushToggle = ref(null)
const PROMPT_KEY = 'admin_push_prompt_until'
const promptHidden = ref((() => { try { return Number(localStorage.getItem(PROMPT_KEY) || 0) > Date.now() } catch { return false } })())
const showPushPrompt = computed(() => canNotify.value && !promptHidden.value && Boolean(pushToggle.value?.canAsk))
function dismissPrompt() { promptHidden.value = true; try { localStorage.setItem(PROMPT_KEY, String(Date.now() + 86400000)) } catch { /* only for this visit */ } }
async function turnOnPush() { await pushToggle.value?.toggle(); promptHidden.value = true }
const showSoundHint = computed(() => canNotify.value && !ui.soundMuted && !ui.soundReady)

// The sidebar: the same items and permissions as always, grouped under small labels. Items without permission are left out, and so is a label without items.
const named = (...names) => (r) => names.includes(r.name)
// The sidebar: one plain list in the order the owner works in (no group headings). `system` items sit under a thin line.
const NAV = [
  { to: 'dashboard', text: 'Dashboard', icon: LayoutDashboard, can: 'dashboard.view', on: named('dashboard') },
  { to: 'orders', text: 'Orders', icon: ShoppingBag, can: 'orders.view', on: named('orders', 'order'), badge: 'pendingOrders', testid: 'orders-badge', title: (n) => `${n} orders or payment proofs waiting` },
  { to: 'wholesale', text: 'Wholesale', icon: Store, can: 'wholesale.view', on: named('wholesale', 'wholesale-request'), badge: 'newWholesale', testid: 'wholesale-badge', title: (n) => `${n} new requests` },
  { to: 'customers', text: 'Customers', icon: Users, can: 'customers.view', on: named('customers') },
  { to: 'products', text: 'Products', icon: Package, can: 'products.view', on: (r) => String(r.name).startsWith('product') },
  { to: 'categories', text: 'Categories', icon: FolderTree, can: 'categories.view', on: named('categories') },
  { to: 'discounts', text: 'Discounts', icon: BadgePercent, can: 'discounts.view', on: (r) => String(r.name).startsWith('discount') },
  { to: 'coupons', text: 'Coupons', icon: Ticket, can: 'coupons.view', on: (r) => String(r.name).startsWith('coupon') },
  { to: 'settings', text: 'Settings', icon: Settings, can: 'settings.*', on: named('settings'), system: true },
  { to: 'access-control', text: 'Access Control', icon: KeyRound, can: 'access.manage', on: named('access-control'), system: true },
]
const items = computed(() => NAV.filter((i) => auth.can(i.can)))

async function logout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <div class="app-shell" :class="{ 'is-collapsed': ui.sidebarCollapsed, 'mobile-open': ui.mobileSidebarOpen }">
    <aside class="sidebar" aria-label="Main navigation">
      <div class="sidebar__head">
        <BrandLogo :collapsed="ui.sidebarCollapsed" />
        <button class="side-toggle" type="button" :title="ui.sidebarCollapsed ? 'Expand menu' : 'Collapse menu'" :aria-label="ui.sidebarCollapsed ? 'Expand menu' : 'Collapse menu'" data-testid="collapse-toggle" @click="ui.toggleSidebar()">
          <ChevronsRight v-if="ui.sidebarCollapsed" :size="16" /><ChevronsLeft v-else :size="16" />
        </button>
      </div>
      <nav class="sidebar__nav">
        <template v-for="(i, n) in items" :key="i.to">
          <div v-if="i.system && !items[n - 1]?.system" class="nav-sep" role="separator" />
          <RouterLink :to="{ name: i.to }" class="nav-item" :class="{ 'is-active': i.on(route) }" exact-active-class="" active-class="" :title="ui.sidebarCollapsed ? i.text : undefined" @pointerenter="warm(router, { name: i.to }, auth)" @focus="warm(router, { name: i.to }, auth)" @touchstart.passive="warm(router, { name: i.to }, auth)">
            <component :is="i.icon" :size="18" /><span>{{ i.text }}</span>
            <b v-if="i.badge && ui[i.badge]" class="nav-badge" :data-testid="i.testid" :title="i.title(ui[i.badge])">{{ ui[i.badge] }}</b>
          </RouterLink>
        </template>
      </nav>
      <div class="sidebar__foot">
        <RouterLink :to="{ name: 'profile' }" class="profile-link" active-class="" exact-active-class="" :class="{ 'is-active': route.name === 'profile' }" :title="`${auth.admin?.name || auth.admin?.username} - change password`" data-testid="profile-link">
          <span class="avatar">{{ auth.initials }}</span>
          <span class="profile-link__text"><strong>{{ auth.admin?.name || auth.admin?.username }}</strong><small>{{ roleLabel }}</small></span>
        </RouterLink>
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
        <span v-if="showSoundHint" class="sound-hint" data-testid="sound-hint" role="status">Click anywhere once to enable sound</span>
        <button v-if="canNotify" class="icon-btn" type="button" data-testid="sound-toggle" :aria-pressed="!ui.soundMuted" :title="ui.soundMuted ? 'Alert sound is off. Click to turn it on.' : 'Alert sound is on. Click to mute.'" :aria-label="ui.soundMuted ? 'Turn the alert sound on' : 'Mute the alert sound'" @click="toggleSound">
          <VolumeX v-if="ui.soundMuted" :size="17" /><Volume2 v-else :size="17" />
        </button>
        <PushToggle v-if="canNotify" ref="pushToggle" />
        <div v-if="canNotify" ref="statusEl" class="status-menu">
          <button class="icon-btn" type="button" data-testid="notification-status-btn" title="Notification status" aria-label="Notification status" :aria-expanded="statusOpen" @click.stop="statusOpen = !statusOpen"><Activity :size="17" /></button>
          <div v-if="statusOpen" class="status-pop" role="dialog" aria-label="Notification status"><NotificationStatusCard compact bare /></div>
        </div>
        <button class="icon-btn" type="button" data-testid="theme-toggle" :aria-pressed="ui.theme === 'dark'" :title="ui.theme === 'dark' ? 'Switch to the light theme' : 'Switch to the dark theme'" :aria-label="ui.theme === 'dark' ? 'Switch to the light theme' : 'Switch to the dark theme'" @click="ui.toggleTheme()">
          <Sun v-if="ui.theme === 'dark'" :size="17" /><Moon v-else :size="17" />
        </button>
        <button class="icon-btn" type="button" data-testid="logout" title="Sign out" aria-label="Sign out" @click="logout"><LogOut :size="17" /></button>
      </header>

      <main class="content">
        <div v-if="showPushPrompt" class="push-prompt" role="status" data-testid="push-prompt">
          <BellRing :size="18" />
          <span>Get a notification on this computer when an order or a wholesale request arrives, even when this tab is closed.</span>
          <button class="btn btn-primary btn-sm" type="button" data-testid="push-prompt-on" @click="turnOnPush">Turn on</button>
          <button class="btn btn-outline btn-sm" type="button" @click="dismissPrompt">Not now</button>
        </div>
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.sound-hint { font-size: 11.5px; color: var(--primary-dark); background: var(--primary-soft); border-radius: 12px; padding: 3px 10px; white-space: nowrap; }
.status-menu { position: relative; }
.status-pop { position: absolute; right: -40px; top: calc(100% + 8px); width: 360px; max-width: calc(100vw - 20px); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-md); z-index: 50; }
@media (max-width: 720px) { .sound-hint { display: none; } .status-pop { right: -90px; } }
</style>

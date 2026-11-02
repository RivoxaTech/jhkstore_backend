<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Activity, Clock, PackageSearch, Plus, ShoppingBag, Wallet } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { ACTION_LABELS, money, timeAgo, todayISO } from '@/utils/format.js'

const ui = useUiStore()
const auth = useAuthStore()
const data = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await api.get('admin/dashboard')
    if (data.value.orders) ui.pendingOrders = data.value.orders.pending ?? ui.pendingOrders
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

// Order figures only exist for an admin who may see orders (the developer gets none: the server leaves them out).
const stats = computed(() => {
  const o = data.value?.orders
  const low = { label: 'Low stock', value: data.value?.catalog?.low_stock, sub: 'Options at or below their alert level', icon: PackageSearch, color: '#e11d48', soft: '#fde8ed', to: { name: 'products' } }
  if (!auth.can('orders.view')) return auth.can('products.view') ? [low] : []
  return [
    { label: 'Orders today', value: o?.today, sub: 'Placed since midnight (not cancelled)', icon: ShoppingBag, color: '#1d70ca', soft: '#e8f1fb', to: { name: 'orders', query: { from: todayISO() } } },
    { label: 'Revenue today', value: money(o?.revenue_today ?? 0), sub: 'Order totals of today', icon: Wallet, color: '#16a34a', soft: '#e7f6ec', to: { name: 'orders' } },
    { label: 'Pending orders', value: o?.pending, sub: 'Waiting to be confirmed', icon: Clock, color: '#d97706', soft: '#fff4e0', to: { name: 'orders', query: { status: 'pending' } } },
    ...(auth.can('products.view') ? [low] : []),
  ]
})

function describe(a) {
  const label = ACTION_LABELS[a.action] || a.action
  return a.subject_name ? `${label} "${a.subject_name}"` : label
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Dashboard</h1>
      <div class="crumbs"><span>Home</span> › <span>Dashboard</span></div>
    </div>
    <div class="page-actions">
      <RouterLink v-if="auth.can('products.manage')" class="btn btn-primary" :to="{ name: 'product-new' }"><Plus :size="17" /> New product</RouterLink>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">
    {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
  </div>

  <div v-if="stats.length" class="stats" aria-label="Overview">
    <RouterLink v-for="s in stats" :key="s.label" :to="s.to" class="stat" :style="{ '--c': s.color, '--c-soft': s.soft }">
      <div>
        <div class="stat__label">{{ s.label }}</div>
        <div v-if="loading" class="skeleton" style="width: 70px; height: 32px; margin: 6px 0" />
        <div v-else class="stat__value">{{ s.value ?? 0 }}</div>
        <div class="stat__sub">{{ s.sub }}</div>
      </div>
      <div class="stat__icon"><component :is="s.icon" :size="24" /></div>
    </RouterLink>
  </div>

  <section class="card">
    <div class="card__head"><h2>Recent activity</h2></div>
    <div class="card__body">
      <TableSkeleton v-if="loading" :rows="5" />
      <p v-else-if="!data?.recent_activity?.length" class="muted">No activity yet.</p>
      <div v-else>
        <div v-for="a in data.recent_activity" :key="a.id" class="list-item">
          <span class="activity-dot"><Activity :size="16" /></span>
          <div class="grow">
            <strong style="white-space: normal">{{ a.actor }} {{ describe(a) }}</strong>
            <small class="muted">{{ timeAgo(a.created_at) }}</small>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

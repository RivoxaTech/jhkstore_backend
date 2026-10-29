<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Activity, Building2, CheckCircle2, Clock, Inbox, PackageSearch, ShoppingBag, Tags, Wallet } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import { ACTION_LABELS, formatDate, money, timeAgo } from '@/utils/format.js'

const ui = useUiStore()
const data = ref(null)
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await api.get('admin/dashboard')
    ui.pendingCount = data.value.companies.pending
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

const orderStats = computed(() => {
  const o = data.value?.orders
  return [
    { label: 'Orders today', value: o?.today, sub: 'Placed since midnight (not cancelled)', icon: ShoppingBag, color: '#1d70ca', soft: '#e8f1fb', to: { name: 'orders', query: { from: new Date().toLocaleDateString('en-CA') } } },
    { label: 'Revenue today', value: money(o?.revenue_today ?? 0), sub: 'Order totals of today', icon: Wallet, color: '#16a34a', soft: '#e7f6ec', to: { name: 'orders' } },
    { label: 'Pending orders', value: o?.pending, sub: 'Waiting to be confirmed', icon: Clock, color: '#d97706', soft: '#fff4e0', to: { name: 'orders', query: { status: 'pending' } } },
    { label: 'Low stock', value: o?.low_stock, sub: 'Options at or below their alert level', icon: PackageSearch, color: '#e11d48', soft: '#fde8ed', to: { name: 'products' } },
  ]
})

const stats = computed(() => {
  const c = data.value?.companies
  const b = data.value?.brands
  return [
    { label: 'Total companies', value: c?.total, sub: 'All registered companies', icon: Building2, color: '#1d70ca', soft: '#e8f1fb', to: { name: 'companies' } },
    { label: 'Pending requests', value: c?.pending, sub: 'Waiting for your review', icon: Clock, color: '#d97706', soft: '#fff4e0', to: { name: 'companies', query: { status: 'pending' } } },
    { label: 'Approved', value: c?.approved, sub: `${c?.rejected ?? 0} rejected, ${c?.suspended ?? 0} suspended`, icon: CheckCircle2, color: '#16a34a', soft: '#e7f6ec', to: { name: 'companies', query: { status: 'approved' } } },
    { label: 'Brands', value: b?.total, sub: `${b?.active ?? 0} live on the website`, icon: Tags, color: '#7c3aed', soft: '#f1eafe', to: { name: 'companies' } },
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
      <RouterLink class="btn btn-primary" :to="{ name: 'company-new' }"><Building2 :size="17" /> New company</RouterLink>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">
    {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
  </div>

  <div class="stats" aria-label="Orders">
    <RouterLink v-for="s in orderStats" :key="s.label" :to="s.to" class="stat" :style="{ '--c': s.color, '--c-soft': s.soft }">
      <div>
        <div class="stat__label">{{ s.label }}</div>
        <div v-if="loading" class="skeleton" style="width: 70px; height: 32px; margin: 6px 0" />
        <div v-else class="stat__value">{{ s.value ?? 0 }}</div>
        <div class="stat__sub">{{ s.sub }}</div>
      </div>
      <div class="stat__icon"><component :is="s.icon" :size="24" /></div>
    </RouterLink>
  </div>

  <div class="stats">
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

  <div class="grid-2">
    <section class="card">
      <div class="card__head">
        <h2>Pending requests</h2>
        <RouterLink class="btn btn-soft btn-sm" :to="{ name: 'companies', query: { status: 'pending' } }">View all</RouterLink>
      </div>
      <TableSkeleton v-if="loading" :rows="4" />
      <EmptyState v-else-if="!data?.recent_pending.length" title="No pending requests" text="New seller registrations will appear here.">
        <template #icon><Inbox :size="40" /></template>
      </EmptyState>
      <div v-else class="table-wrap">
        <table class="table table--compact">
          <thead><tr><th>Company</th><th>City</th><th>Source</th><th>Submitted</th><th /></tr></thead>
          <tbody>
            <tr v-for="c in data.recent_pending" :key="c.id">
              <td><div class="cell-main"><div><strong>{{ c.name }}</strong><small>{{ c.email }}</small></div></div></td>
              <td>{{ c.city }}</td>
              <td><span class="badge badge--plain">{{ c.source === 'public_form' ? 'Website form' : 'Admin' }}</span></td>
              <td><span class="muted">{{ formatDate(c.submitted_at) }}</span></td>
              <td><div class="row-actions"><RouterLink class="btn btn-primary btn-sm" :to="{ name: 'company', params: { id: c.id } }">Review</RouterLink></div></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Recent activity</h2></div>
      <div class="card__body">
        <TableSkeleton v-if="loading" :rows="5" />
        <p v-else-if="!data?.recent_activity.length" class="muted">No activity yet.</p>
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
  </div>
</template>

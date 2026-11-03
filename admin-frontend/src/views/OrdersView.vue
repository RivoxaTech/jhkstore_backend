<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Search, ShoppingBag } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { formatDate, money } from '@/utils/format.js'

const STATUSES = ['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'rejected']
// the filter boxes come from GET admin/orders/options; these are the readable names (an unknown value falls back to its own name)
const PAYMENT_STATUS_LABELS = { cod_pending: 'Cash on delivery - to collect', awaiting_payment: 'Awaiting payment (bank transfer)', paid: 'Paid', cancelled: 'Cancelled', pending: 'Pending', failed: 'Failed' }
const paymentStatuses = ref(Object.keys(PAYMENT_STATUS_LABELS))
const paymentMethods = ref([{ code: 'cod', name: 'Cash on delivery' }, { code: 'bank_transfer', name: 'Bank transfer' }])
const statusLabel = (v) => PAYMENT_STATUS_LABELS[v] || v.charAt(0).toUpperCase() + v.slice(1).replace(/_/g, ' ')

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(true)
const loadedOnce = ref(false)
const error = ref('')
const search = ref(String(route.query.q || ''))

// The URL is the single source of truth for the filters, so they survive refresh and back/forward.
const q = (key) => String(route.query[key] || '')
const status = computed(() => q('status'))
const page = computed(() => Number(route.query.page || 1))
const PER_PAGE = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }

const tabs = computed(() => [
  { key: '', label: 'All', count: Object.values(counts.value).reduce((a, b) => a + Number(b), 0) },
  ...STATUSES.map(s => ({ key: s, label: s[0].toUpperCase() + s.slice(1), count: counts.value[s] || 0 })),
])

onMounted(async () => {
  try {
    const o = await api.get('admin/orders/options')
    if (o.payment_statuses?.length) paymentStatuses.value = o.payment_statuses
    if (o.payment_methods?.length) paymentMethods.value = o.payment_methods
  } catch { /* the built-in lists are enough */ }
})

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/orders', {
      search: q('q'), status: status.value, payment_status: q('payment_status'), payment_method: q('payment_method'), from: q('from'), to: q('to'),
      customer_id: q('customer_id'), page: page.value, per_page: PER_PAGE.value,
    })
    if (mine !== seq) return
    rows.value = res.data
    meta.value = res.meta
    counts.value = res.counts || {}
    loadedOnce.value = true
    if (!q('q') && !status.value && !q('payment_status') && !q('payment_method') && !q('from') && !q('to') && !q('customer_id')) ui.pendingOrders = counts.value.pending || 0
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}

watch(() => route.fullPath, load, { immediate: true })
watch(() => route.query.q, (v) => { search.value = String(v || '') })

function go(patch) {
  const query = { ...route.query, ...patch }
  Object.keys(query).forEach(k => (query[k] === '' || query[k] == null) && delete query[k])
  router.push({ name: 'orders', query })
}
const hasFilters = computed(() => ['q', 'status', 'payment_status', 'payment_method', 'from', 'to', 'customer_id'].some(k => q(k)))
const clearFilters = () => { search.value = ''; router.push({ name: 'orders' }) }

let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== q('q')) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Orders</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Orders</span></div>
    </div>
  </div>

  <section class="card">
    <div class="tabs" role="tablist">
      <button v-for="tab in tabs" :key="tab.key" class="tab" :class="{ 'is-active': status === tab.key }" type="button" role="tab" :aria-selected="status === tab.key" @click="go({ status: tab.key, page: undefined })">
        {{ tab.label }} <span v-if="loadedOnce" class="count">{{ tab.count }}</span>
      </button>
      <RowsPerPage :model-value="PER_PAGE" @update:model-value="setPerPage" />
    </div>

    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Order no, customer, phone or email" aria-label="Search orders" />
      </div>
      <select class="select" style="width: 150px" :value="q('payment_method')" aria-label="Payment method" @change="go({ payment_method: $event.target.value, page: undefined })">
        <option value="">All methods</option>
        <option v-for="m in paymentMethods" :key="m.code" :value="m.code">{{ m.name }}</option>
      </select>
      <select class="select" style="width: 160px" :value="q('payment_status')" aria-label="Payment" @change="go({ payment_status: $event.target.value, page: undefined })">
        <option value="">All payments</option>
        <option v-for="v in paymentStatuses" :key="v" :value="v">{{ statusLabel(v) }}</option>
      </select>
      <label class="date-field">From <input class="input" type="date" :value="q('from')" aria-label="From date" @change="go({ from: $event.target.value, page: undefined })" /></label>
      <label class="date-field">To <input class="input" type="date" :value="q('to')" :min="q('from') || undefined" aria-label="To date" @change="go({ to: $event.target.value, page: undefined })" /></label>
      <button v-if="hasFilters" class="btn btn-outline btn-sm" type="button" @click="clearFilters">Clear</button>
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="7" />
    <EmptyState v-else-if="!rows.length && !error" title="No orders found" :text="hasFilters ? 'Try changing the filters or the search.' : 'Orders placed on the website will appear here.'">
      <template #icon><ShoppingBag :size="42" /></template>
    </EmptyState>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table">
        <thead>
          <tr><th>Order</th><th>Date</th><th>Customer</th><th class="num" style="text-align: right">Items</th><th style="text-align: right">Total</th><th>Payment</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="o in rows" :key="o.id" class="row-link" @click="router.push({ name: 'order', params: { id: o.id } })">
            <td><RouterLink class="order-no" :to="{ name: 'order', params: { id: o.id } }" @click.stop>{{ o.order_number }}</RouterLink></td>
            <td class="nowrap"><span>{{ formatDate(o.placed_at) }}</span><div class="muted">{{ formatDate(o.placed_at, true).split(',').pop().trim() }}</div></td>
            <td>
              <strong class="truncate" :title="o.customer.name">{{ o.customer.name }}</strong>
              <div class="muted truncate" :title="o.customer.email">{{ o.customer.phone || o.customer.email }}</div>
            </td>
            <td style="text-align: right">{{ o.items_count }}</td>
            <td class="nowrap" style="text-align: right"><strong>{{ money(o.grand_total) }}</strong><div v-if="o.delivery_fee_pending" class="muted" title="The delivery fee is not set yet">+ delivery fee TBC</div></td>
            <td>
              <div>{{ o.payment_method_label }}</div>
              <StatusBadge :status="o.payment_status" />
            </td>
            <td><StatusBadge :status="o.status" /></td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>
</template>

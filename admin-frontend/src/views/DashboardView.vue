<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Activity, Ban, Calculator, CircleDollarSign, Clock, ImageUp, PackageSearch, Plus, ShoppingBag, Store, Users, Wallet } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { api } from '@/api/client.js'
import RevenueChart from '@/components/RevenueChart.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { ACTION_LABELS, formatDate, money, timeAgo } from '@/utils/format.js'

// The dashboard: a date range (shop time) and one section per permission. The server leaves out what this admin may not see: the developer
// gets the catalogue widgets only, never an order, a customer or a wholesale figure.
const RANGES = [['today', 'Today'], ['7d', 'Last 7 days'], ['30d', 'Last 30 days'], ['month', 'This month'], ['all', 'All time']]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)
const error = ref('')

const range = computed(() => (RANGES.some(([k]) => k === route.query.range) ? String(route.query.range) : '30d'))
const setRange = (key) => router.replace({ name: 'dashboard', query: key === '30d' ? {} : { range: key } })

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/dashboard', { range: range.value })
    if (mine === seq) data.value = res
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
onMounted(load)
watch(range, load)

const orders = computed(() => data.value?.orders)
const catalog = computed(() => data.value?.catalog)
const fromQuery = computed(() => (data.value?.range?.from ? { from: data.value.range.from, to: data.value.range.to } : {}))

// coloured KPI cards; each opens the screen it counts
const cards = computed(() => {
  const k = orders.value?.kpis
  const list = []
  if (k) {
    list.push(
      { id: 'revenue', label: 'Revenue', value: money(k.revenue), sub: 'Not counting cancelled or rejected', icon: Wallet, color: '#16a34a', soft: '#e7f6ec', to: { name: 'orders', query: fromQuery.value } },
      { id: 'pending-amount', label: 'Pending amount', value: money(k.pending_amount), sub: 'Orders waiting to be confirmed', icon: CircleDollarSign, color: '#d97706', soft: '#fff4e0', to: { name: 'orders', query: { status: 'pending', ...fromQuery.value } } },
      { id: 'orders', label: 'Orders', value: k.total_orders, sub: 'Placed in this period', icon: ShoppingBag, color: '#1d70ca', soft: '#e8f1fb', to: { name: 'orders', query: fromQuery.value } },
      { id: 'aov', label: 'Average order', value: money(k.average_order_value), sub: 'Revenue per order', icon: Calculator, color: '#7c3aed', soft: '#f1eafd', to: { name: 'orders', query: fromQuery.value } },
      { id: 'pending', label: 'Pending orders', value: k.pending_count, sub: 'Waiting to be confirmed', icon: Clock, color: '#f97316', soft: '#fff1e6', to: { name: 'orders', query: { status: 'pending', ...fromQuery.value } } },
      { id: 'void', label: 'Cancelled / rejected', value: k.cancelled_rejected_count, sub: 'Orders that did not go through', icon: Ban, color: '#e11d48', soft: '#fde8ed', to: { name: 'orders', query: { status: 'cancelled', ...fromQuery.value } } },
      { id: 'proof', label: 'Awaiting payment proof', value: k.awaiting_proof_count, sub: 'Bank transfers without a screenshot', icon: ImageUp, color: '#0891b2', soft: '#e0f4f8', to: { name: 'orders', query: { status: 'pending', payment_method: 'bank_transfer', ...fromQuery.value } } },
    )
  }
  if (data.value?.wholesale) list.push({ id: 'wholesale', label: 'New wholesale requests', value: data.value.wholesale.new, sub: 'Nobody has answered yet', icon: Store, color: '#0d9488', soft: '#e0f5f2', to: { name: 'wholesale' } })
  if (data.value?.customers) list.push({ id: 'customers', label: 'New customers', value: data.value.customers.new, sub: 'In this period', icon: Users, color: '#4f46e5', soft: '#ebebfd', to: { name: 'customers' } })
  if (catalog.value) list.push({ id: 'low-stock', label: 'Low stock', value: catalog.value.low_stock, sub: 'Options at or below their alert level', icon: PackageSearch, color: '#e11d48', soft: '#fde8ed', to: { name: 'products', query: { stock: 'low' } } })
  return list
})

const topMax = computed(() => Math.max(1, ...(orders.value?.top_products ?? []).map(p => p.qty)))

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

  <div class="tabs dash-tabs" role="tablist" aria-label="Date range">
    <button v-for="[key, label] in RANGES" :key="key" class="tab" :class="{ 'is-active': range === key }" type="button" role="tab" :aria-selected="range === key" :data-range="key" @click="setRange(key)">{{ label }}</button>
    <span v-if="data?.range" class="muted dash-zone" :title="`Dates follow the store's time zone (${data.range.timezone})`">{{ data.range.from ? `${formatDate(data.range.from)} - ${formatDate(data.range.to)}` : 'Everything' }} · {{ data.range.timezone }}</span>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">
    {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
  </div>

  <div v-if="cards.length || (loading && !data)" class="stats" aria-label="Overview" data-testid="kpis">
    <template v-if="loading && !data">
      <div v-for="n in 4" :key="n" class="stat"><div class="skeleton" style="width: 120px; height: 44px" /></div>
    </template>
    <RouterLink v-for="s in cards" v-else :key="s.id" :to="s.to" class="stat" :data-kpi="s.id" :style="{ '--c': s.color, '--c-soft': s.soft, opacity: loading ? 0.6 : 1 }">
      <div>
        <div class="stat__label">{{ s.label }}</div>
        <div class="stat__value">{{ s.value ?? 0 }}</div>
        <div class="stat__sub">{{ s.sub }}</div>
      </div>
      <div class="stat__icon"><component :is="s.icon" :size="24" /></div>
    </RouterLink>
  </div>

  <div v-if="orders" class="dash-grid">
    <section class="card dash-wide">
      <div class="card__head"><h2>Revenue and orders</h2><span class="muted">{{ orders.series.granularity === 'month' ? 'per month' : 'per day' }}</span></div>
      <div class="card__body">
        <RevenueChart :points="orders.series.points" :granularity="orders.series.granularity" />
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Top selling products</h2></div>
      <div class="card__body">
        <p v-if="!orders.top_products.length" class="muted">No sales in this period.</p>
        <ol v-else class="top-list" data-testid="top-sellers">
          <li v-for="(p, i) in orders.top_products" :key="p.product_id">
            <span class="top-list__rank">{{ i + 1 }}</span>
            <div class="grow">
              <RouterLink v-if="auth.can('products.view')" :to="{ name: 'product-edit', params: { id: p.product_id } }" class="top-list__name">{{ p.name }}</RouterLink>
              <span v-else class="top-list__name">{{ p.name }}</span>
              <div class="top-list__bar"><i :style="{ width: `${Math.max(4, (p.qty / topMax) * 100)}%` }" /></div>
            </div>
            <div class="top-list__nums"><strong>{{ p.qty }}</strong> sold<small>{{ money(p.revenue) }}</small></div>
          </li>
        </ol>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Recent orders</h2><RouterLink class="muted" :to="{ name: 'orders' }">See all</RouterLink></div>
      <div class="table-wrap">
        <p v-if="!orders.recent.length" class="muted" style="padding: 14px">No orders in this period.</p>
        <table v-else class="table" data-testid="recent-orders">
          <tbody>
            <tr v-for="o in orders.recent" :key="o.id" class="row-link" @click="router.push({ name: 'order', params: { id: o.id } })">
              <td><RouterLink :to="{ name: 'order', params: { id: o.id } }" @click.stop><strong>{{ o.order_number }}</strong></RouterLink><small class="muted" style="display: block">{{ o.customer_name }}</small></td>
              <td class="nowrap">{{ money(o.total) }}<small class="muted" style="display: block">{{ timeAgo(o.placed_at) }}</small></td>
              <td><StatusBadge :status="o.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div v-if="catalog" class="dash-grid">
    <section class="card" data-testid="low-stock-widget">
      <div class="card__head"><h2>Low stock</h2><RouterLink class="muted" :to="{ name: 'products', query: { stock: 'low' } }">All {{ catalog.low_stock }}</RouterLink></div>
      <div class="card__body">
        <p v-if="!catalog.low_stock_items.length" class="muted">Every option has enough stock.</p>
        <div v-for="v in catalog.low_stock_items" v-else :key="v.variant_id" class="list-item">
          <PackageSearch :size="16" class="muted" />
          <div class="grow">
            <RouterLink :to="{ name: 'product-edit', params: { id: v.product_id } }"><strong style="white-space: normal">{{ v.product_name }}</strong></RouterLink>
            <small class="muted">{{ v.option_name || v.sku }} · alert at {{ v.threshold }}</small>
          </div>
          <span class="stock-pill" :class="{ 'is-zero': v.available === 0 }">{{ v.available }} left</span>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Products</h2><span class="muted">{{ catalog.products }} in total</span></div>
      <div class="card__body">
        <div v-for="(n, s) in catalog.by_status" :key="s" class="list-item">
          <div class="grow"><RouterLink :to="{ name: 'products', query: { status: s } }" style="text-transform: capitalize">{{ s }}</RouterLink></div>
          <strong>{{ n }}</strong>
        </div>
      </div>
    </section>
  </div>

  <section class="card">
    <div class="card__head"><h2>Recent activity</h2></div>
    <div class="card__body">
      <TableSkeleton v-if="loading && !data" :rows="5" />
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

<style scoped>
.dash-tabs { align-items: center; margin-bottom: 12px; padding-bottom: 0; background: var(--surface); border: 1px solid var(--border-light); border-radius: var(--radius); }
.dash-zone { margin-left: auto; padding-right: 8px; font-size: 12px; }
.dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 12px; margin-bottom: 12px; }
.dash-wide { grid-column: 1 / -1; }
.top-list { list-style: none; display: grid; gap: 10px; }
.top-list li { display: flex; gap: 10px; align-items: center; }
.top-list__rank { width: 22px; height: 22px; border-radius: 50%; background: var(--primary-soft); color: var(--primary); display: grid; place-items: center; font-size: 11px; font-weight: 600; flex: none; }
.top-list__name { font-weight: 500; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 260px; }
.top-list__bar { height: 5px; border-radius: 3px; background: var(--muted-soft); margin-top: 4px; overflow: hidden; }
.top-list__bar i { display: block; height: 100%; background: var(--primary); border-radius: 3px; }
.top-list__nums { text-align: right; font-size: 12px; white-space: nowrap; }
.top-list__nums small { display: block; color: var(--text-3); }
.grow { flex: 1; min-width: 0; }
</style>

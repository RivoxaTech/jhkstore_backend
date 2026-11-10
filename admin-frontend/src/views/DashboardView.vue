<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Activity, ArrowDownRight, ArrowUpRight, Clock, PackageSearch, Plus, ReceiptText, ShoppingBag, TrendingUp, Wallet } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { api } from '@/api/client.js'
import RevenueChart from '@/components/RevenueChart.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { ACTION_LABELS, formatDate, money, timeAgo } from '@/utils/format.js'

// The dashboard: a welcome line, a date range (shop time), soft coloured cards with the change against the previous period, a smooth chart, the best
// sellers, the newest orders and the catalogue widgets. One section per permission: the server leaves out what this admin may not see, so the
// developer gets the catalogue widgets only, never an order, a customer or a wholesale figure.
const RANGES = [['today', 'Today'], ['7d', '7 days'], ['30d', '30 days'], ['month', 'This month'], ['all', 'All time']]
const PAYMENT = { cod: 'Cash on delivery', bank_transfer: 'Bank transfer' }

const auth = useAuthStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()
const data = ref(null)
const loading = ref(true)
const error = ref('')

const range = computed(() => (RANGES.some(([k]) => k === route.query.range) ? String(route.query.range) : '30d'))
const setRange = (key) => router.replace({ name: 'dashboard', query: key === '30d' ? {} : { range: key } })
const rangeText = computed(() => ({ today: 'today', '7d': 'in the last 7 days', '30d': 'in the last 30 days', month: 'this month', all: 'since the start' })[range.value])
const firstName = computed(() => String(auth.admin?.name || auth.admin?.username || '').trim().split(/\s+/)[0])

let seq = 0
async function load(silent = false) {
  const mine = ++seq
  if (silent !== true) loading.value = true
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
onMounted(() => load())
watch(range, () => load())
watch(() => ui.ordersVersion, () => load(true))

const orders = computed(() => data.value?.orders)
const catalog = computed(() => data.value?.catalog)
const compare = computed(() => orders.value?.comparison || null)
const fromQuery = computed(() => (data.value?.range?.from ? { from: data.value.range.from, to: data.value.range.to } : {}))

/** The change against the previous period: "up 14.9%" with the figure it was. `null` change = the period before had nothing to compare with. */
function change(key, format = (v) => v) {
  if (!compare.value) return null
  const pct = compare.value.change[key]
  const was = format(compare.value.previous[key])
  if (pct === null) return { none: true, text: `before: ${was}` }
  return { up: pct >= 0, pct: `${Math.abs(pct).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`, text: `before: ${was}` }
}

// soft coloured cards; each opens the screen it counts
const cards = computed(() => {
  const k = orders.value?.kpis
  const list = []
  if (k) {
    list.push(
      { id: 'revenue', label: 'Revenue', value: money(k.revenue), under: `Pending ${money(k.pending_amount)}`, change: change('revenue', money), icon: Wallet, tint: 'peach', to: { name: 'orders', query: fromQuery.value } },
      { id: 'orders', label: 'Total orders', value: k.total_orders, under: 'Placed in this period', change: change('total_orders'), icon: ShoppingBag, tint: 'green', to: { name: 'orders', query: fromQuery.value } },
      { id: 'aov', label: 'Average order value', value: money(k.average_order_value), under: 'Revenue per order', change: change('average_order_value', money), icon: ReceiptText, tint: 'blue', to: { name: 'orders', query: fromQuery.value } },
      { id: 'pending', label: 'Pending orders', value: k.pending_count, under: 'Waiting to be confirmed', icon: Clock, tint: 'mint', to: { name: 'orders', query: { status: 'pending', ...fromQuery.value } } },
    )
  }
  // four cards for the orders (the sidebar badges already show pending orders and new wholesale requests); the developer, who has no orders, gets the low-stock card
  if (!k && catalog.value) list.push({ id: 'low-stock', label: 'Low stock', value: catalog.value.low_stock, under: 'Options at or below their alert level', icon: PackageSearch, tint: 'pink', to: { name: 'products', query: { stock: 'low' } } })
  return list
})

const payment = (code) => PAYMENT[code] || String(code || '').replace(/_/g, ' ')
const initial = (name) => String(name || '?').trim().charAt(0).toUpperCase()

function describe(a) {
  const label = ACTION_LABELS[a.action] || a.action
  return a.subject_name ? `${label} "${a.subject_name}"` : label
}
</script>

<template>
  <div class="dash-head">
    <div>
      <h1>Welcome back<template v-if="firstName">, {{ firstName }}</template>!</h1>
      <p class="muted">Here's what's happening with your store {{ orders || data?.wholesale || data?.customers ? rangeText : 'today' }}.</p>
    </div>
    <div class="dash-actions">
      <div class="dash-tabs" role="tablist" aria-label="Date range">
        <button v-for="[key, label] in RANGES" :key="key" class="dash-tab" :class="{ 'is-active': range === key }" type="button" role="tab" :aria-selected="range === key" :data-range="key" @click="setRange(key)">{{ label }}</button>
      </div>
      <RouterLink v-if="auth.can('products.manage')" class="btn btn-dark" :to="{ name: 'product-new' }"><Plus :size="16" /> New product</RouterLink>
    </div>
  </div>
  <p v-if="data?.range" class="muted dash-zone" :title="`Dates follow the store's time zone (${data.range.timezone})`">{{ data.range.from ? `${formatDate(data.range.from)} - ${formatDate(data.range.to)}` : 'Everything' }} · {{ data.range.timezone }}</p>

  <div v-if="error" class="alert alert--error" role="alert">
    {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
  </div>

  <div v-if="cards.length || (loading && !data)" class="kpis" aria-label="Overview" data-testid="kpis">
    <template v-if="loading && !data">
      <div v-for="n in 4" :key="n" class="kpi kpi--skeleton"><div class="skeleton" style="width: 60%; height: 12px" /><div class="skeleton" style="width: 80%; height: 26px; margin-top: 10px" /><div class="skeleton" style="width: 50%; height: 16px; margin-top: 12px" /></div>
    </template>
    <RouterLink v-for="s in cards" v-else :key="s.id" :to="s.to" class="kpi" :class="`kpi--${s.tint}`" :data-kpi="s.id" :style="{ opacity: loading ? 0.6 : 1 }">
      <div class="kpi__top"><span class="kpi__label">{{ s.label }}</span><component :is="s.icon" :size="16" class="kpi__icon" /></div>
      <div class="kpi__value stat__value">{{ s.value ?? 0 }}</div>
      <div class="kpi__under">{{ s.under }}</div>
      <div v-if="s.change" class="kpi__change" :data-change="s.id">
        <span v-if="!s.change.none" class="pill" :class="s.change.up ? 'is-up' : 'is-down'"><component :is="s.change.up ? ArrowUpRight : ArrowDownRight" :size="12" /> {{ s.change.pct }}</span>
        <small>{{ s.change.text }}</small>
      </div>
    </RouterLink>
  </div>

  <div v-if="orders || (loading && !data)" class="dash-row dash-row--chart">
    <section class="card">
      <div class="card__head"><h2><TrendingUp :size="15" /> Summary</h2><span class="muted">{{ orders ? (orders.series.granularity === 'month' ? 'per month' : 'per day') : '' }}</span></div>
      <div class="card__body">
        <div v-if="!orders" class="skeleton" style="height: 250px" />
        <RevenueChart v-else :points="orders.series.points" :granularity="orders.series.granularity" />
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Most selling products</h2></div>
      <div class="card__body">
        <div v-if="!orders" class="skeleton" style="height: 200px" />
        <p v-else-if="!orders.top_products.length" class="empty-note">No sales in this period.</p>
        <ol v-else class="top-list" data-testid="top-sellers">
          <li v-for="p in orders.top_products" :key="p.product_id">
            <span class="thumb top-list__thumb"><img v-if="p.thumb_url" :src="p.thumb_url" :alt="p.name" loading="lazy" /><template v-else>{{ initial(p.name) }}</template></span>
            <div class="top-list__text">
              <RouterLink v-if="auth.can('products.view')" :to="{ name: 'product-edit', params: { id: p.product_id } }" class="top-list__name">{{ p.name }}</RouterLink>
              <span v-else class="top-list__name">{{ p.name }}</span>
              <small>{{ p.price === null ? '' : money(p.price) }}</small>
            </div>
            <span class="top-list__sold"><strong>{{ p.qty }}</strong> sold</span>
          </li>
        </ol>
      </div>
    </section>
  </div>

  <div v-if="orders || catalog" class="dash-row dash-row--orders">
    <section v-if="orders" class="card">
      <div class="card__head">
        <h2>Recent orders</h2>
        <div class="quick">
          <RouterLink class="btn btn-soft btn-sm" :to="{ name: 'orders', query: { payment_method: 'bank_transfer' } }" data-testid="quick-bank">View bank transfer orders</RouterLink>
          <RouterLink class="btn btn-soft btn-sm" :to="{ name: 'orders', query: { payment_method: 'cod' } }" data-testid="quick-cod">View COD orders</RouterLink>
          <RouterLink class="btn btn-outline btn-sm" :to="{ name: 'orders' }">View all</RouterLink>
        </div>
      </div>
      <div class="table-wrap">
        <div v-if="!orders.recent.length" class="empty-note" style="padding: 22px 14px"><ShoppingBag :size="26" /><span>No orders in this period.</span></div>
        <table v-else class="table table--compact table--recent" data-testid="recent-orders">
          <thead><tr><th>Order</th><th>Customer</th><th>Date</th><th>Payment</th><th class="num">Total</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="o in orders.recent" :key="o.id" class="row-link" @click="router.push({ name: 'order', params: { id: o.id } })">
              <td><RouterLink :to="{ name: 'order', params: { id: o.id } }" class="order-link" @click.stop>{{ o.order_number }}</RouterLink></td>
              <td><span class="truncate" :title="o.customer_name">{{ o.customer_name }}</span></td>
              <td class="nowrap muted" :title="formatDate(o.placed_at, true)">{{ timeAgo(o.placed_at) }}</td>
              <td class="nowrap">{{ payment(o.payment_method) }}</td>
              <td class="num nowrap"><strong>{{ money(o.total) }}</strong></td>
              <td><StatusBadge :status="o.status" /></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div v-if="catalog" class="stack">
      <section class="card" data-testid="low-stock-widget">
        <div class="card__head"><h2>Low stock</h2><RouterLink class="muted" :to="{ name: 'products', query: { stock: 'low' } }">All {{ catalog.low_stock }}</RouterLink></div>
        <div class="card__body">
          <p v-if="!catalog.low_stock_items.length" class="empty-note">Every option has enough stock.</p>
          <div v-for="v in catalog.low_stock_items" v-else :key="v.variant_id" class="list-item">
            <PackageSearch :size="15" class="muted" />
            <div class="grow">
              <RouterLink :to="{ name: 'product-edit', params: { id: v.product_id } }"><strong>{{ v.product_name }}</strong></RouterLink>
              <small class="muted">{{ v.option_name || v.sku }} · alert at {{ v.threshold }}</small>
            </div>
            <span class="stock-pill" :class="{ 'is-zero': v.available === 0 }">{{ v.available }} left</span>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card__head"><h2>Products</h2><span class="muted">{{ catalog.products }} in total</span></div>
        <div class="card__body prod-counts">
          <RouterLink v-for="(n, s) in catalog.by_status" :key="s" :to="{ name: 'products', query: { status: s } }" class="prod-count"><strong>{{ n }}</strong><span>{{ s }}</span></RouterLink>
        </div>
      </section>
    </div>
  </div>

  <section class="card" style="margin-top: 10px">
    <div class="card__head"><h2>Recent activity</h2></div>
    <div class="card__body">
      <div v-if="loading && !data" class="skeleton" style="height: 90px" />
      <p v-else-if="!data?.recent_activity?.length" class="empty-note">No activity yet.</p>
      <div v-else class="activity">
        <div v-for="a in data.recent_activity" :key="a.id" class="list-item">
          <span class="activity-dot"><Activity :size="14" /></span>
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
.dash-head > * { min-width: 0; max-width: 100%; }
.dash-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 4px; }
.dash-head h1 { font-size: 20px; font-weight: 600; line-height: 1.2; }
.dash-head p { margin-top: 2px; }
.dash-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; max-width: 100%; min-width: 0; }
.dash-tabs { min-width: 0; display: inline-flex; gap: 2px; padding: 3px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow-x: auto; max-width: 100%; }
.dash-tab { padding: 5px 12px; border-radius: 8px; font-weight: 500; color: var(--text-2); white-space: nowrap; }
.dash-tab:hover { color: var(--primary); }
.dash-tab.is-active { background: var(--brand, var(--primary)); color: var(--on-brand, #fff); }
.btn-dark { background: var(--brand, var(--primary)); color: var(--on-brand, #fff); }
.btn-dark:hover { background: var(--primary-dark); }
.dash-zone { font-size: 11.5px; margin-bottom: 10px; }

/* soft coloured cards */
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px; }
.kpi { --tint: #eef1f5; display: flex; flex-direction: column; gap: 1px; padding: 12px 14px; border-radius: 14px; background: var(--tint); border: 1px solid transparent; transition: transform .15s, box-shadow .15s; min-width: 0; }
a.kpi:hover { transform: translateY(-1px); box-shadow: var(--shadow-sm); }
.kpi--peach { --tint: #fdf1e7; }
.kpi--green { --tint: #eaf6ee; }
.kpi--blue { --tint: #e8f1fb; }
.kpi--mint { --tint: #e5f5f1; }
.kpi--pink { --tint: #fbebef; }
.kpi--lavender { --tint: #f1ecfc; }
.kpi--sky { --tint: #e7f3f9; }
.kpi--skeleton { background: var(--surface); border-color: var(--border-light); }
.kpi__top { display: flex; justify-content: space-between; align-items: center; color: var(--text-2); }
.kpi__label { font-size: 12px; }
.kpi__icon { opacity: .65; flex: none; }
.kpi__value { font-size: 22px; font-weight: 600; line-height: 1.25; color: var(--text); overflow-wrap: anywhere; }
.kpi__under { font-size: 11.5px; color: var(--text-2); }
.kpi__change { display: flex; align-items: center; gap: 6px; margin-top: 6px; flex-wrap: wrap; }
.kpi__change small { font-size: 11px; color: var(--text-2); }
.pill { display: inline-flex; align-items: center; gap: 2px; padding: 1px 8px 1px 5px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #fff; }
.pill.is-up { color: var(--success); }
.pill.is-down { color: var(--danger); }

.dash-row { display: grid; gap: 10px; margin-bottom: 10px; align-items: start; }
.dash-row--chart { grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); }
.dash-row--orders { grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); }
@media (max-width: 1100px) { .dash-row--chart, .dash-row--orders { grid-template-columns: minmax(0, 1fr); } }
.card__head h2 { display: flex; align-items: center; gap: 6px; }
.empty-note { display: flex; gap: 10px; align-items: center; color: var(--text-3); font-size: 12.5px; }

.top-list { list-style: none; display: grid; gap: 9px; }
.top-list li { display: flex; gap: 10px; align-items: center; }
.top-list__thumb { width: 42px; height: 42px; border-radius: 10px; background: #f6f8fb; }
.top-list__text { flex: 1; min-width: 0; display: grid; }
.top-list__name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.top-list__text small { color: var(--text-3); font-size: 11.5px; }
.top-list__sold { font-size: 11.5px; color: var(--text-2); border: 1px solid var(--border); border-radius: 8px; padding: 3px 9px; white-space: nowrap; }
.top-list__sold strong { color: var(--text); font-weight: 600; }

.quick { display: flex; gap: 6px; flex-wrap: wrap; }
table.table--recent { min-width: 560px; }
.table--recent th, .table--recent td { padding-left: 14px; padding-right: 10px; }
.table--recent .num { text-align: right; }
.table--recent td { height: 40px; }
.order-link { font-weight: 600; color: var(--primary); white-space: nowrap; }
.table--recent .truncate { max-width: 150px; display: block; }

.prod-counts { display: grid; grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); gap: 8px; }
.prod-count { display: grid; text-align: center; padding: 8px 4px; border-radius: 10px; background: #f6f8fb; }
.prod-count strong { font-size: 18px; font-weight: 600; }
.prod-count span { font-size: 11.5px; color: var(--text-2); text-transform: capitalize; }
.prod-count:hover { background: var(--primary-soft); }
.activity { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; }
@media (max-width: 900px) { .activity { grid-template-columns: minmax(0, 1fr); } }
.grow { flex: 1; min-width: 0; }
</style>

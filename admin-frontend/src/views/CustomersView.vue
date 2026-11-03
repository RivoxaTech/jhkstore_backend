<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Download, MessageCircle, Search, ShoppingCart, Users } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { formatDate, money, timeAgo, whatsappUrl } from '@/utils/format.js'
import { useSiteStore } from '@/stores/site.js'

// Two tabs. Customers: the people who ordered (a contact record created by every order), search by name, phone or e-mail, CSV export.
// Abandoned carts: carts with a saved phone or e-mail, items and no order for two hours; a WhatsApp button and a CSV export.
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const site = useSiteStore()

const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref('')
const exporting = ref(false)
const search = ref(String(route.query.q || ''))

const abandoned = computed(() => route.query.tab === 'abandoned')
const q = computed(() => String(route.query.q || ''))
const page = computed(() => Number(route.query.page || 1))
const PER_PAGE = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }
const endpoint = computed(() => (abandoned.value ? 'admin/customers/abandoned-carts' : 'admin/customers'))

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(endpoint.value, { q: q.value, page: page.value, per_page: PER_PAGE.value })
    if (mine !== seq) return
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
watch(() => route.fullPath, load, { immediate: true })

function go(patch) {
  const query = { ...route.query, ...patch }
  Object.keys(query).forEach(k => (query[k] === '' || query[k] == null) && delete query[k])
  router.push({ name: 'customers', query })
}
const showTab = (tab) => { search.value = ''; rows.value = []; router.push({ name: 'customers', query: tab ? { tab } : {} }) }

let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== q.value) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))

async function exportCsv() {
  exporting.value = true
  try {
    await api.download(`${endpoint.value}/export${q.value ? `?q=${encodeURIComponent(q.value)}` : ''}`, abandoned.value ? 'abandoned-carts.csv' : 'customers.csv')
  } catch (e) {
    ui.toast(e.message || 'The export failed.', 'error')
  } finally {
    exporting.value = false
  }
}

const message = (c) => `Hello${c.name ? ` ${c.name}` : ''}, you left ${c.items} item${c.items === 1 ? '' : 's'} in your cart at ${site.name}. Can we help you finish your order?`
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Customers</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>{{ abandoned ? 'Abandoned carts' : 'Customers' }}</span></div>
    </div>
    <div class="page-actions">
      <button v-if="auth.can('customers.export')" class="btn btn-outline" type="button" data-testid="export-customers" :disabled="exporting" @click="exportCsv"><span v-if="exporting" class="spinner" /><Download v-else :size="16" /> Export CSV</button>
    </div>
  </div>

  <section class="card">
    <div class="tabs" role="tablist">
      <button class="tab" :class="{ 'is-active': !abandoned }" type="button" role="tab" :aria-selected="!abandoned" data-tab="customers" @click="showTab('')"><Users :size="15" /> Customers</button>
      <button class="tab" :class="{ 'is-active': abandoned }" type="button" role="tab" :aria-selected="abandoned" data-tab="abandoned" @click="showTab('abandoned')"><ShoppingCart :size="15" /> Abandoned carts</button>
    </div>

    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Search by name, phone or email" aria-label="Search" />
      </div>
      <RowsPerPage :model-value="PER_PAGE" @update:model-value="setPerPage" />
    </div>

    <p v-if="abandoned" class="muted" style="margin: 0 14px 8px">Carts with a phone number or e-mail that were left for 2 hours without an order. The value is the current price of the items, before discounts.</p>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="7" />
    <EmptyState v-else-if="!rows.length && !error" :title="abandoned ? 'No abandoned carts' : 'No customers found'"
      :text="abandoned ? 'Carts that visitors left behind (with their phone number or e-mail) appear here.' : q ? 'Try another name, phone or email.' : 'People who place an order on the website appear here.'">
      <template #icon><ShoppingCart v-if="abandoned" :size="42" /><Users v-else :size="42" /></template>
    </EmptyState>

    <div v-else-if="abandoned" class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table" data-testid="abandoned-table">
        <thead>
          <tr><th>Name</th><th>Phone</th><th>Email</th><th style="text-align: right">Items</th><th style="text-align: right">Value</th><th>Last activity</th><th /></tr>
        </thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id">
            <td><strong class="truncate" :title="c.name">{{ c.name || '–' }}</strong></td>
            <td class="nowrap">{{ c.phone || '–' }}</td>
            <td><span class="truncate" :title="c.email">{{ c.email || '–' }}</span></td>
            <td style="text-align: right">{{ c.items }}</td>
            <td class="nowrap" style="text-align: right">{{ money(c.value) }}</td>
            <td class="nowrap" :title="formatDate(c.last_activity_at, true)">{{ timeAgo(c.last_activity_at) }}</td>
            <td>
              <a v-if="whatsappUrl(c.phone, message(c))" class="btn btn-soft btn-sm" :href="whatsappUrl(c.phone, message(c))" target="_blank" rel="noopener" data-testid="whatsapp"><MessageCircle :size="14" /> WhatsApp</a>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table">
        <thead>
          <tr><th>Name</th><th>Phone</th><th>Email</th><th>City</th><th style="text-align: right">Orders</th><th style="text-align: right">Total spent</th><th>Last order</th></tr>
        </thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id">
            <td><strong class="truncate" :title="c.name">{{ c.name }}</strong></td>
            <td class="nowrap">{{ c.phone }}</td>
            <td><span class="truncate" :title="c.email">{{ c.email || '–' }}</span></td>
            <td>{{ c.city || '–' }}</td>
            <td style="text-align: right">{{ c.orders_count }}</td>
            <td class="nowrap" style="text-align: right">{{ money(c.total_spent) }}</td>
            <td class="nowrap">{{ c.last_order_at ? formatDate(c.last_order_at) : '–' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>
</template>

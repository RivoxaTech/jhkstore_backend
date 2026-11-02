<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Download, Search, Users } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { formatDate, money } from '@/utils/format.js'

// Read-only list of the people who ordered (a contact record created by every order). Search by name, phone or e-mail; CSV export.
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()

const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref('')
const exporting = ref(false)
const search = ref(String(route.query.q || ''))

const q = computed(() => String(route.query.q || ''))
const page = computed(() => Number(route.query.page || 1))
const PER_PAGE = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/customers', { q: q.value, page: page.value, per_page: PER_PAGE.value })
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

let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== q.value) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))

async function exportCsv() {
  exporting.value = true
  try {
    await api.download(`admin/customers/export${q.value ? `?q=${encodeURIComponent(q.value)}` : ''}`, 'customers.csv')
  } catch (e) {
    ui.toast(e.message || 'The export failed.', 'error')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Customers</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Customers</span></div>
    </div>
    <div class="page-actions">
      <button v-if="auth.can('customers.export')" class="btn btn-outline" type="button" :disabled="exporting" @click="exportCsv"><span v-if="exporting" class="spinner" /><Download v-else :size="16" /> Export CSV</button>
    </div>
  </div>

  <section class="card">
    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Search by name, phone or email" aria-label="Search customers" />
      </div>
      <RowsPerPage :model-value="PER_PAGE" @update:model-value="setPerPage" />
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="7" />
    <EmptyState v-else-if="!rows.length && !error" title="No customers found" :text="q ? 'Try another name, phone or email.' : 'People who place an order on the website appear here.'">
      <template #icon><Users :size="42" /></template>
    </EmptyState>

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

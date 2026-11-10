<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Download, Search, Store } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import EmptyState from '@/components/EmptyState.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { formatDate, timeAgo } from '@/utils/format.js'
import { cachedList, rememberList } from '@/utils/listCache.js'

// Wholesale requests: customers asking for a bulk price (personal data: wholesale.view to look, wholesale.manage to answer and download).
// Status tabs, search, a detail dialog with the status, an internal note and a WhatsApp button, and a CSV of the current list.
const STATUSES = ['new', 'contacted', 'quoted', 'closed']
const LABEL = { new: 'New', contacted: 'Contacted', quoted: 'Quoted', closed: 'Closed' }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const canManage = computed(() => auth.can('wholesale.manage'))

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(true)
const loadedOnce = ref(false)
const error = ref('')
const search = ref(String(route.query.q || ''))
const exporting = ref(false)

const q = (key) => String(route.query[key] || '')
const status = computed(() => q('status'))
const page = computed(() => Number(route.query.page || 1))
const PER_PAGE = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }

const tabs = computed(() => [
  { key: '', label: 'All', count: Object.values(counts.value).reduce((a, b) => a + Number(b), 0) },
  ...STATUSES.map(s => ({ key: s, label: LABEL[s], count: counts.value[s] || 0 })),
])

let seq = 0
async function load(silent = false) {
  const mine = ++seq
  const key = 'wholesale:' + route.fullPath
  const hit = silent !== true ? cachedList(key) : null
  if (hit) { rows.value = hit.rows; meta.value = hit.meta; counts.value = hit.counts; loadedOnce.value = true; silent = true }
  if (silent !== true) loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/wholesale', { search: q('q'), status: status.value, page: page.value, per_page: PER_PAGE.value })
    if (mine !== seq) return
    rows.value = res.data
    meta.value = res.meta
    counts.value = res.counts || {}
    loadedOnce.value = true
    rememberList(key, { rows: res.data, meta: res.meta, counts: res.counts || {} })
    if (!q('q') && !status.value) ui.newWholesale = Number(counts.value.new || 0)
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
watch(() => route.fullPath, () => load(), { immediate: true })
watch(() => ui.wholesaleVersion, () => load(true))

function go(patch) {
  const query = { ...route.query, ...patch }
  Object.keys(query).forEach(k => (query[k] === '' || query[k] == null) && delete query[k])
  router.push({ name: 'wholesale', query })
}
let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== q('q')) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))

async function exportCsv() {
  exporting.value = true
  try {
    const query = new URLSearchParams(Object.entries({ search: q('q'), status: status.value }).filter(([, v]) => v))
    await api.download(`admin/wholesale/export${query.size ? `?${query}` : ''}`, 'wholesale-requests.csv')
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
      <h1>Wholesale requests</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Wholesale</span></div>
    </div>
    <div class="page-actions">
      <button v-if="canManage" class="btn btn-outline" type="button" data-testid="export-wholesale" :disabled="exporting" @click="exportCsv"><span v-if="exporting" class="spinner" /><Download v-else :size="16" /> Export CSV</button>
    </div>
  </div>

  <section class="card">
    <div class="tabs" role="tablist">
      <button v-for="tab in tabs" :key="tab.key" class="tab" :class="{ 'is-active': status === tab.key }" type="button" role="tab" :aria-selected="status === tab.key" :data-status="tab.key" @click="go({ status: tab.key, page: undefined })">
        {{ tab.label }} <span v-if="loadedOnce" class="count">{{ tab.count }}</span>
      </button>
      <RowsPerPage :model-value="PER_PAGE" @update:model-value="setPerPage" />
    </div>

    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Reference, name, phone, business or product" aria-label="Search requests" />
      </div>
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="6" />
    <EmptyState v-else-if="!rows.length && !error" title="No requests" :text="q('q') || status ? 'Try another status or search.' : 'Requests from the wholesale form of your website appear here.'">
      <template #icon><Store :size="42" /></template>
    </EmptyState>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table" data-testid="wholesale-table">
        <thead>
          <tr><th>Reference</th><th>Customer</th><th>Product</th><th style="text-align: right">Quantity</th><th>Received</th><th>Status</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id" class="row-link" :data-ref="r.reference" @click="router.push({ name: 'wholesale-request', params: { id: r.id } })">
            <td class="nowrap"><strong>{{ r.reference }}</strong></td>
            <td>
              <strong class="truncate" :title="r.name">{{ r.name }}</strong>
              <small class="muted" style="display: block">{{ r.business_name || r.phone }}</small>
            </td>
            <td><span class="truncate" style="max-width: 220px; display: inline-block" :title="r.product_name">{{ r.product_name }}</span></td>
            <td style="text-align: right">{{ r.quantity }}</td>
            <td class="nowrap" :title="formatDate(r.created_at, true)">{{ timeAgo(r.created_at) }}</td>
            <td><span class="badge" :class="{ 'badge--pending': r.status === 'new', 'badge--active': r.status === 'quoted', 'badge--plain': r.status === 'contacted', 'badge--suspended': r.status === 'closed' }">{{ LABEL[r.status] }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>

</template>

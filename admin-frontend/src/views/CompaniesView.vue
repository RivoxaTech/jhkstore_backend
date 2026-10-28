<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Building2, Plus, Search } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import LogoBox from '@/components/LogoBox.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import { formatDate } from '@/utils/format.js'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(true)
const loadedOnce = ref(false) // tab counts stay hidden until the first answer arrives
const error = ref('')
const search = ref(String(route.query.q || ''))

// The URL is the single source of truth (status / q / source / page) so filters survive refresh and back/forward.
const status = computed(() => String(route.query.status || ''))
const source = computed(() => String(route.query.source || ''))
const page = computed(() => Number(route.query.page || 1))
const perPage = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }

const tabs = computed(() => [
  { key: '', label: 'All', count: Object.values(counts.value).reduce((a, b) => a + Number(b), 0) },
  { key: 'pending', label: 'Pending', count: counts.value.pending || 0 },
  { key: 'approved', label: 'Approved', count: counts.value.approved || 0 },
  { key: 'rejected', label: 'Rejected', count: counts.value.rejected || 0 },
  { key: 'suspended', label: 'Suspended', count: counts.value.suspended || 0 },
])

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/companies', {
      status: status.value, source: source.value, q: route.query.q, page: page.value, per_page: perPage.value,
    })
    if (mine !== seq) return // a newer request is already running
    rows.value = res.data
    meta.value = res.meta
    counts.value = res.counts || {}
    loadedOnce.value = true
    ui.pendingCount = Number(counts.value.pending || 0)
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}

watch(() => route.fullPath, load, { immediate: true })
watch(() => route.query.q, (q) => { search.value = String(q || '') })

function go(patch) {
  const query = { ...route.query, ...patch }
  Object.keys(query).forEach(k => (query[k] === '' || query[k] == null) && delete query[k])
  router.push({ name: 'companies', query })
}

let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== String(route.query.q || '')) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Companies</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Companies</span></div>
    </div>
    <div class="page-actions">
      <RouterLink class="btn btn-primary" :to="{ name: 'company-new' }"><Plus :size="17" /> Create new company</RouterLink>
    </div>
  </div>

  <section class="card">
    <div class="tabs" role="tablist">
      <button v-for="tab in tabs" :key="tab.key" class="tab" :class="{ 'is-active': status === tab.key }" type="button" role="tab" :aria-selected="status === tab.key" @click="go({ status: tab.key, page: undefined })">
        {{ tab.label }} <span v-if="loadedOnce" class="count">{{ tab.count }}</span>
      </button>
      <RowsPerPage :model-value="perPage" @update:model-value="setPerPage" />
    </div>

    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Search name, GST number or email" aria-label="Search companies" />
      </div>
      <select class="select" style="width: 160px" :value="source" aria-label="Filter by source" @change="go({ source: $event.target.value, page: undefined })">
        <option value="">All sources</option>
        <option value="public_form">Website form</option>
        <option value="admin">Created by admin</option>
      </select>
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="7" />
    <EmptyState v-else-if="!rows.length && !error" title="No companies found" :text="route.query.q || status ? 'Try changing the filters or the search.' : 'Create the first company to get started.'">
      <template #icon><Building2 :size="42" /></template>
      <RouterLink class="btn btn-primary" :to="{ name: 'company-new' }"><Plus :size="17" /> Create new company</RouterLink>
    </EmptyState>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table">
        <thead>
          <tr>
            <th class="sno">#</th><th>Company</th><th>Contact</th><th>Location</th><th>Vendor / type</th><th>GST / VAT</th>
            <th>Brands</th><th>Status</th><th>Submitted</th><th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(c, i) in rows" :key="c.id" class="row-link" @click="router.push({ name: 'company', params: { id: c.id } })">
            <td class="sno">{{ (page - 1) * perPage + i + 1 }}</td>
            <td>
              <div class="cell-main">
                <LogoBox :name="c.name" :src="c.logo_thumb_url" />
                <div style="min-width: 0">
                  <strong class="truncate" :title="c.name">{{ c.name }}</strong>
                  <small :title="c.email">{{ c.email }}</small>
                </div>
              </div>
            </td>
            <td>
              <div class="truncate">{{ c.contact.name }}</div>
              <div class="muted">{{ c.contact.mobile }}</div>
            </td>
            <td>
              <div class="truncate">{{ c.city }}</div>
              <div class="muted">{{ c.origin_country?.name }}</div>
            </td>
            <td>
              <div class="truncate">{{ c.vendor_type?.name }}</div>
              <div class="muted truncate">{{ c.registration_type?.name }}</div>
            </td>
            <td class="nowrap"><span style="font-family: ui-monospace, Consolas, monospace; font-size: 12px">{{ c.vat_gst_number }}</span></td>
            <td>
              <strong>{{ c.brands_count }}</strong>
              <div v-if="c.brand_names.length" class="chips">
                <span v-for="n in c.brand_names.slice(0, 2)" :key="n" class="chip" :title="n">{{ n }}</span>
                <span v-if="c.brands_count > 2" class="chip">+{{ c.brands_count - 2 }}</span>
              </div>
            </td>
            <td class="nowrap">
              <StatusBadge :status="c.status" />
              <div class="muted">{{ c.source === 'public_form' ? 'Website form' : 'Admin' }}</div>
            </td>
            <td class="nowrap">
              <div>{{ formatDate(c.submitted_at) }}</div>
              <div v-if="c.catalogue_count" class="muted">{{ c.catalogue_count }} file{{ c.catalogue_count > 1 ? 's' : '' }}</div>
            </td>
            <td @click.stop>
              <div class="row-actions">
                <RouterLink class="btn btn-soft btn-sm" :to="{ name: 'company', params: { id: c.id } }">{{ c.status === 'pending' ? 'Review' : 'Open' }}</RouterLink>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>
</template>

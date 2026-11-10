<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { BadgePercent, History, Pencil, Plus, Search, Ticket, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { formatDate, money } from '@/utils/format.js'
import { rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import CouponUsagesModal from '@/components/CouponUsagesModal.vue'
import EmptyState from '@/components/EmptyState.vue'
import PaginationBar from '@/components/PaginationBar.vue'

import RowsPerPage from '@/components/RowsPerPage.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// The compact list behind the Discounts and the Coupons screens (`kind` = discount | coupon): search, status filter, pagination, activate switch,
// edit, delete and (coupons) the uses of a coupon. Buttons need `<kind>s.manage`; the list itself `<kind>s.view`.
const props = defineProps({ kind: { type: String, required: true } })
const auth = useAuthStore()
const ui = useUiStore()

const isCoupon = computed(() => props.kind === 'coupon')
const plural = computed(() => (isCoupon.value ? 'coupons' : 'discounts'))
const title = computed(() => (isCoupon.value ? 'Coupons' : 'Discounts'))
const canManage = computed(() => auth.can(`${plural.value}.manage`))
const STATUSES = [['', 'All statuses'], ['live', 'Live'], ['scheduled', 'Scheduled'], ['expired', 'Expired'], ['inactive', 'Inactive']]
const SCOPE_LABEL = { store: 'Whole store', products: 'Products', categories: 'Categories', sub_categories: 'Sub-categories' }

const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const loadedOnce = ref(false)
const error = ref('')
const search = ref('')
const status = ref('')
const page = ref(1)
const perPage = ref(storedPerPage())

const usages = ref(null)
const toDelete = ref(null)
const busy = ref(false)

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`admin/${plural.value}`, { q: search.value.trim(), status: status.value, page: page.value, per_page: perPage.value })
    if (mine !== seq) return
    rows.value = res.data
    meta.value = res.meta
    loadedOnce.value = true
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
onMounted(load)

let t
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; load() }, 350) })
onBeforeUnmount(() => clearTimeout(t))
const setStatus = (v) => { status.value = v; page.value = 1; load() }
const setPage = (n) => { page.value = n; load() }
const setPerPage = (n) => { rememberPerPage(n); perPage.value = n; page.value = 1; load() }
const hasFilters = computed(() => Boolean(search.value.trim() || status.value))
const clearFilters = () => { search.value = ''; status.value = ''; page.value = 1; load() }



// the switch flips at once and goes back if the server refuses
async function flip(row) {
  const value = !row.is_active
  row.is_active = value
  try {
    const res = await api.patch(`admin/${plural.value}/${row.id}/active`, { is_active: value })
    rows.value = rows.value.map(r => (r.id === row.id ? { ...r, ...res.data } : r))
    ui.toast(value ? 'Switched on.' : 'Switched off.', 'info')
  } catch (e) {
    row.is_active = !value
    ui.toast(e.message, 'error')
  }
}

async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/${plural.value}/${toDelete.value.id}`)
    ui.toast(`${isCoupon.value ? 'Coupon' : 'Discount'} deleted.`)
    toDelete.value = null
    busy.value = false
    if (rows.value.length === 1 && page.value > 1) page.value--
    await load()
  } catch (e) {
    toDelete.value = null
    // a used coupon answers 422 coupon_has_usages: "... Deactivate it instead."
    ui.toast(e.message, 'error', 9000)
  } finally {
    busy.value = false
  }
}

const valueText = (r) => {
  if (r.free_delivery) return 'Free delivery'
  if (r.value === null || r.value === undefined) return '-'
  return r.type === 'percent' ? `${r.value}%` : money(r.value)
}
// the API sends an instant with its offset; formatDate shows it in the store's time zone
const shop = (iso) => formatDate(iso, true)
const dates = (r) => (!r.starts_at && !r.ends_at ? 'Always' : `${r.starts_at ? shop(r.starts_at) : 'now'} → ${r.ends_at ? shop(r.ends_at) : 'no end'}`)
const scopeText = (r) => (r.scope === 'store' ? SCOPE_LABEL.store : `${SCOPE_LABEL[r.scope]} (${r.targets_count ?? 0})`)
const usedText = (r) => `${r.used_count ?? 0}${r.usage_limit ? ` / ${r.usage_limit}` : ''}`
const label = (r) => (isCoupon.value ? r.code : r.name)
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ title }}</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>{{ title }}</span></div>
    </div>
    <div class="page-actions">
      <RouterLink v-if="canManage" class="btn btn-primary" :to="{ name: `${kind}-new` }" data-add><Plus :size="16" /> Add {{ kind }}</RouterLink>
    </div>
  </div>

  <section class="card">
    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" :placeholder="isCoupon ? 'Search by code or name' : 'Search by name'" :aria-label="`Search ${plural}`" />
      </div>
      <select class="select" style="width: 150px" :value="status" aria-label="Status" @change="setStatus($event.target.value)">
        <option v-for="[v, name] in STATUSES" :key="v" :value="v">{{ name }}</option>
      </select>
      <button v-if="hasFilters" class="btn btn-outline btn-sm" type="button" @click="clearFilters">Clear</button>
      <RowsPerPage style="margin-left: auto" :model-value="perPage" @update:model-value="setPerPage" />
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="6" />
    <EmptyState v-else-if="!rows.length && !error" :title="`No ${plural} found`" :text="hasFilters ? 'Try changing the search or the status.' : isCoupon ? 'A coupon is a code customers type at checkout.' : 'A discount applies by itself to the products it covers, with no code.'">
      <template #icon><Ticket v-if="isCoupon" :size="42" /><BadgePercent v-else :size="42" /></template>
      <RouterLink v-if="canManage && !hasFilters" class="btn btn-primary" :to="{ name: `${kind}-new` }"><Plus :size="16" /> Add the first {{ kind }}</RouterLink>
    </EmptyState>

    <div v-else-if="rows.length" class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table table--dense" :data-list="plural">
        <thead>
          <tr>
            <th>{{ isCoupon ? 'Code' : 'Name' }}</th><th v-if="isCoupon">Name</th><th>{{ isCoupon ? 'Gives' : 'Discount' }}</th><th>Applies to</th><th>Dates</th><th>Status</th>
            <th v-if="isCoupon" class="num">Used</th><th v-else class="num">Priority</th><th>Active</th><th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id" :data-row="label(r)" :style="r.is_active ? null : { opacity: 0.7 }">
            <td><strong class="truncate" :title="label(r)">{{ label(r) }}</strong></td>
            <td v-if="isCoupon"><span class="truncate" :title="r.name">{{ r.name }}</span></td>
            <td class="nowrap"><strong>{{ valueText(r) }}</strong></td>
            <td class="nowrap">{{ scopeText(r) }}</td>
            <td class="nowrap muted">{{ dates(r) }}</td>
            <td><StatusBadge :status="r.state" /></td>
            <td v-if="isCoupon" class="num">{{ usedText(r) }}</td>
            <td v-else class="num">{{ r.priority }}</td>
            <td><label class="switch" :title="r.is_active ? 'Active' : 'Off'"><input type="checkbox" :checked="r.is_active" :disabled="!canManage" :aria-label="`${label(r)} active`" @change="flip(r)" /><span /></label></td>
            <td>
              <div class="row-actions">
                <button v-if="isCoupon" class="btn btn-outline btn-sm" type="button" :aria-label="`Uses of ${r.code}`" @click="usages = r"><History :size="13" /> Uses</button>
                <RouterLink v-if="canManage" class="btn btn-outline btn-sm btn-icon" :to="{ name: `${kind}-edit`, params: { id: r.id } }" title="Edit" :aria-label="`Edit ${label(r)}`"><Pencil :size="14" /></RouterLink>
                <button v-if="canManage" class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${label(r)}`" @click="toDelete = r"><Trash2 :size="14" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="setPage" />
  </section>


  <CouponUsagesModal v-if="usages" :coupon="usages" @close="usages = null" />
  <ConfirmDialog
    v-if="toDelete" :title="`Delete ${kind}`" confirm-text="Delete" tone="danger" :busy="busy"
    :message="`Delete ${isCoupon ? 'the coupon' : 'the discount'} &quot;${label(toDelete)}&quot;?${isCoupon ? ' A coupon that was used on an order cannot be deleted: switch it off instead.' : ''}`"
    @confirm="confirmDelete" @close="toDelete = null"
  />
</template>

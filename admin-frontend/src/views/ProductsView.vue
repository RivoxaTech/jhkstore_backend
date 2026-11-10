<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { GripVertical, Package, Pencil, Plus, Search, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { money } from '@/utils/format.js'

const STATUSES = ['active', 'draft', 'inactive', 'archived']

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const catalog = useCatalogStore()
const auth = useAuthStore()
const canManage = computed(() => auth.can('products.manage'))   // without it the admin can look but not change

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(true)
const loadedOnce = ref(false)
const error = ref('')
const search = ref(String(route.query.q || ''))

const toDelete = ref(null)
const busy = ref(false)

// The URL is the single source of truth for the filters, so they survive refresh and back/forward.
const q = (key) => String(route.query[key] || '')
const status = computed(() => q('status'))
const page = computed(() => Number(route.query.page || 1))
// rows per page: ?per_page= in the URL, else the remembered choice (25 / 50 / 75 / 100)
const PER_PAGE = computed(() => (isValidPerPage(route.query.per_page) ? Number(route.query.per_page) : storedPerPage()))
function setPerPage(n) { rememberPerPage(n); go({ per_page: n, page: undefined }) }

const tabs = computed(() => [
  { key: '', label: 'All', count: Object.values(counts.value).reduce((a, b) => a + Number(b), 0) },
  ...STATUSES.map(s => ({ key: s, label: s[0].toUpperCase() + s.slice(1), count: counts.value[s] || 0 })),
])

// the sub-categories of the chosen category
const subOptions = computed(() => {
  const category = catalog.categories.find(c => String(c.id) === q('category_id'))
  return category ? category.sub_categories.map(s => ({ id: s.id, label: s.name })) : []
})

let seq = 0
async function load() {
  const mine = ++seq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('admin/products', {
      q: q('q'), status: status.value, category_id: q('category_id'),
      sub_category_id: q('sub_category_id'), tag: q('tag'), stock: q('stock'), featured: q('featured'), page: page.value, per_page: PER_PAGE.value,
    })
    if (mine !== seq) return
    rows.value = res.data
    meta.value = res.meta
    counts.value = res.counts || {}
    loadedOnce.value = true
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}

onMounted(() => catalog.load().catch(e => { error.value = e.message }))
watch(() => route.fullPath, load, { immediate: true })
watch(() => route.query.q, (v) => { search.value = String(v || '') })

function go(patch) {
  const query = { ...route.query, ...patch }
  Object.keys(query).forEach(k => (query[k] === '' || query[k] == null) && delete query[k])
  router.push({ name: 'products', query })
}
// choosing another category clears the sub-category that depended on it
const pickCategory = (v) => go({ category_id: v, sub_category_id: undefined, page: undefined })
const hasFilters = computed(() => ['q', 'status', 'category_id', 'sub_category_id', 'tag', 'stock', 'featured'].some(k => q(k)))
const STOCK_FILTERS = [['in', 'In stock'], ['low', 'Running low'], ['out', 'Out of stock'], ['sold_out', 'Marked sold out']]
const clearFilters = () => { search.value = ''; router.push({ name: 'products' }) }

// ---- arrange the products by drag and drop (the order the website shows them in). Only on the unfiltered list: a filtered list is not the whole order.
const canArrange = computed(() => canManage.value && !hasFilters.value && rows.value.length > 1)
const dragId = ref(null)
const overId = ref(null)
function dragStart(e, p) {
  dragId.value = p.id
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', String(p.id))
  const row = e.target.closest('tr')
  if (row) e.dataTransfer.setDragImage(row, 20, 20)
}
const dragEnd = () => { dragId.value = null; overId.value = null }
async function dropOn(target) {
  const from = rows.value.findIndex(r => r.id === dragId.value)
  const to = rows.value.findIndex(r => r.id === target.id)
  dragEnd()
  if (from < 0 || to < 0 || from === to) return
  const before = [...rows.value]
  const next = [...before]
  next.splice(to, 0, next.splice(from, 1)[0])
  rows.value = next   // the list moves at once; the server answer only confirms it
  try {
    await api.put('admin/products/reorder', { ids: next.map(r => r.id) })
    ui.toast('Order saved.')
  } catch (e) {
    rows.value = before
    ui.toast(e.message, 'error')
  }
}

let t
watch(search, (value) => {
  clearTimeout(t)
  t = setTimeout(() => { if (value.trim() !== q('q')) go({ q: value.trim(), page: undefined }) }, 350)
})
onBeforeUnmount(() => clearTimeout(t))

// ---- quick actions
async function changeStatus(row, value) {
  const before = row.status
  row.status = value
  try {
    await api.patch(`admin/products/${row.id}/status`, { status: value })
    ui.toast(`"${row.name}" is now ${value}.`, 'info')
    load()
  } catch (e) {
    row.status = before
    ui.toast(e.message, 'error')
  }
}

// the two switches of the list: the change is shown at once and undone when the server refuses it
async function setFlag(row, flag, value) {
  const field = flag === 'sold-out' ? 'is_sold_out' : 'is_featured'
  const before = row[field]
  row[field] = value
  try {
    await api.patch(`admin/products/${row.id}/${flag}`, { value })
    ui.toast(flag === 'sold-out' ? (value ? `"${row.name}" is shown as sold out.` : `"${row.name}" can be ordered again.`) : (value ? `"${row.name}" is featured.` : `"${row.name}" is no longer featured.`), 'info')
  } catch (e) {
    row[field] = before
    ui.toast(e.message, 'error')
  }
}



async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/products/${toDelete.value.id}`)
    ui.toast('Product deleted.')
    toDelete.value = null
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.message, 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Products</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Products</span></div>
    </div>
    <div class="page-actions">

      <RouterLink v-if="canManage" class="btn btn-primary" :to="{ name: 'product-new' }"><Plus :size="17" /> Add product</RouterLink>
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
        <input v-model="search" class="input" type="search" placeholder="Search name or SKU" aria-label="Search products" />
      </div>
      <template v-if="catalog.loaded">
      <select class="select" style="width: 150px" :value="q('category_id')" aria-label="Category" @change="pickCategory($event.target.value)">
        <option value="">All categories</option>
        <option v-for="c in catalog.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
      </select>
      <select v-if="catalog.hasSubCategories" class="select" style="width: 150px" :value="q('sub_category_id')" :disabled="!subOptions.length" aria-label="Sub-category" @change="go({ sub_category_id: $event.target.value, page: undefined })">
        <option value="">All sub-categories</option>
        <option v-for="s in subOptions" :key="s.id" :value="s.id">{{ s.label }}</option>
      </select>
      <select class="select" style="width: 130px" :value="q('stock')" aria-label="Stock" data-filter="stock" @change="go({ stock: $event.target.value, page: undefined })">
        <option value="">Any stock</option>
        <option v-for="[v, l] in STOCK_FILTERS" :key="v" :value="v">{{ l }}</option>
      </select>

      </template>
      <button v-if="hasFilters" class="btn btn-outline btn-sm" type="button" @click="clearFilters">Clear</button>
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading && !rows.length" :rows="7" />
    <EmptyState v-else-if="!rows.length && !error" title="No products found" :text="hasFilters ? 'Try changing the filters or the search.' : 'Add the first product to get started.'">
      <template #icon><Package :size="42" /></template>
      <RouterLink v-if="canManage" class="btn btn-primary" :to="{ name: 'product-new' }"><Plus :size="17" /> Add product</RouterLink>
    </EmptyState>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table prod-table">
        <thead>
          <tr>
            <th v-if="canArrange" class="c" style="width: 30px" title="Drag to arrange" />
            <th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th class="c">Sold out</th><th class="c">Featured</th><th />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="p in rows" :key="p.id" class="row-link" :data-product="p.id"
            :class="{ 'is-soldout': p.is_sold_out, 'is-dragging': dragId === p.id, 'is-over': dragId && overId === p.id && dragId !== p.id }"
            @click="router.push({ name: 'product-edit', params: { id: p.id } })"
            @dragover="canArrange && dragId && ($event.preventDefault(), (overId = p.id))" @drop.prevent="canArrange && dragId && dropOn(p)"
          >
            <td v-if="canArrange" class="c grip-cell" @click.stop>
              <span class="grip" draggable="true" title="Drag to arrange the products" data-testid="product-grip" @dragstart="dragStart($event, p)" @dragend="dragEnd"><GripVertical :size="17" /></span>
            </td>
            <td>
              <div class="cell-main">
                <span class="thumb"><img v-if="p.thumb_url" :src="p.thumb_url" :alt="p.name" loading="lazy" /><template v-else>{{ p.name.slice(0, 2).toUpperCase() }}</template></span>
                <div style="min-width: 0">
                  <strong class="truncate" :title="p.name">{{ p.name }}</strong>
                  <small>{{ p.sku }}<template v-if="p.variants_count > 1"> · {{ p.variants_count }} options</template></small>
                </div>
              </div>
            </td>
            <td>
              <div class="truncate">{{ p.category?.name }}</div>
              <div v-if="catalog.hasSubCategories" class="muted truncate">{{ p.sub_category?.name }}</div>
            </td>
            <td class="nowrap price-cell">
              <strong>{{ money(p.min_price) }}</strong><s v-if="p.mrp && p.mrp !== p.min_price">{{ money(p.mrp) }}</s>
              <div v-if="p.discount_percent > 0" class="off-tag">{{ Number(p.discount_percent) }}% off</div>
            </td>
            <td>
              <span class="stock-pill" :class="{ 'is-zero': !p.is_in_stock || !p.stock, 'is-low': p.low_stock && p.stock > 0 }" :title="p.is_in_stock ? (p.low_stock ? 'Running low (all options)' : 'Units in stock (all options)') : 'Out of stock'">{{ p.stock ?? 0 }}</span>
            </td>
            <td @click.stop>
              <select class="select status-select" :class="`s-${p.status}`" :value="p.status" :disabled="!canManage" aria-label="Status" @change="changeStatus(p, $event.target.value)">
                <option v-for="s in STATUSES" :key="s" :value="s">{{ s[0].toUpperCase() + s.slice(1) }}</option>
              </select>
            </td>
            <td class="c" @click.stop>
              <label class="switch" :title="p.is_sold_out ? 'Shown as sold out: customers cannot order it' : 'Mark as sold out (stays visible, cannot be ordered)'"><input type="checkbox" :checked="p.is_sold_out" :disabled="!canManage" data-flag="sold-out" :aria-label="`Sold out: ${p.name}`" @change="setFlag(p, 'sold-out', $event.target.checked)" /><span /></label>
            </td>
            <td class="c" @click.stop>
              <label class="switch" title="Featured products are listed with ?featured=1"><input type="checkbox" :checked="p.is_featured" :disabled="!canManage" data-flag="featured" :aria-label="`Featured: ${p.name}`" @change="setFlag(p, 'featured', $event.target.checked)" /><span /></label>
            </td>
            <td @click.stop>
              <div class="row-actions">

                <RouterLink class="btn btn-soft btn-sm btn-icon" :to="{ name: 'product-edit', params: { id: p.id } }" :title="canManage ? 'Edit' : 'View'" :aria-label="canManage ? 'Edit' : 'View'"><Pencil :size="14" /></RouterLink>
                <button v-if="canManage" class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" aria-label="Delete" @click="toDelete = p"><Trash2 :size="14" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>


  <ConfirmDialog v-if="toDelete" title="Delete product" :message="`Delete &quot;${toDelete.name}&quot;? It disappears from the website. It can be restored from the database only.`" confirm-text="Delete" tone="danger" :busy="busy" @confirm="confirmDelete" @close="toDelete = null" />
</template>

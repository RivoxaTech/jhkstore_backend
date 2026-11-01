<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Package, Pencil, Plus, Search, Tag, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import QuickTagsModal from '@/components/QuickTagsModal.vue'
import TagsModal from '@/components/TagsModal.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'
import { money } from '@/utils/format.js'

const STATUSES = ['active', 'draft', 'inactive', 'archived']

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const catalog = useCatalogStore()

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(true)
const loadedOnce = ref(false)
const error = ref('')
const search = ref(String(route.query.q || ''))
const tagsFor = ref(null)      // product whose tags are being edited
const manageTags = ref(false)
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
      sub_category_id: q('sub_category_id'), tag: q('tag'), page: page.value, per_page: PER_PAGE.value,
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
const hasFilters = computed(() => ['q', 'status', 'category_id', 'sub_category_id', 'tag'].some(k => q(k)))
const clearFilters = () => { search.value = ''; router.push({ name: 'products' }) }

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

function tagsSaved(product, tags) {
  const row = rows.value.find(r => r.id === product.id)
  if (row) row.tags = tags
  tagsFor.value = null
  ui.toast('Tags saved.')
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
      <button class="btn btn-outline" type="button" @click="manageTags = true"><Tag :size="16" /> Tags</button>
      <RouterLink class="btn btn-primary" :to="{ name: 'product-new' }"><Plus :size="17" /> Add product</RouterLink>
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
      <select class="select" style="width: 150px" :value="q('sub_category_id')" :disabled="!subOptions.length" aria-label="Sub-category" @change="go({ sub_category_id: $event.target.value, page: undefined })">
        <option value="">All sub-categories</option>
        <option v-for="s in subOptions" :key="s.id" :value="s.id">{{ s.label }}</option>
      </select>
      <select class="select" style="width: 130px" :value="q('tag')" aria-label="Tag" @change="go({ tag: $event.target.value, page: undefined })">
        <option value="">All tags</option>
        <option v-for="tg in catalog.options.tags" :key="tg.id" :value="tg.slug">{{ tg.name }}</option>
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
      <RouterLink class="btn btn-primary" :to="{ name: 'product-new' }"><Plus :size="17" /> Add product</RouterLink>
    </EmptyState>

    <div v-else class="table-wrap" :style="{ opacity: loading ? 0.55 : 1, transition: 'opacity .15s' }">
      <table class="table prod-table">
        <thead>
          <tr>
            <th class="sno">#</th><th>Product</th><th>Category</th><th>Price</th><th>Stock</th><th>Tags</th><th>Status</th><th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="(p, i) in rows" :key="p.id" class="row-link" @click="router.push({ name: 'product-edit', params: { id: p.id } })">
            <td class="sno">{{ (page - 1) * PER_PAGE + i + 1 }}</td>
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
              <div class="muted truncate">{{ p.sub_category?.name }}</div>
            </td>
            <td class="nowrap price-cell">
              <strong>{{ money(p.min_price) }}</strong><s v-if="p.mrp && p.mrp !== p.min_price">{{ money(p.mrp) }}</s>
              <div v-if="p.discount_percent > 0" class="off-tag">{{ Number(p.discount_percent) }}% off</div>
            </td>
            <td>
              <span class="stock-pill" :class="{ 'is-zero': !p.is_in_stock || !p.stock }" :title="p.is_in_stock ? 'Units in stock (all options)' : 'Out of stock'">{{ p.stock ?? 0 }}</span>
            </td>
            <td>
              <div class="tags-cell">
                <span v-for="tg in p.tags.slice(0, 2)" :key="tg.id" class="tag-chip" :style="{ '--c': tg.color || undefined }"><i class="dot" />{{ tg.name }}</span>
                <span v-if="p.tags.length > 2" class="chip">+{{ p.tags.length - 2 }}</span>
                <span v-if="!p.tags.length" class="muted">-</span>
              </div>
            </td>
            <td @click.stop>
              <select class="select status-select" :class="`s-${p.status}`" :value="p.status" aria-label="Status" @change="changeStatus(p, $event.target.value)">
                <option v-for="s in STATUSES" :key="s" :value="s">{{ s[0].toUpperCase() + s.slice(1) }}</option>
              </select>
            </td>
            <td @click.stop>
              <div class="row-actions">
                <button class="btn btn-soft btn-sm btn-icon" type="button" title="Edit tags" aria-label="Edit tags" @click="tagsFor = p"><Tag :size="14" /></button>
                <RouterLink class="btn btn-soft btn-sm btn-icon" :to="{ name: 'product-edit', params: { id: p.id } }" title="Edit" aria-label="Edit"><Pencil :size="14" /></RouterLink>
                <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" aria-label="Delete" @click="toDelete = p"><Trash2 :size="14" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="go({ page: $event })" />
  </section>

  <QuickTagsModal v-if="tagsFor" :product="tagsFor" :tags="catalog.options.tags" @close="tagsFor = null" @saved="tagsSaved" />
  <TagsModal v-if="manageTags" @close="manageTags = false; catalog.reload()" />
  <ConfirmDialog v-if="toDelete" title="Delete product" :message="`Delete &quot;${toDelete.name}&quot;? It disappears from the website. It can be restored from the database only.`" confirm-text="Delete" tone="danger" :busy="busy" @confirm="confirmDelete" @close="toDelete = null" />
</template>

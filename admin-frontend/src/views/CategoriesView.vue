<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { ChevronRight, FolderTree, Pencil, Plus, Search, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import LogoBox from '@/components/LogoBox.vue'
import CategoryModal from '@/components/CategoryModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'

const MAX_TOP = 12 // top categories shown on the home screen (the API enforces it too)

const ui = useUiStore()
const auth = useAuthStore()
const canManage = computed(() => auth.can('categories.manage'))   // without it the admin can look but not change

const categories = ref([])
const meta = ref(null)                       // pagination of the top-level categories
const counts = ref({ categories: 0, top: 0, sub_categories: 0 }) // totals of everything (not only this page)
const page = ref(1)
const perPage = ref(storedPerPage())
const PER_PAGE = computed(() => perPage.value)
function setPerPage(n) { perPage.value = n; rememberPerPage(n); page.value = 1; load() }
const loading = ref(true)
const error = ref('')
const search = ref('')
const expanded = ref(new Set())  // 'c<id>' for an opened category
// A store with no sub-category at all does not show the sub-category column or rows (the "+ Sub" button stays: it is how the first one is made)
const subsExist = computed(() => Number(counts.value.sub_categories) > 0)
const modal = ref(null)          // { item, parent } while the create/edit modal is open (parent = the category of a sub-category)
const toDelete = ref(null)       // { item, level } level: 'category' | 'sub'
const busy = ref(false)

const searching = computed(() => search.value.trim() !== '')
const topCount = computed(() => counts.value.top)
const isOpen = (key) => searching.value || expanded.value.has(key)

let seq = 0
async function load() {
  const mine = ++seq
  error.value = ''
  try {
    const res = await api.get('admin/categories', { q: search.value.trim(), page: page.value, per_page: perPage.value })
    if (mine !== seq) return // a newer request is already running
    categories.value = res.data
    meta.value = res.meta
    counts.value = res.counts
    // the last row of the last page was deleted: go back one page
    if (!res.data.length && page.value > 1) { page.value = res.meta.last_page; return load() }
  } catch (e) {
    if (mine === seq) error.value = e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
onMounted(load)

let t
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; load() }, 300) })
const goPage = (n) => { page.value = n; load() }
onBeforeUnmount(() => clearTimeout(t))

function toggle(key) {
  const next = new Set(expanded.value)
  next.has(key) ? next.delete(key) : next.add(key)
  expanded.value = next
}
function expandAll() {
  expanded.value = new Set(categories.value.map(c => `c${c.id}`))
}
const collapseAll = () => { expanded.value = new Set() }

const openCreate = () => { modal.value = { item: null, parent: null } }
const openEdit = (item, parent = null) => { modal.value = { item, parent } }
const openAdd = (parent) => { modal.value = { item: null, parent } } // parent: a category (adds a sub-category to it)

async function onSaved(saved, wasEdit) {
  const parent = modal.value?.parent
  const level = parent ? 'Sub-category' : 'Category'
  modal.value = null
  ui.toast(`${level} ${wasEdit ? 'updated' : 'added'}.`)
  if (parent) expanded.value = new Set(expanded.value).add(`c${parent.id}`)
  await load()
}

// Inline switches save immediately; the row flips at once and goes back if the server refuses.
async function flip(item, field, isSub) {
  const value = !item[field]
  item[field] = value
  try {
    await api.put(`admin/${isSub ? 'sub-categories' : 'categories'}/${item.id}`, { [field]: value })
    useCatalogStore().reload().catch(() => {})
    if (field === 'is_top') counts.value.top += value ? 1 : -1
    if (field === 'is_active') {
      ui.toast(value ? 'Visible to customers.' : `Hidden from customers${isSub ? '' : ' (with its sub-categories)'}.`, 'info')
    } else if (field === 'is_top') {
      ui.toast(value ? 'Shown on the home screen.' : 'Removed from the home screen.', 'info')
    }
  } catch (e) {
    item[field] = !value
    ui.toast(e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message, 'error')
  }
}

const NOUN = { category: 'category', sub: 'sub-category' }
async function confirmDelete() {
  const { item, level } = toDelete.value
  busy.value = true
  try {
    await api.del(`admin/${level === 'category' ? 'categories' : 'sub-categories'}/${item.id}`)
    useCatalogStore().reload().catch(() => {})
    ui.toast(`${NOUN[level][0].toUpperCase()}${NOUN[level].slice(1)} deleted.`)
    toDelete.value = null
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.message, 'error', 7000) // e.g. "This category has 3 sub-categories. Deactivate it instead of deleting."
  } finally {
    busy.value = false
  }
}
const deleteMessage = computed(() => {
  if (!toDelete.value) return ''
  const { item, level } = toDelete.value
  if (level === 'sub') return `Delete ${item.name}? A sub-category that has products cannot be deleted - deactivate it instead.`
  return `Delete ${item.name}? A category that has sub-categories or products cannot be deleted - deactivate it instead.`
})
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Categories</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Categories</span></div>
    </div>
    <div class="page-actions">
      <button v-if="canManage" class="btn btn-primary" type="button" @click="openCreate"><Plus :size="17" /> Add category</button>
    </div>
  </div>

  <section class="card">
    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Search categories or sub-categories" aria-label="Search categories" />
      </div>
      <span v-if="!loading && categories.length" class="muted">
        {{ counts.categories }} categories<template v-if="subsExist"> · {{ counts.sub_categories }} sub-categories</template>
      </span>
      <span v-if="!loading && categories.length" class="top-count" :class="{ 'is-full': topCount >= MAX_TOP }" title="Categories shown on the home screen" data-test="top-count">Home: {{ topCount }}/{{ MAX_TOP }}</span>
      <div v-if="categories.length && !searching && subsExist" style="margin-left: auto; display: flex; gap: 6px">
        <button class="btn btn-outline btn-sm" type="button" @click="expandAll">Expand all</button>
        <button class="btn btn-outline btn-sm" type="button" @click="collapseAll">Collapse all</button>
      </div>
      <RowsPerPage :style="{ marginLeft: categories.length && !searching && subsExist ? '0' : 'auto' }" :model-value="perPage" @update:model-value="setPerPage" />
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading" :rows="8" />
    <EmptyState v-else-if="!categories.length && !error" :title="searching ? 'No categories found' : 'No categories yet'" :text="searching ? 'Try a different search.' : 'Add the first category to organise your products.'">
      <template #icon><FolderTree :size="42" /></template>
      <button v-if="!searching && canManage" class="btn btn-primary" type="button" @click="openCreate"><Plus :size="17" /> Add category</button>
    </EmptyState>

    <div v-else class="table-wrap">
      <table class="table cat-table">
        <thead>
          <tr>
            <th class="sno">#</th><th>Name</th><th v-if="subsExist" class="num">Sub-categories</th><th class="num">Products</th>
            <th class="tog">Active</th><th class="tog" title="Shown on the home screen (max 12)">Home</th><th class="num">Order</th><th />
          </tr>
        </thead>
        <tbody>
          <template v-for="(c, ci) in categories" :key="c.id">
            <tr class="row-link" :class="{ 'cat-row--open': isOpen(`c${c.id}`) }" data-kind="category" @click="toggle(`c${c.id}`)">
              <td class="sno">{{ (page - 1) * PER_PAGE + ci + 1 }}</td>
              <td>
                <div class="cell-main">
                  <button v-if="subsExist" class="chev" :class="{ 'is-open': isOpen(`c${c.id}`) }" type="button" :aria-expanded="isOpen(`c${c.id}`)" :aria-label="`${isOpen(`c${c.id}`) ? 'Collapse' : 'Expand'} ${c.name}`" @click.stop="toggle(`c${c.id}`)">
                    <ChevronRight :size="16" />
                  </button>
                  <LogoBox :name="c.name" :src="c.icon_thumb_url" />
                  <div style="min-width: 0">
                    <strong class="truncate" :title="c.name">{{ c.name }}</strong>
                    <small>{{ c.slug }}</small>
                  </div>
                  <span v-if="c.is_top" class="top-tag" title="Shown on the home screen">Top</span>
                </div>
              </td>
              <td v-if="subsExist" class="num">{{ c.sub_categories_count }}</td>
              <td class="num">{{ c.products_count }}</td>
              <td class="tog" @click.stop>
                <label class="switch" :title="c.is_active ? 'Active' : 'Hidden from customers'"><input type="checkbox" :checked="c.is_active" :disabled="!canManage" :aria-label="`${c.name} active`" @change="flip(c, 'is_active', false)" /><span /></label>
              </td>
              <td class="tog" @click.stop>
                <label class="switch" :title="c.is_top ? 'Shown on the home screen' : 'Not on the home screen'"><input type="checkbox" :checked="c.is_top" :disabled="!canManage" :aria-label="`${c.name} on home screen`" data-field="is_top" @change="flip(c, 'is_top', false)" /><span /></label>
              </td>
              <td class="num">{{ c.sort_order }}</td>
              <td @click.stop>
                <div v-if="canManage" class="row-actions">
                  <button class="btn btn-soft btn-sm" type="button" title="Add sub-category" :aria-label="`Add sub-category to ${c.name}`" @click="openAdd(c)"><Plus :size="14" /> Sub</button>
                  <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${c.name}`" @click="openEdit(c)"><Pencil :size="14" /></button>
                  <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${c.name}`" @click="toDelete = { item: c, level: 'category' }"><Trash2 :size="14" /></button>
                </div>
              </td>
            </tr>

            <template v-if="subsExist && isOpen(`c${c.id}`)">
              <tr v-for="(s, si) in c.sub_categories" :key="`s${s.id}`" class="cat-sub" data-kind="sub-category">
                <td class="sno">{{ (page - 1) * PER_PAGE + ci + 1 }}.{{ si + 1 }}</td>
                <td>
                  <div class="cell-main">
                    <span class="chev-gap" />
                    <LogoBox :name="s.name" :src="s.icon_thumb_url" small />
                    <div style="min-width: 0">
                      <strong class="truncate" :title="s.name">{{ s.name }}</strong>
                      <small>{{ s.slug }}</small>
                    </div>
                  </div>
                </td>
                <td v-if="subsExist" class="num muted">-</td>
                <td class="num">{{ s.products_count }}</td>
                <td class="tog">
                  <label class="switch" :title="s.is_active ? 'Active' : 'Hidden from customers'"><input type="checkbox" :checked="s.is_active" :disabled="!canManage" :aria-label="`${s.name} active`" @change="flip(s, 'is_active', true)" /><span /></label>
                </td>
                <td class="tog muted">-</td>
                <td class="num">{{ s.sort_order }}</td>
                <td>
                  <div v-if="canManage" class="row-actions">
                    <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${s.name}`" @click="openEdit(s, c)"><Pencil :size="14" /></button>
                    <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${s.name}`" @click="toDelete = { item: s, level: 'sub' }"><Trash2 :size="14" /></button>
                  </div>
                </td>
              </tr>
              <tr class="cat-sub cat-sub--add">
                <td />
                <td colspan="7">
                  <span v-if="!c.sub_categories.length" class="muted" style="margin-right: 10px">No sub-categories yet.</span>
                  <button v-if="canManage" class="btn btn-soft btn-sm" type="button" @click="openAdd(c)"><Plus :size="14" /> Add sub-category</button>
                </td>
              </tr>
            </template>
          </template>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="goPage" />
  </section>

  <CategoryModal v-if="modal" :item="modal.item" :parent="modal.parent" @close="modal = null" @saved="onSaved" />

  <ConfirmDialog
    v-if="toDelete"
    :title="`Delete ${NOUN[toDelete.level]}`"
    confirm-text="Delete"
    tone="danger"
    :busy="busy"
    :message="deleteMessage"
    @close="toDelete = null"
    @confirm="confirmDelete"
  />
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { Pencil, Plus, Search, Tags, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import LogoBox from '@/components/LogoBox.vue'
import BrandModal from '@/components/BrandModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import EmptyState from '@/components/EmptyState.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import { rememberPerPage, storedPerPage } from '@/utils/perPage.js'

const ui = useUiStore()
const catalog = useCatalogStore()

const brands = ref([])
const meta = ref(null)
const page = ref(1)
const perPage = ref(storedPerPage())
const loading = ref(true)
const error = ref('')
const search = ref('')
const activeOnly = ref(false)
const modal = ref(null)     // { brand } while the create/edit modal is open (brand = null: create)
const toDelete = ref(null)
const busy = ref(false)

let seq = 0
async function load() {
  const mine = ++seq
  error.value = ''
  try {
    const res = await api.get('admin/brands', { q: search.value.trim(), active: activeOnly.value ? 1 : undefined, page: page.value, per_page: perPage.value })
    if (mine !== seq) return
    brands.value = res.data
    meta.value = res.meta
    // the last row of the last page was deleted: go back one page
    if (!res.data.length && page.value > 1) { page.value = res.meta.last_page; return load() }
  } catch (e) {
    if (mine === seq) error.value = e.status === 404 ? 'Brands are switched off. Turn on "Enable brands" in Settings to use them.' : e.message
  } finally {
    if (mine === seq) loading.value = false
  }
}
onMounted(load)

let t
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; load() }, 300) })
watch(activeOnly, () => { page.value = 1; load() })
onBeforeUnmount(() => clearTimeout(t))
const goPage = (n) => { page.value = n; load() }
function setPerPage(n) { perPage.value = n; rememberPerPage(n); page.value = 1; load() }

async function onSaved(_saved, wasEdit) {
  modal.value = null
  ui.toast(`Brand ${wasEdit ? 'updated' : 'added'}.`)
  catalog.reload().catch(() => {}) // the product screens list the active brands
  await load()
}

// The switch saves at once; the row flips immediately and goes back if the server refuses.
async function flip(brand) {
  const value = !brand.is_active
  brand.is_active = value
  try {
    const fd = new FormData()
    fd.append('_method', 'PUT')
    fd.append('name', brand.name)
    fd.append('is_active', value ? '1' : '0')
    await api.postForm(`admin/brands/${brand.id}`, fd)
    ui.toast(value ? 'Brand is active.' : 'Brand is hidden from customers.', 'info')
    catalog.reload().catch(() => {})
  } catch (e) {
    brand.is_active = !value
    ui.toast(e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message, 'error')
  }
}

async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/brands/${toDelete.value.id}`)
    ui.toast('Brand deleted.')
    toDelete.value = null
    catalog.reload().catch(() => {})
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.message, 'error', 7000) // e.g. "This brand has 3 products. Deactivate it instead of deleting."
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Brands</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Brands</span></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="button" @click="modal = { brand: null }"><Plus :size="17" /> Add brand</button>
    </div>
  </div>

  <section class="card">
    <div class="toolbar">
      <div class="field-inline">
        <Search :size="16" />
        <input v-model="search" class="input" type="search" placeholder="Search brands" aria-label="Search brands" />
      </div>
      <label style="display: flex; gap: 8px; align-items: center">
        <span class="switch"><input v-model="activeOnly" type="checkbox" aria-label="Show active brands only" /><span /></span> Active only
      </label>
      <RowsPerPage style="margin-left: auto" :model-value="perPage" @update:model-value="setPerPage" />
    </div>

    <div v-if="error" class="alert alert--error" style="margin: 0 12px 10px" role="alert">
      {{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button>
    </div>

    <TableSkeleton v-if="loading" :rows="6" />
    <EmptyState v-else-if="!brands.length && !error" :title="search || activeOnly ? 'No brands found' : 'No brands yet'" :text="search || activeOnly ? 'Try a different search.' : 'Add the first brand to group your products.'">
      <template #icon><Tags :size="42" /></template>
      <button v-if="!search && !activeOnly" class="btn btn-primary" type="button" @click="modal = { brand: null }"><Plus :size="17" /> Add brand</button>
    </EmptyState>

    <div v-else-if="brands.length" class="table-wrap">
      <table class="table">
        <thead>
          <tr><th class="sno">#</th><th>Brand</th><th class="num" style="text-align: right">Products</th><th>Active</th><th class="num" style="text-align: right">Order</th><th /></tr>
        </thead>
        <tbody>
          <tr v-for="(b, i) in brands" :key="b.id">
            <td class="sno">{{ (page - 1) * perPage + i + 1 }}</td>
            <td>
              <div class="cell-main">
                <LogoBox :name="b.name" :src="b.logo_thumb_url || b.logo_url" />
                <div style="min-width: 0">
                  <strong class="truncate" :title="b.name">{{ b.name }}</strong>
                  <small>{{ b.slug }}</small>
                </div>
              </div>
            </td>
            <td class="num" style="text-align: right">{{ b.products_count }}</td>
            <td>
              <label class="switch" :title="b.is_active ? 'Active' : 'Hidden from customers'"><input type="checkbox" :checked="b.is_active" :aria-label="`${b.name} active`" @change="flip(b)" /><span /></label>
            </td>
            <td class="num" style="text-align: right">{{ b.sort_order }}</td>
            <td>
              <div class="row-actions">
                <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${b.name}`" @click="modal = { brand: b }"><Pencil :size="14" /></button>
                <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${b.name}`" @click="toDelete = b"><Trash2 :size="14" /></button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <PaginationBar :meta="meta" @page="goPage" />
  </section>

  <BrandModal v-if="modal" :brand="modal.brand" @close="modal = null" @saved="onSaved" />

  <ConfirmDialog
    v-if="toDelete"
    title="Delete brand"
    confirm-text="Delete"
    tone="danger"
    :busy="busy"
    :message="`Delete ${toDelete.name}? A brand that has products cannot be deleted - deactivate it instead.`"
    @close="toDelete = null"
    @confirm="confirmDelete"
  />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ArrowDown, ArrowUp, GalleryHorizontal, GripVertical, Pencil, Plus, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { formatDate } from '@/utils/format.js'
import { useUiStore } from '@/stores/ui.js'
import BannerModal from '@/components/BannerModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Banners grouped by the spot they are shown in. A spot holds one banner or several (the store decides between a single
// banner and a slider); inside a spot the order is set by drag and drop or the arrow buttons and saved at once.
const ui = useUiStore()

const banners = ref([])
const placements = ref([])      // [{ key, label }]
const loading = ref(true)
const error = ref('')
const modal = ref(null)         // { item, placement } while the create/edit modal is open
const toDelete = ref(null)
const busy = ref(false)
const dragging = ref(null)      // { placement, index }
const over = ref(null)          // { placement, index }

const groups = computed(() => placements.value.map(p => ({ ...p, items: banners.value.filter(b => b.placement === p.key) })))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [list, options] = await Promise.all([api.get('admin/banners'), api.get('admin/banners/options')])
    banners.value = list.data
    placements.value = options.placements
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

const openCreate = (placement = '') => { modal.value = { item: null, placement } }
const openEdit = (item) => { modal.value = { item, placement: item.placement } }

async function onSaved(saved, wasEdit) {
  modal.value = null
  ui.toast(wasEdit ? 'Banner updated.' : 'Banner added.')
  await load()   // the placement may have changed, so positions are re-read
}

function replace(updated) {
  banners.value = banners.value.map(b => (b.id === updated.id ? updated : b))
}

// the switch flips at once and goes back if the server refuses
async function flip(banner) {
  const value = !banner.is_active
  banner.is_active = value
  try {
    const res = await api.patch(`admin/banners/${banner.id}`, { is_active: value })
    replace(res.data)
    ui.toast(value ? 'Banner is active.' : 'Banner hidden from customers.', 'info')
  } catch (e) {
    banner.is_active = !value
    ui.toast(e.message, 'error')
  }
}

async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/banners/${toDelete.value.id}`)
    ui.toast('Banner deleted.')
    toDelete.value = null
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.message, 'error')
  } finally {
    busy.value = false
  }
}

// ---- order
async function move(placement, from, to) {
  const items = banners.value.filter(b => b.placement === placement)
  if (from === to || to < 0 || to >= items.length) return
  const before = banners.value
  items.splice(to, 0, items.splice(from, 1)[0])
  banners.value = [...banners.value.filter(b => b.placement !== placement), ...items]
  try {
    await api.put('admin/banners/reorder', { ids: items.map(b => b.id) })
  } catch (e) {
    banners.value = before
    ui.toast(e.message || 'The order could not be saved.', 'error')
  }
}

function dragStart(e, placement, index) {
  dragging.value = { placement, index }
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', String(index))
  const row = e.target.closest('.banner-row')
  if (row) e.dataTransfer.setDragImage(row, 20, 20)
}
const dragEnd = () => { dragging.value = null; over.value = null }
function drop(placement, index) {
  if (dragging.value && dragging.value.placement === placement) move(placement, dragging.value.index, index)
  dragEnd()
}
const canDrop = (placement) => dragging.value?.placement === placement

function window_(b) {
  if (!b.starts_at && !b.ends_at) return 'Always'
  return `${b.starts_at ? formatDate(b.starts_at, true) : 'now'} → ${b.ends_at ? formatDate(b.ends_at, true) : 'no end'}`
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Banners</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Banners</span></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="button" :disabled="loading || !placements.length" @click="openCreate()"><Plus :size="17" /> Add banner</button>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">{{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="6" />

  <template v-else-if="!error">
    <EmptyState v-if="!banners.length" title="No banners yet" text="Add a picture with a title and a link to promote a sale, a new collection or a delivery offer on your store.">
      <template #icon><GalleryHorizontal :size="42" /></template>
      <button class="btn btn-primary" type="button" @click="openCreate()"><Plus :size="17" /> Add the first banner</button>
    </EmptyState>

    <section v-for="g in groups" v-else :key="g.key" class="card" :data-placement="g.key" style="margin-bottom: 12px">
      <div class="card__head">
        <h2>{{ g.label }} <span class="muted">· {{ g.items.length }} {{ g.items.length === 1 ? 'banner' : 'banners' }}</span></h2>
        <button class="btn btn-soft btn-sm" type="button" @click="openCreate(g.key)"><Plus :size="14" /> Add here</button>
      </div>
      <div class="card__body">
        <p v-if="!g.items.length" class="muted" style="margin: 0">Nothing here yet. The store shows nothing in this spot.</p>
        <p v-else-if="g.items.length > 1" class="hint" style="margin: 0 0 8px">{{ g.items.length }} banners: the store can show them as a slider, in this order. Drag to reorder.</p>

        <ol v-if="g.items.length" class="banner-list">
          <li
            v-for="(b, i) in g.items" :key="b.id" class="banner-row"
            :class="{ 'is-dragging': dragging?.placement === g.key && dragging.index === i, 'is-over': canDrop(g.key) && over?.placement === g.key && over.index === i && dragging.index !== i, 'is-off': b.status !== 'live' }"
            @dragover="canDrop(g.key) && ($event.preventDefault(), (over = { placement: g.key, index: i }))" @drop.prevent="drop(g.key, i)"
          >
            <span class="banner-grip" draggable="true" title="Drag to reorder" aria-hidden="true" @dragstart="dragStart($event, g.key, i)" @dragend="dragEnd"><GripVertical :size="18" /></span>
            <div class="banner-thumb"><img :src="b.desktop_thumb_url || b.desktop_image_url" :alt="b.title || 'Banner picture'" loading="lazy" /></div>
            <div class="banner-info">
              <strong class="truncate" :title="b.title || ''">{{ b.title || '(no title)' }}</strong>
              <small v-if="b.subtitle" class="truncate">{{ b.subtitle }}</small>
              <small class="muted truncate">
                <template v-if="b.link_url">{{ b.button_text ? `${b.button_text} → ` : '' }}{{ b.link_url }}</template>
                <template v-else>No link</template>
              </small>
              <small class="muted">{{ window_(b) }}<template v-if="b.mobile_image_url"> · mobile picture</template></small>
            </div>
            <StatusBadge :status="b.status" />
            <div class="banner-actions">
              <label class="switch" :title="b.is_active ? 'Active' : 'Hidden from customers'"><input type="checkbox" :checked="b.is_active" :aria-label="`${b.title || 'Banner'} active`" @change="flip(b)" /><span /></label>
              <button class="btn btn-outline btn-sm btn-icon" type="button" :disabled="i === 0" :aria-label="`Move ${b.title || 'banner'} up`" @click="move(g.key, i, i - 1)"><ArrowUp :size="14" /></button>
              <button class="btn btn-outline btn-sm btn-icon" type="button" :disabled="i === g.items.length - 1" :aria-label="`Move ${b.title || 'banner'} down`" @click="move(g.key, i, i + 1)"><ArrowDown :size="14" /></button>
              <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${b.title || 'banner'}`" @click="openEdit(b)"><Pencil :size="14" /></button>
              <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${b.title || 'banner'}`" @click="toDelete = b"><Trash2 :size="14" /></button>
            </div>
          </li>
        </ol>
      </div>
    </section>
  </template>

  <BannerModal v-if="modal" :item="modal.item" :placements="placements" :default-placement="modal.placement" @close="modal = null" @saved="onSaved" />

  <ConfirmDialog
    v-if="toDelete" title="Delete banner" confirm-text="Delete" tone="danger" :busy="busy"
    :message="`Delete ${toDelete.title ? `“${toDelete.title}”` : 'this banner'}? Its pictures are removed too.`"
    @confirm="confirmDelete" @close="toDelete = null"
  />
</template>

<style scoped>
.banner-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.banner-row { display: grid; grid-template-columns: auto 150px minmax(0, 1fr) auto auto; gap: 12px; align-items: center; padding: 8px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
.banner-row.is-dragging { opacity: .45; }
.banner-row.is-over { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
.banner-row.is-off .banner-thumb, .banner-row.is-off .banner-info { opacity: .65; }
.banner-grip { cursor: grab; color: var(--text-3); display: grid; }
.banner-thumb { width: 150px; height: 62px; border-radius: 8px; overflow: hidden; background: var(--muted-soft); display: grid; place-items: center; }
.banner-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.banner-info { display: grid; gap: 1px; min-width: 0; }
.banner-info small { display: block; }
.banner-actions { display: flex; align-items: center; gap: 4px; }
@media (max-width: 900px) {
  .banner-row { grid-template-columns: auto 100px minmax(0, 1fr); }
  .banner-thumb { width: 100px; height: 50px; }
  .banner-row > .badge, .banner-actions { grid-column: 2 / -1; }
}
</style>

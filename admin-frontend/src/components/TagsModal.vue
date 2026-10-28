<script setup>
import { onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { Pencil, Plus, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import AppModal from '@/components/AppModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import PaginationBar from '@/components/PaginationBar.vue'
import RowsPerPage from '@/components/RowsPerPage.vue'
import { isValidPerPage, rememberPerPage, storedPerPage } from '@/utils/perPage.js'

// Manage the tags: add custom ones, rename, pick a colour, switch on/off. System tags cannot be deleted.
const emit = defineEmits(['close'])
const ui = useUiStore()

const perPage = ref(storedPerPage())
const tags = ref([])
const meta = ref(null)
const page = ref(1)
const search = ref('')
const loading = ref(true)
const message = ref('')
const errors = ref({})
const busy = ref(false)
const editing = ref(null)  // tag id being renamed
const toDelete = ref(null)
const draft = reactive({ name: '', color: '#1d70ca' })
const form = reactive({ name: '', color: '#1d70ca' })

async function load() {
  try {
    const res = await api.get('admin/tags', { page: page.value, per_page: perPage.value, q: search.value.trim() })
    tags.value = res.data
    meta.value = res.meta
    if (!res.data.length && page.value > 1) { page.value = res.meta.last_page; return load() } // the last tag of the last page was deleted
  } catch (e) {
    message.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
let t
watch(search, () => { clearTimeout(t); t = setTimeout(() => { page.value = 1; load() }, 300) })
onBeforeUnmount(() => clearTimeout(t))
const goPage = (n) => { page.value = n; load() }
function setPerPage(n) { perPage.value = n; rememberPerPage(n); page.value = 1; load() }

async function add() {
  errors.value = {}
  if (!form.name.trim()) { errors.value = { name: ['Enter a tag name.'] }; return }
  busy.value = true
  try {
    await api.post('admin/tags', { name: form.name.trim(), color: form.color })
    form.name = ''
    ui.toast('Tag added.')
    await load()
  } catch (e) {
    if (e.status === 422) errors.value = e.errors
    else message.value = e.message
  } finally {
    busy.value = false
  }
}

function startEdit(tag) {
  editing.value = tag.id
  draft.name = tag.name
  draft.color = tag.color || '#1d70ca'
}

async function saveEdit(tag) {
  errors.value = {}
  try {
    await api.put(`admin/tags/${tag.id}`, { name: draft.name.trim(), color: draft.color })
    editing.value = null
    await load()
  } catch (e) {
    if (e.status === 422) errors.value = e.errors
    else ui.toast(e.message, 'error')
  }
}

async function toggle(tag) {
  const value = !tag.is_active
  tag.is_active = value
  try {
    await api.put(`admin/tags/${tag.id}`, { is_active: value })
  } catch (e) {
    tag.is_active = !value
    ui.toast(e.message, 'error')
  }
}

async function remove() {
  const tag = toDelete.value
  busy.value = true
  try {
    await api.del(`admin/tags/${tag.id}`)
    ui.toast('Tag deleted.')
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
  <AppModal title="Product tags" large @close="emit('close')">
    <p class="muted" style="margin-bottom: 8px">The website sections are built from tags, for example <code>/products?tag=best-seller</code>. System tags stay; add your own for special sections.</p>
    <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>

    <form class="field" style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 10px" novalidate @submit.prevent="add">
      <div style="flex: 1">
        <input v-model="form.name" class="input" :class="{ 'has-error': errors.name }" placeholder="New tag name, e.g. Diwali Offer" maxlength="60" aria-label="New tag name" />
        <p v-if="errors.name" class="err">{{ errors.name[0] }}</p>
      </div>
      <input v-model="form.color" type="color" aria-label="Tag colour" style="width: 38px; height: 34px; border: 1px solid var(--border); border-radius: 8px; padding: 2px; background: #fff" />
      <button class="btn btn-primary" type="submit" :disabled="busy"><Plus :size="16" /> Add</button>
    </form>

    <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 6px">
      <input v-model="search" class="input" type="search" placeholder="Search tags" aria-label="Search tags" />
      <RowsPerPage :model-value="perPage" @update:model-value="setPerPage" />
    </div>
    <div v-if="loading" class="muted">Loading...</div>
    <div v-else-if="!tags.length" class="muted" style="padding: 8px 0">No tags found.</div>
    <div v-for="t in tags" :key="t.id" class="tag-row">
      <span class="color-dot" :style="{ '--c': t.color || undefined }" />
      <div v-if="editing === t.id" style="display: flex; gap: 6px; align-items: center">
        <input v-model="draft.name" class="input" style="height: 28px" maxlength="60" :aria-label="`Name of ${t.name}`" @keyup.enter="saveEdit(t)" />
        <input v-model="draft.color" type="color" aria-label="Colour" style="width: 32px; height: 28px; border: 1px solid var(--border); border-radius: 6px; padding: 1px; background: #fff" />
        <button class="btn btn-primary btn-sm" type="button" @click="saveEdit(t)">Save</button>
        <button class="btn btn-outline btn-sm" type="button" @click="editing = null">Cancel</button>
        <span v-if="errors.name" class="err">{{ errors.name[0] }}</span>
      </div>
      <div v-else style="min-width: 0">
        <strong style="font-weight: 500">{{ t.name }}</strong>
        <span class="muted"> · {{ t.slug }} · {{ t.products_count }} product{{ t.products_count === 1 ? '' : 's' }}</span>
        <span v-if="t.is_system" class="badge badge--plain" style="margin-left: 6px">System</span>
      </div>
      <label class="switch" :aria-label="`${t.name} active`"><input type="checkbox" :checked="t.is_active" @change="toggle(t)" /><span /></label>
      <button class="btn btn-soft btn-sm btn-icon" type="button" :aria-label="`Rename ${t.name}`" @click="startEdit(t)"><Pencil :size="14" /></button>
      <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :disabled="t.is_system" :title="t.is_system ? 'System tags cannot be deleted' : 'Delete'" :aria-label="`Delete ${t.name}`" @click="toDelete = t"><Trash2 :size="14" /></button>
    </div>
    <PaginationBar :meta="meta" @page="goPage" />
    <template #footer>
      <button class="btn btn-primary" type="button" @click="emit('close')">Done</button>
    </template>
  </AppModal>
  <ConfirmDialog v-if="toDelete" title="Delete tag" :message="`Delete the tag &quot;${toDelete.name}&quot;? It is removed from ${toDelete.products_count} product(s).`" confirm-text="Delete" tone="danger" :busy="busy" @confirm="remove" @close="toDelete = null" />
</template>

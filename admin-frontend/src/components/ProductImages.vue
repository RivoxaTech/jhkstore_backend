<script setup>
import { computed, ref } from 'vue'
import { ArrowLeft, ArrowRight, Star, Trash2, UploadCloud } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'

// One gallery of pictures: the product's own (variantId = null) or the pictures of one option (variantId = its id).
// Upload (several at once), reorder, delete, "make primary" (the first picture of a gallery is its primary one).
// `immediate`: the product (and the option) is saved, so every action calls the API at once and `modelValue` (the server's full
// list of pictures; this component only shows its own gallery) is refreshed from the answer.
// Not immediate: files are only collected in `pending` and the parent uploads them right after saving.
const MAX = 12
const MAX_MB = 8
const EXT = ['png', 'jpg', 'jpeg', 'webp']

const props = defineProps({
  productId: { type: Number, default: null },
  variantId: { type: Number, default: null },
  immediate: { type: Boolean, default: false },
  compact: { type: Boolean, default: false },
})
const images = defineModel({ type: Array, default: () => [] })       // server list: [{ id, source, variant_id, url, thumb_url, is_primary }]
const pending = defineModel('pending', { type: Array, default: () => [] }) // [{ key, file, preview }]
const busy = defineModel('busy', { type: Boolean, default: false })
const ui = useUiStore()

const mine = computed(() => images.value.filter(i => (i.variant_id ?? null) === props.variantId))
const items = computed(() => props.immediate
  ? mine.value.map(i => ({ key: i.id, id: i.id, src: i.thumb_url || i.url }))
  : pending.value.map(p => ({ key: p.key, id: p.key, src: p.preview })))

function validFiles(list) {
  const ok = []
  for (const f of list) {
    const ext = f.name.split('.').pop().toLowerCase()
    if (!EXT.includes(ext)) ui.toast(`${f.name}: use PNG, JPG or WEBP.`, 'error')
    else if (f.size > MAX_MB * 1024 * 1024) ui.toast(`${f.name} is over ${MAX_MB} MB.`, 'error')
    else ok.push(f)
  }
  if (items.value.length + ok.length > MAX) {
    ui.toast(`A gallery can have at most ${MAX} pictures.`, 'error')
    return ok.slice(0, Math.max(0, MAX - items.value.length))
  }
  return ok
}

async function pick(e) {
  const files = validFiles([...e.target.files])
  e.target.value = ''
  if (!files.length) return
  if (!props.immediate) {
    pending.value = [...pending.value, ...files.map(file => ({ key: `n${Math.random().toString(36).slice(2)}`, file, preview: URL.createObjectURL(file) }))]
    return
  }
  const form = new FormData()
  files.forEach(f => form.append('images[]', f))
  if (props.variantId !== null) form.append('variant_id', props.variantId)
  await run(() => api.postForm(`admin/products/${props.productId}/images`, form), `${files.length} picture${files.length > 1 ? 's' : ''} added.`)
}

async function run(call, message) {
  busy.value = true
  try {
    const res = await call()
    images.value = res.data
    if (message) ui.toast(message)
  } catch (e) {
    ui.toast(e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message, 'error')
  } finally {
    busy.value = false
  }
}

function makePrimary(index) {
  if (index === 0) return
  if (!props.immediate) return move(index, 0)
  run(() => api.put(`admin/products/${props.productId}/images/${items.value[index].id}/primary`), 'Primary picture changed.')
}

function move(from, to) {
  if (from === to || to < 0 || to >= items.value.length) return
  if (!props.immediate) {
    const list = [...pending.value]
    list.splice(to, 0, list.splice(from, 1)[0])
    pending.value = list
    return
  }
  const ids = mine.value.map(i => i.id) // uploaded pictures and imported (remote) ones share one order
  ids.splice(to, 0, ids.splice(from, 1)[0])
  run(() => api.put(`admin/products/${props.productId}/images/order`, { ids }), 'Order saved.')
}

// drag and drop: drag a picture onto another one to put it there
const dragFrom = ref(null)
const dragOver = ref(null)
function onDragStart(e, i) {
  if (busy.value) return e.preventDefault()
  dragFrom.value = i
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', String(i)) // Firefox needs data to start a drag
}
const onDragOver = (e, i) => { if (dragFrom.value !== null) { e.preventDefault(); dragOver.value = i } }
function onDrop(e, i) {
  e.preventDefault()
  const from = dragFrom.value
  dragFrom.value = dragOver.value = null
  if (from !== null) move(from, i)
}
const onDragEnd = () => { dragFrom.value = dragOver.value = null }

function remove(index) {
  if (!props.immediate) {
    pending.value = pending.value.filter((_, i) => i !== index)
    return
  }
  run(() => api.del(`admin/products/${props.productId}/images/${items.value[index].id}`), 'Picture deleted.')
}
</script>

<template>
  <div class="pic-gallery" :class="{ 'is-compact': compact }">
    <div v-if="items.length" class="img-grid">
      <div
        v-for="(img, i) in items" :key="img.key" class="img-card" draggable="true" :class="{ 'is-primary': i === 0, 'is-dragging': dragFrom === i, 'is-over': dragOver === i && dragFrom !== i }"
        data-testid="picture" @dragstart="onDragStart($event, i)" @dragover="onDragOver($event, i)" @dragleave="dragOver = null" @drop="onDrop($event, i)" @dragend="onDragEnd"
      >
        <span v-if="i === 0" class="primary-tag">Primary</span>
        <div class="pic"><img :src="img.src" :alt="`Picture ${i + 1}`" loading="lazy" /></div>
        <div class="bar">
          <button type="button" :disabled="busy || i === 0" title="Make primary" aria-label="Make primary" @click="makePrimary(i)"><Star :size="14" /></button>
          <button type="button" :disabled="busy || i === 0" title="Move left" aria-label="Move left" @click="move(i, i - 1)"><ArrowLeft :size="14" /></button>
          <button type="button" :disabled="busy || i >= items.length - 1" title="Move right" aria-label="Move right" @click="move(i, i + 1)"><ArrowRight :size="14" /></button>
          <button type="button" class="danger" :disabled="busy" title="Delete" aria-label="Delete picture" @click="remove(i)"><Trash2 :size="14" /></button>
        </div>
      </div>
    </div>
    <label class="dropzone" :style="{ marginTop: items.length ? '8px' : '0' }">
      <UploadCloud :size="20" style="margin: 0 auto 2px" />
      <div><strong>Add pictures</strong> (several at once)</div>
      <small v-if="!compact" class="muted">PNG, JPG or WEBP, up to {{ MAX_MB }} MB each, max {{ MAX }} pictures. The first one is the primary picture. Drag a picture to move it.</small>
      <input type="file" accept=".png,.jpg,.jpeg,.webp" multiple hidden :disabled="busy" @change="pick" />
    </label>
    <p v-if="!immediate && items.length" class="hint" style="font-size: 11.5px; color: var(--text-3); margin-top: 6px">These pictures are uploaded when you save the product.</p>
  </div>
</template>

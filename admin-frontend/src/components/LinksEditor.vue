<script setup>
import { ref } from 'vue'
import { ArrowDown, ArrowUp, GripVertical, Plus, Trash2 } from '@lucide/vue'

// Editable, ordered list of links { platform, label, url, active } with drag-to-reorder (the grip), move up / down buttons
// (keyboard friendly), add and remove. Every row needs a `_key` (see utils/links.js) so Vue keeps the right row while it moves.
// `errors` are the server's validation errors, keyed like "social_links.2.url".
const props = defineProps({
  modelValue: { type: Array, required: true },
  platforms: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  field: { type: String, default: 'social_links' },
})
const emit = defineEmits(['update:modelValue'])

const LABELS = { facebook: 'Facebook', instagram: 'Instagram', linkedin: 'LinkedIn', x: 'X (Twitter)', youtube: 'YouTube', tiktok: 'TikTok', whatsapp: 'WhatsApp', pinterest: 'Pinterest', website: 'Website', custom: 'Other link' }
const PLACEHOLDERS = { whatsapp: 'https://wa.me/491701234567', facebook: 'https://facebook.com/yourpage', instagram: 'https://instagram.com/yourname' }

const dragging = ref(-1)
const over = ref(-1)
let seq = 0

const set = (list) => emit('update:modelValue', list)
const err = (i, key) => props.errors[`${props.field}.${i}.${key}`]?.[0] || ''

function patch(i, changes) {
  set(props.modelValue.map((row, n) => (n === i ? { ...row, ...changes } : row)))
}

function add() {
  set([...props.modelValue, { _key: `new-${Date.now()}-${seq++}`, platform: 'facebook', label: '', url: '', active: true }])
}

const remove = (i) => set(props.modelValue.filter((_, n) => n !== i))

function move(from, to) {
  if (from === to || to < 0 || to >= props.modelValue.length) return
  const list = [...props.modelValue]
  list.splice(to, 0, list.splice(from, 1)[0])
  set(list)
}

function dragStart(e, i) {
  dragging.value = i
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', String(i))        // Firefox needs data to start a drag
  const row = e.target.closest('.link-row')
  if (row) e.dataTransfer.setDragImage(row, 20, 20)
}
function dragEnd() { dragging.value = -1; over.value = -1 }
function drop(i) {
  if (dragging.value >= 0) move(dragging.value, i)
  dragEnd()
}
</script>

<template>
  <div class="links-editor">
    <p v-if="!modelValue.length" class="muted links-empty">No links yet. Add your social media pages, a WhatsApp chat or any other link.</p>

    <ol class="links-list">
      <li
        v-for="(row, i) in modelValue" :key="row._key" class="link-row"
        :class="{ 'is-dragging': dragging === i, 'is-over': over === i && dragging !== i, 'is-inactive': !row.active }"
        @dragover.prevent="over = i" @dragleave="over === i && (over = -1)" @drop.prevent="drop(i)"
      >
        <span class="link-grip" draggable="true" title="Drag to reorder" aria-hidden="true" @dragstart="dragStart($event, i)" @dragend="dragEnd"><GripVertical :size="18" /></span>

        <div class="link-fields">
          <div class="field">
            <label :for="`lk-p-${row._key}`" class="sr-only">Platform</label>
            <select :id="`lk-p-${row._key}`" class="select" :value="row.platform" :class="{ 'has-error': err(i, 'platform') }" @change="patch(i, { platform: $event.target.value })">
              <option v-for="p in platforms" :key="p" :value="p">{{ LABELS[p] || p }}</option>
            </select>
          </div>
          <div class="field">
            <label :for="`lk-l-${row._key}`" class="sr-only">Label</label>
            <input :id="`lk-l-${row._key}`" class="input" type="text" maxlength="60" placeholder="Label (optional)" :value="row.label" :class="{ 'has-error': err(i, 'label') }" @input="patch(i, { label: $event.target.value })" />
            <p v-if="err(i, 'label')" class="err">{{ err(i, 'label') }}</p>
          </div>
          <div class="field link-url">
            <label :for="`lk-u-${row._key}`" class="sr-only">URL</label>
            <input :id="`lk-u-${row._key}`" class="input" type="url" maxlength="500" :placeholder="PLACEHOLDERS[row.platform] || 'https://'" :value="row.url" :class="{ 'has-error': err(i, 'url') }" @input="patch(i, { url: $event.target.value })" />
            <p v-if="err(i, 'url')" class="err">{{ err(i, 'url') }}</p>
          </div>
        </div>

        <div class="link-actions">
          <label class="switch" :title="row.active ? 'Shown in the store' : 'Hidden'"><input type="checkbox" :checked="row.active" :aria-label="`Show link ${i + 1} in the store`" @change="patch(i, { active: $event.target.checked })" /><span /></label>
          <button class="btn btn-outline btn-icon btn-sm" type="button" :disabled="i === 0" :aria-label="`Move link ${i + 1} up`" @click="move(i, i - 1)"><ArrowUp :size="16" /></button>
          <button class="btn btn-outline btn-icon btn-sm" type="button" :disabled="i === modelValue.length - 1" :aria-label="`Move link ${i + 1} down`" @click="move(i, i + 1)"><ArrowDown :size="16" /></button>
          <button class="btn btn-soft-danger btn-icon btn-sm" type="button" :aria-label="`Remove link ${i + 1}`" @click="remove(i)"><Trash2 :size="16" /></button>
        </div>
      </li>
    </ol>

    <button class="btn btn-outline btn-sm" type="button" @click="add"><Plus :size="16" /> Add link</button>
  </div>
</template>

<style scoped>
.links-list { list-style: none; margin: 0 0 10px; padding: 0; display: grid; gap: 8px; }
.link-row { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: start; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
.link-row.is-dragging { opacity: .45; }
.link-row.is-over { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
.link-row.is-inactive .link-fields { opacity: .6; }
.link-grip { cursor: grab; color: var(--text-3); padding-top: 8px; display: grid; }
.link-fields { display: grid; grid-template-columns: 150px minmax(120px, 200px) minmax(180px, 1fr); gap: 8px; min-width: 0; }
.link-actions { display: flex; align-items: center; gap: 4px; padding-top: 3px; }
.links-empty { margin: 0 0 10px; }
@media (max-width: 760px) {
  .link-row { grid-template-columns: auto 1fr; }
  .link-actions { grid-column: 1 / -1; justify-content: flex-end; }
  .link-fields { grid-template-columns: 1fr; }
}
</style>

<script setup>
import { ref } from 'vue'
import { ArrowDown, ArrowUp, GripVertical, Plus, Trash2 } from '@lucide/vue'

// Editable, ordered list of bank accounts { bank, title, number, iban, active } with drag-to-reorder (the grip), move up / down buttons,
// add, remove and an "active" switch (an inactive account is hidden from customers). Modelled on LinksEditor.
// Every row needs a `_key` so Vue keeps the right row while it moves. `errors` are the server's validation errors, keyed like "pay_bank_accounts.2.bank".
const props = defineProps({
  modelValue: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  field: { type: String, default: 'pay_bank_accounts' },
})
const emit = defineEmits(['update:modelValue'])

const dragging = ref(-1)
const over = ref(-1)
let seq = 0

const set = (list) => emit('update:modelValue', list)
const err = (i, key) => props.errors[`${props.field}.${i}.${key}`]?.[0] || ''

function patch(i, changes) {
  set(props.modelValue.map((row, n) => (n === i ? { ...row, ...changes } : row)))
}

function add() {
  set([...props.modelValue, { _key: `acct-${Date.now()}-${seq++}`, bank: '', title: '', number: '', iban: '', active: true }])
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
  e.dataTransfer.setData('text/plain', String(i))
  const row = e.target.closest('.acct-row')
  if (row) e.dataTransfer.setDragImage(row, 20, 20)
}
function dragEnd() { dragging.value = -1; over.value = -1 }
function drop(i) {
  if (dragging.value >= 0) move(dragging.value, i)
  dragEnd()
}
</script>

<template>
  <div class="accounts-editor">
    <p v-if="!modelValue.length" class="muted acct-empty">No bank accounts yet. Add the account customers pay into.</p>

    <ol class="acct-list">
      <li
        v-for="(row, i) in modelValue" :key="row._key" class="acct-row"
        :class="{ 'is-dragging': dragging === i, 'is-over': over === i && dragging !== i, 'is-inactive': !row.active }"
        @dragover.prevent="over = i" @dragleave="over === i && (over = -1)" @drop.prevent="drop(i)"
      >
        <span class="acct-grip" draggable="true" title="Drag to reorder" aria-hidden="true" @dragstart="dragStart($event, i)" @dragend="dragEnd"><GripVertical :size="18" /></span>

        <div class="acct-fields">
          <div class="field">
            <label :for="`ac-b-${row._key}`" class="sr-only">Bank name</label>
            <input :id="`ac-b-${row._key}`" class="input acct-bank" type="text" maxlength="80" placeholder="Bank name *" :value="row.bank" :class="{ 'has-error': err(i, 'bank') }" @input="patch(i, { bank: $event.target.value })" />
            <p v-if="err(i, 'bank')" class="err">{{ err(i, 'bank') }}</p>
          </div>
          <div class="field">
            <label :for="`ac-t-${row._key}`" class="sr-only">Account title</label>
            <input :id="`ac-t-${row._key}`" class="input acct-title" type="text" maxlength="120" placeholder="Account title" :value="row.title" :class="{ 'has-error': err(i, 'title') }" @input="patch(i, { title: $event.target.value })" />
            <p v-if="err(i, 'title')" class="err">{{ err(i, 'title') }}</p>
          </div>
          <div class="field">
            <label :for="`ac-n-${row._key}`" class="sr-only">Account number</label>
            <input :id="`ac-n-${row._key}`" class="input acct-number" type="text" maxlength="40" placeholder="Account number" :value="row.number" :class="{ 'has-error': err(i, 'number') }" @input="patch(i, { number: $event.target.value })" />
            <p v-if="err(i, 'number')" class="err">{{ err(i, 'number') }}</p>
          </div>
          <div class="field">
            <label :for="`ac-i-${row._key}`" class="sr-only">IBAN</label>
            <input :id="`ac-i-${row._key}`" class="input acct-iban" type="text" maxlength="40" placeholder="IBAN" :value="row.iban" :class="{ 'has-error': err(i, 'iban') }" @input="patch(i, { iban: $event.target.value })" />
            <p v-if="err(i, 'iban')" class="err">{{ err(i, 'iban') }}</p>
          </div>
        </div>

        <div class="acct-actions">
          <label class="switch" :title="row.active ? 'Shown to customers' : 'Hidden from customers'"><input type="checkbox" :checked="row.active" :aria-label="`Account ${i + 1} active`" @change="patch(i, { active: $event.target.checked })" /><span /></label>
          <button class="btn btn-outline btn-icon btn-sm" type="button" :disabled="i === 0" :aria-label="`Move account ${i + 1} up`" @click="move(i, i - 1)"><ArrowUp :size="16" /></button>
          <button class="btn btn-outline btn-icon btn-sm" type="button" :disabled="i === modelValue.length - 1" :aria-label="`Move account ${i + 1} down`" @click="move(i, i + 1)"><ArrowDown :size="16" /></button>
          <button class="btn btn-soft-danger btn-icon btn-sm" type="button" :aria-label="`Remove account ${i + 1}`" @click="remove(i)"><Trash2 :size="16" /></button>
        </div>
      </li>
    </ol>

    <button class="btn btn-outline btn-sm" type="button" data-add-account @click="add"><Plus :size="16" /> Add account</button>
  </div>
</template>

<style scoped>
.acct-list { list-style: none; margin: 0 0 10px; padding: 0; display: grid; gap: 8px; }
.acct-row { display: grid; grid-template-columns: auto 1fr auto; gap: 10px; align-items: start; padding: 8px 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--field); }
.acct-row.is-dragging { opacity: .45; }
.acct-row.is-over { border-color: var(--primary); box-shadow: 0 0 0 2px var(--primary-soft); }
.acct-row.is-inactive .acct-fields { opacity: .6; }
.acct-grip { cursor: grab; color: var(--text-3); padding-top: 8px; display: grid; }
.acct-fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; min-width: 0; }
.acct-actions { display: flex; align-items: center; gap: 4px; padding-top: 3px; }
.acct-empty { margin: 0 0 10px; }
@media (max-width: 900px) {
  .acct-row { grid-template-columns: auto 1fr; }
  .acct-actions { grid-column: 1 / -1; justify-content: flex-end; }
  .acct-fields { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 560px) { .acct-fields { grid-template-columns: 1fr; } }
</style>

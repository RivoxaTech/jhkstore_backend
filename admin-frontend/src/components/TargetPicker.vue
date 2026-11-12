<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { Search, X } from '@lucide/vue'
import { api } from '@/api/client.js'

// Searchable multi-select of products, categories or sub-categories (GET admin/promotion-targets). The chosen items are chips with a remove button.
// v-model = [{ id, name }]. The list shows up to 20 matches and hides what is already chosen.
const props = defineProps({
  modelValue: { type: Array, required: true },
  type: { type: String, required: true },          // product | category | sub_category
  invalid: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])

const LABELS = { product: 'products', category: 'categories', sub_category: 'sub-categories' }

const term = ref('')
const results = ref([])
const open = ref(false)
const busy = ref(false)
let timer
let seq = 0

const chosen = computed(() => new Set(props.modelValue.map(t => t.id)))
const options = computed(() => results.value.filter(r => !chosen.value.has(r.id)))

async function search() {
  const mine = ++seq
  busy.value = true
  try {
    const res = await api.get('admin/promotion-targets', { type: props.type, q: term.value.trim() })
    if (mine === seq) results.value = res.data
  } catch {
    if (mine === seq) results.value = []
  } finally {
    if (mine === seq) busy.value = false
  }
}

watch(term, () => { clearTimeout(timer); timer = setTimeout(search, 250) })
watch(() => props.type, () => { results.value = []; term.value = ''; if (open.value) search() })
onBeforeUnmount(() => clearTimeout(timer))

function show() { open.value = true; if (!results.value.length) search() }
function hide() { open.value = false }
function add(item) { emit('update:modelValue', [...props.modelValue, { id: item.id, name: item.name }]); term.value = '' }
const remove = (id) => emit('update:modelValue', props.modelValue.filter(t => t.id !== id))
</script>

<template>
  <div class="target-picker" :data-type="type">
    <div v-if="modelValue.length" class="tp-chips">
      <span v-for="t in modelValue" :key="t.id" class="chip-x" data-chip><span :title="t.name">{{ t.name }}</span><button type="button" :aria-label="`Remove ${t.name}`" @click="remove(t.id)"><X :size="12" /></button></span>
    </div>
    <div class="tp-box">
      <Search :size="15" />
      <input v-model="term" class="input tp-input" :class="{ 'has-error': invalid }" type="search" autocomplete="off" :placeholder="`Search ${LABELS[type]} to add`" :aria-label="`Search ${LABELS[type]}`" @focus="show" @blur="hide" />
      <ul v-if="open" class="tp-list" role="listbox">
        <li v-if="busy && !options.length" class="tp-note">Searching...</li>
        <li v-else-if="!options.length" class="tp-note">{{ term.trim() ? 'No match.' : 'Nothing more to add.' }}</li>
        <li v-for="r in options" :key="r.id" role="option" class="tp-option" @mousedown.prevent="add(r)">{{ r.name }}</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.tp-chips { display: flex; flex-wrap: wrap; gap: 5px; margin-bottom: 6px; max-height: 96px; overflow: auto; }
.tp-chips .chip-x { max-width: 220px; }
.tp-box { position: relative; }
.tp-box > svg { position: absolute; left: 11px; top: 10px; color: var(--text-3); pointer-events: none; }
.tp-input { padding-left: 34px; }
.tp-list { position: absolute; z-index: 20; left: 0; right: 0; top: calc(100% + 2px); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow-md); max-height: 220px; overflow: auto; list-style: none; margin: 0; padding: 3px; }
.tp-option { padding: 6px 9px; border-radius: 6px; cursor: pointer; font-size: 12.5px; }
.tp-option:hover { background: var(--primary-soft); color: var(--primary-dark); }
.tp-note { padding: 7px 9px; color: var(--text-3); font-size: 12px; }
</style>

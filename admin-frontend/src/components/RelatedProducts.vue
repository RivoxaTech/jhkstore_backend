<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { Search, X } from '@lucide/vue'
import { api } from '@/api/client.js'

// Pick the "related products" shown on a product page: search by name or SKU, click to add, x to remove. The order is the order of the chips.
const MAX = 12
const props = defineProps({ excludeId: { type: Number, default: null } })
const selected = defineModel({ type: Array, default: () => [] }) // [{ id, name, sku, thumb_url }]

const term = ref('')
const results = ref([])
const open = ref(false)
const busy = ref(false)
let t
let seq = 0

watch(term, (v) => {
  clearTimeout(t)
  if (v.trim().length < 2) { results.value = []; return }
  t = setTimeout(async () => {
    const mine = ++seq
    busy.value = true
    try {
      const res = await api.get('admin/products', { q: v.trim(), per_page: 8 })
      if (mine === seq) results.value = res.data.filter(p => p.id !== props.excludeId && !selected.value.some(s => s.id === p.id))
    } catch { results.value = [] } finally { if (mine === seq) busy.value = false }
  }, 300)
})
onBeforeUnmount(() => clearTimeout(t))

function add(p) {
  if (selected.value.length >= MAX) return
  selected.value = [...selected.value, { id: p.id, name: p.name, sku: p.sku, thumb_url: p.thumb_url }]
  results.value = results.value.filter(r => r.id !== p.id)
  term.value = ''
  open.value = false
}
const closeSoon = () => setTimeout(() => { open.value = false }, 200)
const remove = (id) => { selected.value = selected.value.filter(s => s.id !== id) }
</script>

<template>
  <div class="related">
    <div v-for="s in selected" :key="s.id" class="related__item">
      <span class="thumb"><img v-if="s.thumb_url" :src="s.thumb_url" :alt="s.name" /><template v-else>{{ s.name.slice(0, 2).toUpperCase() }}</template></span>
      <div class="grow"><strong class="truncate" :title="s.name">{{ s.name }}</strong><small class="muted">{{ s.sku }}</small></div>
      <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Remove ${s.name}`" @click="remove(s.id)"><X :size="14" /></button>
    </div>
    <div v-if="selected.length < MAX" class="field-inline related__search">
      <Search :size="15" />
      <input v-model="term" class="input" type="search" placeholder="Search a product to add" aria-label="Search related product" @focus="open = true" @blur="closeSoon" />
      <div v-if="open && term.trim().length >= 2" class="related__results">
        <div v-if="busy" class="muted" style="padding: 8px 10px">Searching...</div>
        <div v-else-if="!results.length" class="muted" style="padding: 8px 10px">No product found.</div>
        <button v-for="p in results" :key="p.id" type="button" class="related__result" @mousedown.prevent="add(p)">
          <span class="thumb"><img v-if="p.thumb_url" :src="p.thumb_url" :alt="p.name" /><template v-else>{{ p.name.slice(0, 2).toUpperCase() }}</template></span>
          <span class="grow"><strong class="truncate">{{ p.name }}</strong><small class="muted">{{ p.sku }} · {{ p.status }}</small></span>
        </button>
      </div>
    </div>
    <p class="hint" style="font-size: 11.5px; color: var(--text-3); margin: 0">
      Up to {{ MAX }}. When none are chosen, the website shows popular products of the same category.
    </p>
  </div>
</template>

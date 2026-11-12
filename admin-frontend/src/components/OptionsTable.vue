<script setup>
import { computed, ref } from 'vue'
import { ChevronDown, ChevronRight, Plus, Trash2 } from '@lucide/vue'
import ProductImages from '@/components/ProductImages.vue'
import { useCatalogStore } from '@/stores/catalog.js'

// The options (variants) of a product as one compact editable table: default, name, SKU, regular price, selling price, stock, low-stock alert,
// active. A row has a "more" row with the weight and the option's own pictures. The rows are edited in place; the server's validation messages
// (`errors`, keyed like the API: variants.0.sku) show under the cell. (There is no bulk price editor here: the shop does not use quantity prices.)
const props = defineProps({
  rows: { type: Array, required: true },
  errors: { type: Object, default: () => ({}) },
  productId: { type: Number, default: null },
  images: { type: Array, default: () => [] },
})
const emit = defineEmits(['clear', 'add', 'remove', 'default', 'active', 'images'])
const catalog = useCatalogStore()
const symbol = computed(() => catalog.currency.symbol)

const err = (i, key) => props.errors[`variants.${i}.${key}`]?.[0] || ''
const clear = (i, ...keys) => emit('clear', keys.map(k => `variants.${i}.${k}`))

const rowHasError = (i) => Object.keys(props.errors).some(k => k.startsWith(`variants.${i}.`))

const num = (v) => (v === '' || v === null || v === undefined ? NaN : Number(v))
const off = (r) => {
  const m = num(r.mrp), p = num(r.selling_price)
  if (!(m > 0) || Number.isNaN(p)) return ''
  const d = Math.round(((m - p) / m) * 10000) / 100
  return d > 0 ? `${d}% off` : d < 0 ? 'above MRP' : ''
}

const activeCount = computed(() => props.rows.filter(r => r.is_active).length)

const moreFor = ref(new Set())
const root = ref(null)
const toggleMore = (i) => { const next = new Set(moreFor.value); next.has(i) ? next.delete(i) : next.add(i); moreFor.value = next }
const immediate = (r) => props.productId !== null && r.id !== null
</script>

<template>
  <div ref="root" class="opt">
    <div class="table-wrap opt__wrap">
      <table class="table opt-table" data-testid="options-table">
        <thead>
          <tr>
            <th title="Preselected on the product page">Default</th><th>Name <span class="req">*</span></th><th>SKU <span class="req">*</span></th>
            <th title="Optional. The crossed-out price. Empty = the price below.">MRP ({{ symbol }})</th><th>Price ({{ symbol }}) <span class="req">*</span></th><th>Stock</th><th title="Alert when this many or fewer are left">Alert at</th><th>Active</th><th />
          </tr>
        </thead>
        <tbody>
          <template v-for="(r, i) in rows" :key="r.id ?? `n${i}`">
            <tr :class="{ 'is-bad': rowHasError(i), 'is-off': !r.is_active }" :data-option="i">
              <td class="c"><input type="radio" name="default-option" :checked="r.is_default" :disabled="!r.is_active" :aria-label="`Make option ${i + 1} the default`" @change="emit('default', i)" /></td>
              <td>
                <input :id="`variants-${i}-name`" v-model="r.name" class="input input--sm" maxlength="255" :aria-label="`Option ${i + 1} name`" placeholder="e.g. Blue, 500 ml" :class="{ 'has-error': err(i, 'name') }" @input="clear(i, 'name')" />
                <p v-if="err(i, 'name')" class="err">{{ err(i, 'name') }}</p>
              </td>
              <td>
                <input :id="`variants-${i}-sku`" v-model="r.sku" class="input input--sm" maxlength="80" :aria-label="`Option ${i + 1} SKU`" :class="{ 'has-error': err(i, 'sku') }" @input="clear(i, 'sku')" />
                <p v-if="err(i, 'sku')" class="err">{{ err(i, 'sku') }}</p>
              </td>
              <td>
                <input :id="`variants-${i}-mrp`" v-model="r.mrp" class="input input--sm input--num" type="number" min="0" step="0.01" placeholder="optional" :aria-label="`Option ${i + 1} regular price (optional)`" :class="{ 'has-error': err(i, 'mrp') }" @input="clear(i, 'mrp', 'selling_price')" />
                <p v-if="err(i, 'mrp')" class="err">{{ err(i, 'mrp') }}</p>
              </td>
              <td>
                <input :id="`variants-${i}-price`" v-model="r.selling_price" class="input input--sm input--num" type="number" min="0" step="0.01" :aria-label="`Option ${i + 1} price`" :class="{ 'has-error': err(i, 'selling_price') }" @input="clear(i, 'selling_price')" />
                <small v-if="off(r)" class="off-tag" data-testid="off-label">{{ off(r) }}</small>
                <p v-if="err(i, 'selling_price')" class="err">{{ err(i, 'selling_price') }}</p>
              </td>
              <td>
                <input :id="`variants-${i}-stock`" v-model="r.stock" class="input input--sm input--num" type="number" min="0" step="1" :aria-label="`Stock of option ${i + 1}`" :class="{ 'has-error': err(i, 'stock') }" @input="clear(i, 'stock')" />
                <small v-if="r.reserved" class="muted" :title="`${r.reserved} reserved by open orders`">{{ r.reserved }} reserved</small>
                <p v-if="err(i, 'stock')" class="err">{{ err(i, 'stock') }}</p>
              </td>
              <td>
                <input v-model="r.low_stock_threshold" class="input input--sm input--num" type="number" min="0" step="1" :aria-label="`Low-stock alert of option ${i + 1}`" :class="{ 'has-error': err(i, 'low_stock_threshold') }" @input="clear(i, 'low_stock_threshold')" />
              </td>
              <td class="c">
                <label class="switch" :title="r.is_active && activeCount < 2 ? 'The only active option cannot be switched off' : ''"><input v-model="r.is_active" type="checkbox" :disabled="r.is_active && activeCount < 2" :aria-label="`Option ${i + 1} active`" @change="emit('active', r)" /><span /></label>
              </td>
              <td class="nowrap">
                <button class="btn btn-soft btn-sm btn-icon" type="button" :title="moreFor.has(i) ? 'Hide weight and pictures' : 'Weight and pictures of this option'" :aria-label="`More about option ${i + 1}`" :aria-expanded="moreFor.has(i)" @click="toggleMore(i)"><ChevronDown v-if="moreFor.has(i)" :size="14" /><ChevronRight v-else :size="14" /></button>
                <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :disabled="rows.length < 2" :title="rows.length < 2 ? 'A product needs at least one option' : 'Remove option'" :aria-label="`Remove option ${i + 1}`" @click="emit('remove', i)"><Trash2 :size="14" /></button>
              </td>
            </tr>
            <tr v-if="moreFor.has(i)" class="opt-more">
              <td />
              <td colspan="8">
                <div class="opt-more__grid">
                  <div class="field" style="max-width: 180px">
                    <label :for="`opt-w-${i}`">Weight (grams)</label>
                    <input :id="`opt-w-${i}`" v-model="r.weight_grams" class="input input--sm" type="number" min="0" :class="{ 'has-error': err(i, 'weight_grams') }" />
                    <p v-if="err(i, 'weight_grams')" class="err">{{ err(i, 'weight_grams') }}</p>
                  </div>
                  <div class="field">
                    <label>Pictures of this option</label>
                    <ProductImages
                      :model-value="images" :pending="r.pending" :product-id="productId" :variant-id="r.id" :immediate="immediate(r)" compact
                      @update:model-value="emit('images', $event)" @update:pending="r.pending = $event"
                    />
                    <p class="hint">Shown when the customer looks at this option. If none, the general product pictures are used.</p>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
    <div class="opt__foot">
      <button class="btn btn-outline btn-sm" type="button" data-testid="add-option" @click="emit('add')"><Plus :size="14" /> Add option</button>
      <span class="muted">Customers add an option to the cart; the cheapest active option gives the starting price.</span>
    </div>
  </div>
</template>

<style scoped>
.opt__wrap { overflow-x: auto; padding-bottom: 6px; }
.opt-table { min-width: 780px; }
.opt-table th { white-space: nowrap; }
.opt-table td { vertical-align: top; padding: 5px 6px; }
.opt-table td.c, .opt-table th.c { text-align: center; }
.opt-table tr.is-off td { background: var(--hover); }
.opt-table tr.is-bad td { background: var(--danger-soft); }
.input--sm { height: 30px; padding: 0 8px; min-width: 70px; }
.input--num { width: 90px; text-align: right; }
.opt-table .err { font-size: 11px; color: var(--danger); margin-top: 2px; max-width: 170px; }
.opt-table small { display: block; font-size: 11px; }
.req { color: var(--danger); }
.opt-more td { background: var(--surface-2); }
.opt-more__grid { display: grid; grid-template-columns: 180px 1fr; gap: 14px; align-items: start; }
@media (max-width: 760px) { .opt-more__grid { grid-template-columns: 1fr; } }
.opt__foot { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding-top: 6px; }
</style>

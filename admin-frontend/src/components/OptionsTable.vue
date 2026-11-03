<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ChevronDown, ChevronRight, Layers, Plus, Trash2 } from '@lucide/vue'
import ProductImages from '@/components/ProductImages.vue'
import { useCatalogStore } from '@/stores/catalog.js'

// The options (variants) of a product as one compact editable table: default, name, SKU, regular price, selling price, stock, low-stock alert,
// active. A row has a bulk price popover (a lower price each for a larger quantity, up to 5) and a "more" row with the weight and the option's own
// pictures. The rows are edited in place; the server's validation messages (`errors`, keyed like the API: variants.0.sku) show under the cell.
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
const tierErrors = (i) => Object.entries(props.errors).filter(([k]) => k.startsWith(`variants.${i}.price_tiers`)).flatMap(([, v]) => v)
const rowHasError = (i) => Object.keys(props.errors).some(k => k.startsWith(`variants.${i}.`))

const num = (v) => (v === '' || v === null || v === undefined ? NaN : Number(v))
const off = (r) => {
  const m = num(r.mrp), p = num(r.selling_price)
  if (!(m > 0) || Number.isNaN(p)) return ''
  const d = Math.round(((m - p) / m) * 10000) / 100
  return d > 0 ? `${d}% off` : d < 0 ? 'above MRP' : ''
}
const tierOff = (r, t) => {
  const p = num(r.selling_price), x = num(t.price)
  return p > 0 && !Number.isNaN(x) && x < p ? `${Math.round(((p - x) / p) * 10000) / 100}% off` : ''
}
const activeCount = computed(() => props.rows.filter(r => r.is_active).length)

// bulk price popover: one open at a time, closes on a click elsewhere
const popFor = ref(null)
const moreFor = ref(new Set())
const root = ref(null)
const closePop = (e) => { if (popFor.value !== null && !e.target.closest?.('.tier-pop-wrap')) popFor.value = null }
onMounted(() => document.addEventListener('click', closePop))
onBeforeUnmount(() => document.removeEventListener('click', closePop))

const addTier = (r) => { if (r.price_tiers.length < 5) r.price_tiers.push({ min_qty: '', price: '' }) }
const removeTier = (r, j) => r.price_tiers.splice(j, 1)
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
            <th>MRP ({{ symbol }}) <span class="req">*</span></th><th>Price ({{ symbol }}) <span class="req">*</span></th><th>Stock</th><th title="Alert when this many or fewer are left">Alert at</th><th>Bulk</th><th>Active</th><th />
          </tr>
        </thead>
        <tbody>
          <template v-for="(r, i) in rows" :key="r.id ?? `n${i}`">
            <tr :class="{ 'is-bad': rowHasError(i), 'is-off': !r.is_active }" :data-option="i">
              <td class="c"><input type="radio" name="default-option" :checked="r.is_default" :disabled="!r.is_active" :aria-label="`Make option ${i + 1} the default`" @change="emit('default', i)" /></td>
              <td>
                <input v-model="r.name" class="input input--sm" maxlength="255" :aria-label="`Option ${i + 1} name`" placeholder="e.g. Blue, 500 ml" :class="{ 'has-error': err(i, 'name') }" @input="clear(i, 'name')" />
                <p v-if="err(i, 'name')" class="err">{{ err(i, 'name') }}</p>
              </td>
              <td>
                <input v-model="r.sku" class="input input--sm" maxlength="80" :aria-label="`Option ${i + 1} SKU`" :class="{ 'has-error': err(i, 'sku') }" @input="clear(i, 'sku')" />
                <p v-if="err(i, 'sku')" class="err">{{ err(i, 'sku') }}</p>
              </td>
              <td>
                <input v-model="r.mrp" class="input input--sm input--num" type="number" min="0" step="0.01" :aria-label="`Option ${i + 1} regular price`" :class="{ 'has-error': err(i, 'mrp') }" @input="clear(i, 'mrp', 'selling_price')" />
                <p v-if="err(i, 'mrp')" class="err">{{ err(i, 'mrp') }}</p>
              </td>
              <td>
                <input v-model="r.selling_price" class="input input--sm input--num" type="number" min="0" step="0.01" :aria-label="`Option ${i + 1} price`" :class="{ 'has-error': err(i, 'selling_price') }" @input="clear(i, 'selling_price')" />
                <small v-if="off(r)" class="off-tag" data-testid="off-label">{{ off(r) }}</small>
                <p v-if="err(i, 'selling_price')" class="err">{{ err(i, 'selling_price') }}</p>
              </td>
              <td>
                <input v-model="r.stock" class="input input--sm input--num" type="number" min="0" step="1" :aria-label="`Stock of option ${i + 1}`" :class="{ 'has-error': err(i, 'stock') }" @input="clear(i, 'stock')" />
                <small v-if="r.reserved" class="muted" :title="`${r.reserved} reserved by open orders`">{{ r.reserved }} reserved</small>
                <p v-if="err(i, 'stock')" class="err">{{ err(i, 'stock') }}</p>
              </td>
              <td>
                <input v-model="r.low_stock_threshold" class="input input--sm input--num" type="number" min="0" step="1" :aria-label="`Low-stock alert of option ${i + 1}`" :class="{ 'has-error': err(i, 'low_stock_threshold') }" @input="clear(i, 'low_stock_threshold')" />
              </td>
              <td class="tier-pop-wrap">
                <button class="btn btn-sm" :class="r.price_tiers.length ? 'btn-soft' : 'btn-outline'" type="button" :aria-expanded="popFor === i" :aria-label="`Bulk prices of option ${i + 1}`" data-testid="bulk-button" @click.stop="popFor = popFor === i ? null : i">
                  <Layers :size="13" /> {{ r.price_tiers.length || 'Add' }}
                </button>
                <div v-if="popFor === i" class="tier-pop" role="dialog" :aria-label="`Bulk prices of option ${i + 1}`" @click.stop>
                  <strong>Bulk prices</strong>
                  <p class="muted" style="margin: 0 0 6px">A lower price each when the customer buys this many or more (max 5).</p>
                  <p v-if="err(i, 'price_tiers')" class="err">{{ err(i, 'price_tiers') }}</p>
                  <div v-for="(t, j) in r.price_tiers" :key="j" class="tier-pop__row">
                    <div>
                      <input v-model="t.min_qty" class="input input--sm" type="number" min="2" placeholder="Qty (2+)" :aria-label="`Option ${i + 1} bulk ${j + 1} quantity`" :class="{ 'has-error': err(i, `price_tiers.${j}.min_qty`) }" @input="clear(i, `price_tiers.${j}.min_qty`)" />
                      <p v-if="err(i, `price_tiers.${j}.min_qty`)" class="err">{{ err(i, `price_tiers.${j}.min_qty`) }}</p>
                    </div>
                    <div>
                      <input v-model="t.price" class="input input--sm" type="number" min="0" step="0.01" :placeholder="`Each (${symbol})`" :aria-label="`Option ${i + 1} bulk ${j + 1} price`" :class="{ 'has-error': err(i, `price_tiers.${j}.price`) }" @input="clear(i, `price_tiers.${j}.price`)" />
                      <p v-if="err(i, `price_tiers.${j}.price`)" class="err">{{ err(i, `price_tiers.${j}.price`) }}</p>
                    </div>
                    <span class="muted nowrap">{{ tierOff(r, t) }}</span>
                    <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Remove bulk price ${j + 1}`" @click="removeTier(r, j)"><Trash2 :size="13" /></button>
                  </div>
                  <button v-if="r.price_tiers.length < 5" class="btn btn-outline btn-sm" type="button" data-testid="add-tier" @click="addTier(r)"><Plus :size="13" /> Add bulk price</button>
                  <button class="btn btn-primary btn-sm" type="button" style="margin-left: 6px" @click="popFor = null">Done</button>
                </div>
                <small v-if="tierErrors(i).length" class="err">!</small>
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
              <td colspan="9">
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
.opt-table { min-width: 860px; }
.opt-table th { white-space: nowrap; }
.opt-table td { vertical-align: top; padding: 5px 6px; }
.opt-table td.c, .opt-table th.c { text-align: center; }
.opt-table tr.is-off td { background: #fafafa; }
.opt-table tr.is-bad td { background: #fff7f8; }
.input--sm { height: 30px; padding: 0 8px; min-width: 70px; }
.input--num { width: 90px; text-align: right; }
.opt-table .err { font-size: 11px; color: var(--danger); margin-top: 2px; max-width: 170px; }
.opt-table small { display: block; font-size: 11px; }
.req { color: var(--danger); }
.tier-pop-wrap { position: relative; }
.tier-pop { position: absolute; z-index: 30; top: calc(100% - 4px); left: -40px; width: 330px; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--shadow-md); padding: 10px 12px; display: grid; gap: 6px; text-align: left; }
.tier-pop__row { display: grid; grid-template-columns: 1fr 1fr auto auto; gap: 6px; align-items: start; }
.opt-more td { background: #f8fafc; }
.opt-more__grid { display: grid; grid-template-columns: 180px 1fr; gap: 14px; align-items: start; }
@media (max-width: 760px) { .opt-more__grid { grid-template-columns: 1fr; } }
.opt__foot { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding-top: 6px; }
</style>

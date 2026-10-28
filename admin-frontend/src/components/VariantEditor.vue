<script setup>
import { computed } from 'vue'
import { Plus, Trash2 } from '@lucide/vue'
import ProductImages from '@/components/ProductImages.vue'
import { useCatalogStore } from '@/stores/catalog.js'

// One OPTION (variant) of a product: its own name, SKU, MRP, price, live discount, points, expiry, weight, bulk prices
// ("Buy 5+ for Rs 152 each") and its own pictures. The row object is edited in place; errors come from the parent
// (`errors`, keyed like the API).
const props = defineProps({
  row: { type: Object, required: true },
  prefix: { type: String, required: true },        // e.g. "variants.0"
  label: { type: String, default: 'Option' },      // e.g. "Option 2" (for screen readers)
  errors: { type: Object, default: () => ({}) },
  productId: { type: Number, default: null },
  images: { type: Array, default: () => [] },       // the product's full picture list from the server
  channels: { type: Array, default: () => [] },     // the sales channels this product is sold on: [{ code, name }]
})
const emit = defineEmits(['clear', 'images'])
const catalog = useCatalogStore()
const idp = props.prefix.replaceAll('.', '-')

const err = (key) => props.errors[`${props.prefix}.${key}`]?.[0] || ''
const clear = (...keys) => emit('clear', keys.map(k => `${props.prefix}.${k}`))
const symbol = computed(() => catalog.currency.symbol)

const num = (v) => (v === '' || v === null || v === undefined ? NaN : Number(v))
const off = computed(() => {
  const m = num(props.row.mrp), p = num(props.row.selling_price)
  if (!(m > 0) || Number.isNaN(p)) return { text: '-% off', cls: 'is-none' }
  const d = Math.round(((m - p) / m) * 10000) / 100
  if (d < 0) return { text: 'Price above MRP', cls: 'is-bad' }
  return { text: `${d}% off`, cls: d === 0 ? 'is-none' : '' }
})
const tierOff = (t) => {
  const p = num(props.row.selling_price), x = num(t.price)
  return p > 0 && !Number.isNaN(x) && x < p ? `${Math.round(((p - x) / p) * 10000) / 100}% extra off` : ''
}

const addTier = () => props.row.price_tiers.push({ min_qty: '', price: '' })
const removeTier = (j) => props.row.price_tiers.splice(j, 1)

// pictures of a saved option are uploaded at once; those of a new option wait for "Save"
const immediate = computed(() => props.productId !== null && props.row.id !== null)
</script>

<template>
  <div class="variant-editor">
    <div class="form-grid">
      <div class="field full">
        <label :for="`${idp}-name`">Option name <span class="req">*</span></label>
        <input :id="`${idp}-name`" v-model="row.name" class="input" maxlength="255" :aria-label="`${label} name`" placeholder="Shown on the product page, e.g. Maarc Pure Eugenol (15ml)" :class="{ 'has-error': err('name') }" @input="clear('name')" />
        <p v-if="err('name')" class="err">{{ err('name') }}</p>
      </div>
      <div class="field">
        <label :for="`${idp}-sku`">SKU <span class="req">*</span></label>
        <input :id="`${idp}-sku`" v-model="row.sku" class="input" maxlength="80" :aria-label="`${label} SKU`" :class="{ 'has-error': err('sku') }" @input="clear('sku')" />
        <p v-if="err('sku')" class="err">{{ err('sku') }}</p>
      </div>
      <div class="field">
        <label :for="`${idp}-weight`">Weight (grams)</label>
        <input :id="`${idp}-weight`" v-model="row.weight_grams" class="input" type="number" min="0" :aria-label="`${label} weight`" :class="{ 'has-error': err('weight_grams') }" />
        <p v-if="err('weight_grams')" class="err">{{ err('weight_grams') }}</p>
      </div>
    </div>

    <div class="form-grid-4">
      <div class="field">
        <label :for="`${idp}-mrp`">MRP ({{ symbol }}) <span class="req">*</span></label>
        <input :id="`${idp}-mrp`" v-model="row.mrp" class="input" type="number" min="0" step="0.01" :aria-label="`${label} MRP`" :class="{ 'has-error': err('mrp') }" @input="clear('mrp', 'selling_price')" />
        <p v-if="err('mrp')" class="err">{{ err('mrp') }}</p>
      </div>
      <div class="field">
        <label :for="`${idp}-price`">Selling price ({{ symbol }}) <span class="req">*</span></label>
        <input :id="`${idp}-price`" v-model="row.selling_price" class="input" type="number" min="0" step="0.01" :aria-label="`${label} price`" :class="{ 'has-error': err('selling_price') }" @input="clear('selling_price')" />
        <p v-if="err('selling_price')" class="err">{{ err('selling_price') }}</p>
      </div>
      <div class="field">
        <label>Discount</label>
        <div class="price-box" style="height: 34px"><span class="off-badge" :class="off.cls" data-testid="off-label">{{ off.text }}</span></div>
      </div>
      <div class="field">
        <label :for="`${idp}-points`">Reward points (coins)</label>
        <input :id="`${idp}-points`" v-model="row.reward_points" class="input" type="number" min="0" :aria-label="`${label} points`" :class="{ 'has-error': err('reward_points') }" />
        <p v-if="err('reward_points')" class="err">{{ err('reward_points') }}</p>
      </div>
      <div class="field">
        <label :for="`${idp}-expiry`">Expiry date</label>
        <input :id="`${idp}-expiry`" v-model="row.expiry_date" class="input" type="date" :aria-label="`${label} expiry`" :class="{ 'has-error': err('expiry_date') }" />
        <p v-if="err('expiry_date')" class="err">{{ err('expiry_date') }}</p>
      </div>
    </div>

    <div v-if="channels.length" class="stock-box">
      <div class="tier-box__head">
        <strong>Stock of this option</strong>
        <span class="muted">Each option has its own quantity in every channel (for example red 3, blue 10).</span>
      </div>
      <div class="stock-inputs">
        <div v-for="c in channels" :key="c.code" class="field">
          <label :for="`${idp}-stock-${c.code}`">{{ c.name }} quantity</label>
          <input :id="`${idp}-stock-${c.code}`" v-model="row.stock[c.code]" class="input" type="number" min="0" :aria-label="`${c.name} quantity of ${label.toLowerCase()}`" :class="{ 'has-error': err(`stock.${c.code}`) }" @input="clear(`stock.${c.code}`)" />
          <p v-if="err(`stock.${c.code}`)" class="err">{{ err(`stock.${c.code}`) }}</p>
        </div>
      </div>
    </div>

    <div class="tier-box">
      <div class="tier-box__head">
        <strong>Bulk prices</strong>
        <span class="muted">"Buy 5+ for {{ symbol }} 152 each" - cheaper for a larger quantity (optional, max 5)</span>
      </div>
      <p v-if="err('price_tiers')" class="err">{{ err('price_tiers') }}</p>
      <div v-for="(t, j) in row.price_tiers" :key="j" class="tier-row">
        <div>
          <input v-model="t.min_qty" class="input" type="number" min="2" :aria-label="`${label} bulk ${j + 1} quantity`" placeholder="Quantity (2 or more)" :class="{ 'has-error': err(`price_tiers.${j}.min_qty`) }" @input="clear(`price_tiers.${j}.min_qty`)" />
          <p v-if="err(`price_tiers.${j}.min_qty`)" class="err">{{ err(`price_tiers.${j}.min_qty`) }}</p>
        </div>
        <div>
          <input v-model="t.price" class="input" type="number" min="0" step="0.01" :aria-label="`${label} bulk ${j + 1} price`" :placeholder="`Price each (${symbol})`" :class="{ 'has-error': err(`price_tiers.${j}.price`) }" @input="clear(`price_tiers.${j}.price`)" />
          <p v-if="err(`price_tiers.${j}.price`)" class="err">{{ err(`price_tiers.${j}.price`) }}</p>
        </div>
        <span class="muted nowrap">{{ tierOff(t) }}</span>
        <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Remove bulk price ${j + 1}`" @click="removeTier(j)"><Trash2 :size="14" /></button>
      </div>
      <button v-if="row.price_tiers.length < 5" class="btn btn-outline btn-sm" type="button" @click="addTier"><Plus :size="15" /> Add bulk price</button>
    </div>

    <div class="field">
      <label>Pictures of this option</label>
      <ProductImages
        :model-value="images" :pending="row.pending" :product-id="productId" :variant-id="row.id" :immediate="immediate" compact
        @update:model-value="emit('images', $event)" @update:pending="row.pending = $event"
      />
      <p class="hint">Shown when the customer looks at this option (for example 2-3 pictures of this exact model). If none, the general product pictures are used.</p>
    </div>
  </div>
</template>

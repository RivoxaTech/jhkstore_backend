<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import { currencySymbol } from '@/utils/format.js'
import AppModal from '@/components/AppModal.vue'

// Create / edit a delivery zone. The values the zone matches are typed one per line (commas work too); a postcode entry may end with * (54* = every 54xxx).
const props = defineProps({ item: { type: Object, default: null } })   // null = create
const emit = defineEmits(['close', 'saved'])

const MATCH_TYPES = [['city', 'City'], ['area', 'Area'], ['postcode', 'Postcode']]
const HINTS = {
  city: 'One city per line, e.g. Lahore. Matches the city of the delivery address (any capitals).',
  area: 'One area per line, e.g. Gulberg. Matches the area of the delivery address (any capitals).',
  postcode: 'One postcode per line. End an entry with * to match a prefix, e.g. 54* matches 54000, 54810 and so on.',
}

const isEdit = computed(() => Boolean(props.item))
const num = (v) => (v === null || v === undefined ? '' : String(v))
const form = reactive({
  name: props.item?.name ?? '',
  match_type: props.item?.match_type ?? 'city',
  values: (props.item?.match_values ?? []).join('\n'),
  fee: num(props.item?.fee ?? 0),
  free_above: num(props.item?.free_above),
  min_order: num(props.item?.min_order),
  eta_text: props.item?.eta_text ?? '',
  is_active: props.item ? props.item.is_active : true,
})
const errors = ref({})
const message = ref('')
const busy = ref(false)
const first = ref(null)
onMounted(() => first.value?.focus())

const err = (k) => errors.value[k]?.[0] || ''
const valuesErr = computed(() => err('match_values') || Object.entries(errors.value).find(([k]) => k.startsWith('match_values.'))?.[1]?.[0] || '')
const clear = (...keys) => { const next = { ...errors.value }; keys.forEach(k => delete next[k]); errors.value = next }
const parsedValues = () => [...new Set(form.values.split(/[\n,]/).map(v => v.trim()).filter(Boolean))]
const optional = (v) => (String(v).trim() === '' ? null : Number(v))

async function save() {
  errors.value = {}
  message.value = ''
  const body = {
    name: form.name.trim(),
    match_type: form.match_type,
    match_values: parsedValues(),
    fee: String(form.fee).trim() === '' ? null : Number(form.fee),
    free_above: optional(form.free_above),
    min_order: optional(form.min_order),
    eta_text: form.eta_text.trim() || null,
    is_active: form.is_active,
  }
  busy.value = true
  try {
    const res = isEdit.value ? await api.put(`admin/delivery-zones/${props.item.id}`, body) : await api.post('admin/delivery-zones', body)
    emit('saved', res.data, isEdit.value)
  } catch (e) {
    if (e.status === 422 && Object.keys(e.errors).length) errors.value = e.errors
    else message.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppModal :title="isEdit ? 'Edit delivery zone' : 'Add delivery zone'" persistent @close="emit('close')">
    <form id="zone-form" novalidate @submit.prevent="save">
      <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
      <div class="form-grid">
        <div class="field full">
          <label for="zname">Zone name <span class="req">*</span></label>
          <input id="zname" ref="first" v-model="form.name" class="input" maxlength="120" placeholder="e.g. Lahore city" :class="{ 'has-error': err('name') }" @input="clear('name')" />
          <p v-if="err('name')" class="err">{{ err('name') }}</p>
        </div>
        <div class="field">
          <label for="ztype">The zone matches the <span class="req">*</span></label>
          <select id="ztype" v-model="form.match_type" class="select" :class="{ 'has-error': err('match_type') }" @change="clear('match_type')">
            <option v-for="[v, label] in MATCH_TYPES" :key="v" :value="v">{{ label }}</option>
          </select>
          <p v-if="err('match_type')" class="err">{{ err('match_type') }}</p>
        </div>
        <div class="field">
          <label for="zfee">Delivery fee <span class="req">*</span></label>
          <div class="input-affix"><span>{{ currencySymbol() }}</span><input id="zfee" v-model="form.fee" class="input" type="number" min="0" step="0.01" :class="{ 'has-error': err('fee') }" @input="clear('fee')" /></div>
          <p v-if="err('fee')" class="err">{{ err('fee') }}</p>
          <p v-else class="hint">0 = free delivery in this zone.</p>
        </div>
        <div class="field full">
          <label for="zvalues">Values <span class="req">*</span></label>
          <textarea id="zvalues" v-model="form.values" class="textarea" rows="4" style="height: auto; padding: 8px 11px" :placeholder="form.match_type === 'postcode' ? '54000\n54*' : 'One per line'" :class="{ 'has-error': valuesErr }" @input="clear('match_values')" />
          <p v-if="valuesErr" class="err">{{ valuesErr }}</p>
          <p v-else class="hint">{{ HINTS[form.match_type] }}</p>
        </div>
        <div class="field">
          <label for="zfree">Free delivery above</label>
          <div class="input-affix"><span>{{ currencySymbol() }}</span><input id="zfree" v-model="form.free_above" class="input" type="number" min="0" step="0.01" placeholder="optional" :class="{ 'has-error': err('free_above') }" @input="clear('free_above')" /></div>
          <p v-if="err('free_above')" class="err">{{ err('free_above') }}</p>
        </div>
        <div class="field">
          <label for="zmin">Minimum order</label>
          <div class="input-affix"><span>{{ currencySymbol() }}</span><input id="zmin" v-model="form.min_order" class="input" type="number" min="0" step="0.01" placeholder="optional" :class="{ 'has-error': err('min_order') }" @input="clear('min_order')" /></div>
          <p v-if="err('min_order')" class="err">{{ err('min_order') }}</p>
          <p v-else class="hint">Replaces the general minimum order for this zone.</p>
        </div>
        <div class="field full">
          <label for="zeta">Delivery estimate</label>
          <input id="zeta" v-model="form.eta_text" class="input" maxlength="120" placeholder="e.g. 1 to 2 days (optional)" :class="{ 'has-error': err('eta_text') }" @input="clear('eta_text')" />
          <p v-if="err('eta_text')" class="err">{{ err('eta_text') }}</p>
        </div>
        <div class="field full">
          <div style="display: flex; align-items: center; gap: 10px">
            <label class="switch" aria-label="Zone active"><input v-model="form.is_active" type="checkbox" /><span /></label>
            <div>
              <strong style="font-weight: 500">Active</strong>
              <p class="hint" style="margin: 0">An inactive zone is skipped when an address is matched.</p>
            </div>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="zone-form" :disabled="busy"><span v-if="busy" class="spinner" /> {{ isEdit ? 'Save zone' : 'Add zone' }}</button>
    </template>
  </AppModal>
</template>

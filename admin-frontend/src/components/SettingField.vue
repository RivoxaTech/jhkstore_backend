<script setup>
import { computed } from 'vue'
import { currencySymbol } from '@/utils/format.js'
import AccountsEditor from '@/components/AccountsEditor.vue'
import LinksEditor from '@/components/LinksEditor.vue'
import UploadField from '@/components/UploadField.vue'

// One setting of the Settings page, drawn by its type: string, text, int / money / percent, bool (a switch row), color, choice (select), links, accounts, file.
// `form` is the reactive object of the values being edited (the page saves only what changed). Advanced (technical) fields carry a small "Developer" tag.
const props = defineProps({
  field: { type: Object, required: true },
  form: { type: Object, required: true },
  errors: { type: Object, default: () => ({}) },        // server validation errors, keyed by setting key (or "key.0.sub" for list rows)
  clientError: { type: String, default: '' },           // an error found in the browser before saving
  platforms: { type: Array, default: () => [] },
  files: { type: Object, default: () => ({}) },
  siteName: { type: String, default: '' },
})
const emit = defineEmits(['picture'])

const f = computed(() => props.field)
const id = computed(() => `s-${f.value.key}`)
const NUMBER_TYPES = ['int', 'money', 'percent']
const isNumber = computed(() => NUMBER_TYPES.includes(f.value.type))
const fe = computed(() => props.errors[f.value.key]?.[0] || props.clientError)
const listErrors = computed(() => Object.fromEntries(Object.entries(props.errors).filter(([k]) => k === f.value.key || k.startsWith(`${f.value.key}.`))))
const affix = computed(() => (f.value.type === 'money' ? 'left' : f.value.type === 'percent' ? 'right' : ''))
const isDev = computed(() => f.value.access === 'advanced')

// colour: a colour picker and a hex box that stay in sync
const HEX = /^#[0-9a-f]{6}$/i
const swatch = computed(() => (HEX.test(String(props.form[f.value.key] ?? '')) ? String(props.form[f.value.key]).toLowerCase() : '#000000'))
const setColour = (value) => { props.form[f.value.key] = String(value).toUpperCase() }

// choice: readable names for the values of a few selects
const OPTION_LABELS = {
  delivery_method: { flat: 'Flat fee', free: 'Always free', free_above: 'Free above an amount', zones: 'By delivery zone', owner_confirms: 'The owner confirms the fee' },
  delivery_unmatched: { block: 'Refuse the order', owner_confirms: 'The owner confirms the fee', flat: 'Use the flat delivery charge' },
  theme_radius: { '999px': '999px (pill)', '0px': '0px (square)' },
}
const optionLabel = (value) => OPTION_LABELS[f.value.key]?.[value] || (f.value.key.startsWith('theme_font') ? value : value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' '))

const METHOD_HELP = {
  flat: 'Every order pays the delivery charge.',
  free: 'Delivery is always free: no fee is needed.',
  free_above: 'Orders pay the delivery charge unless the cart reaches the "free delivery from" amount.',
  zones: 'The fee comes from the delivery zone that matches the address (manage the zones below).',
  owner_confirms: 'Orders are placed without a delivery fee; you set the fee on each order afterwards.',
}

// bank transfer needs an account customers can pay into
const bankWarning = computed(() => {
  if (f.value.key !== 'pay_bank_enabled' || !props.form.pay_bank_enabled) return false
  return !(props.form.pay_bank_accounts || []).some((a) => a.active && String(a.bank || '').trim() && (String(a.number || '').trim() || String(a.iban || '').trim()))
})
</script>

<template>
  <div class="sf" :data-field="f.key" :class="{ 'sf--switch': f.type === 'bool', 'sf--dev': isDev }">
    <UploadField v-if="f.type === 'file'" :label="f.label" :url="files[f.key] || ''" :name="siteName" :help="f.help || ''" :upload="f.upload" @updated="(p, m) => emit('picture', p, m)" />

    <template v-else-if="f.type === 'bool'">
      <div class="sf__text">
        <label :for="id" class="sf__label">{{ f.label }}<span v-if="isDev" class="dev-tag" title="Technical setting">Developer</span></label>
        <p v-if="f.help" class="hint">{{ f.help }}</p>
        <p v-if="bankWarning" class="hint" data-bank-warning style="color: var(--warning)">Add at least one active bank account below, or customers will have nowhere to pay.</p>
        <p v-if="fe" class="err">{{ fe }}</p>
      </div>
      <label class="switch" :aria-label="f.label"><input :id="id" v-model="form[f.key]" type="checkbox" /><span /></label>
    </template>

    <template v-else>
      <label :for="id" class="sf__label">{{ f.label }}<span v-if="isDev" class="dev-tag" title="Technical setting: only the developer, or an admin with the Advanced settings permission, sees it">Developer</span></label>
      <LinksEditor v-if="f.type === 'links'" v-model="form[f.key]" :platforms="platforms" :errors="listErrors" :field="f.key" />
      <AccountsEditor v-else-if="f.type === 'accounts'" v-model="form[f.key]" :errors="listErrors" :field="f.key" />
      <textarea v-else-if="f.type === 'text'" :id="id" v-model="form[f.key]" class="textarea" rows="2" :class="{ 'has-error': fe }" />
      <div v-else-if="f.type === 'color'" class="color-field">
        <input class="color-swatch" type="color" :value="swatch" :aria-label="`${f.label} picker`" @input="setColour($event.target.value)" />
        <input :id="id" v-model="form[f.key]" class="input" type="text" maxlength="7" spellcheck="false" placeholder="#RRGGBB" :class="{ 'has-error': fe }" />
      </div>
      <select v-else-if="f.type === 'choice'" :id="id" v-model="form[f.key]" class="select" :class="{ 'has-error': fe }">
        <option v-for="o in f.options" :key="o" :value="o">{{ optionLabel(o) }}</option>
      </select>
      <div v-else-if="isNumber" class="input-affix" :class="{ 'input-affix--right': affix === 'right' }">
        <span v-if="affix === 'left'">{{ currencySymbol() }}</span>
        <input :id="id" v-model.number="form[f.key]" class="input" type="number" :min="f.min ?? undefined" :max="f.max ?? undefined" :step="f.type === 'int' ? 1 : 0.01" :class="{ 'has-error': fe }" />
        <span v-if="affix === 'right'">%</span>
      </div>
      <input v-else :id="id" v-model="form[f.key]" class="input" type="text" :class="{ 'has-error': fe }" />
      <p v-if="f.help" class="hint">{{ f.help }}</p>
      <p v-if="f.key === 'delivery_method' && METHOD_HELP[form.delivery_method]" class="hint" data-method-help style="color: var(--primary-dark)">{{ METHOD_HELP[form.delivery_method] }}</p>
      <p v-if="fe" class="err">{{ fe }}</p>
    </template>
  </div>
</template>

<style scoped>
.sf__label { display: flex; align-items: center; gap: 6px; font-weight: 500; font-size: 12.5px; margin-bottom: 3px; }
.sf--switch { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 6px 0; }
.sf--switch .sf__label { margin: 0; }
.sf--switch .hint { margin: 1px 0 0; }
.sf--switch .switch { margin-top: 1px; }
.sf .hint { font-size: 11.5px; color: var(--text-3); margin-top: 3px; line-height: 1.35; }
.sf .err { font-size: 11.5px; color: var(--danger); margin-top: 3px; }
.dev-tag { font-size: 9.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #6d3fd1; background: #efe9fd; border-radius: 4px; padding: 0 5px; line-height: 15px; flex: none; }
.color-field { display: flex; align-items: center; gap: 8px; }
.color-swatch { width: 40px; height: 34px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; background: #fff; cursor: pointer; flex: none; }
.color-field .input { max-width: 130px; font-variant-numeric: tabular-nums; text-transform: uppercase; }
</style>

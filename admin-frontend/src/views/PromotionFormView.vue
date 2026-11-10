<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { currencySymbol, timezone, toInputValue } from '@/utils/format.js'
import TargetPicker from '@/components/TargetPicker.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Create / edit a discount (automatic, no code) or a coupon (code) on a page of its own (`route.meta.kind` = discount | coupon). Same form, a few extra
// fields for coupons. On edit the detail is read first, because only the detail carries the chosen products / categories with their names.
// Server messages show under their field.
const route = useRoute()
const router = useRouter()
const ui = useUiStore()

const kind = computed(() => route.meta.kind)
const isCoupon = computed(() => kind.value === 'coupon')
const isEdit = computed(() => route.params.id !== undefined)
const endpoint = computed(() => (isCoupon.value ? 'admin/coupons' : 'admin/discounts'))
const noun = computed(() => (isCoupon.value ? 'coupon' : 'discount'))
const listRoute = computed(() => ({ name: isCoupon.value ? 'coupons' : 'discounts' }))

const SCOPES = [['store', 'Whole store'], ['products', 'Chosen products'], ['categories', 'Chosen categories'], ['sub_categories', 'Chosen sub-categories']]
const TARGET_TYPE = { products: 'product', categories: 'category', sub_categories: 'sub_category' }
const TARGET_LABEL = { products: 'Products', categories: 'Categories', sub_categories: 'Sub-categories' }

const local = toInputValue
const text = (v) => (v === null || v === undefined ? '' : String(v))

const form = reactive({
  code: '', name: '', description: '', type: 'percent', value: '', free_delivery: false, scope: 'store', targets: [],
  starts_at: '', ends_at: '', is_active: true, priority: '0',
  min_order: '', max_discount: '', usage_limit: '', per_customer_limit: '', first_order_only: false,
})
const errors = ref({})
const message = ref('')
const busy = ref(false)
const loading = ref(isEdit.value)
const first = ref(null)

function fill(m) {
  Object.assign(form, {
    code: m.code ?? '', name: m.name ?? '', description: m.description ?? '', type: m.type ?? 'percent', value: text(m.value), free_delivery: Boolean(m.free_delivery),
    scope: m.scope ?? 'store', targets: (m.targets ?? []).map(t => ({ id: t.id, name: t.name })),
    starts_at: local(m.starts_at), ends_at: local(m.ends_at), is_active: m.is_active ?? true, priority: text(m.priority ?? 0),
    min_order: text(m.min_order), max_discount: text(m.max_discount), usage_limit: text(m.usage_limit), per_customer_limit: text(m.per_customer_limit),
    first_order_only: Boolean(m.first_order_only),
  })
}

onMounted(async () => {
  if (!isEdit.value) { first.value?.focus(); return }
  try {
    fill((await api.get(`${endpoint.value}/${route.params.id}`)).data)
  } catch (e) {
    message.value = e.message
  } finally {
    loading.value = false
  }
})

const err = (k) => errors.value[k]?.[0] || ''
const nestedErr = (k) => err(k) || Object.entries(errors.value).find(([key]) => key.startsWith(`${k}.`))?.[1]?.[0] || ''
const clear = (...keys) => { const next = { ...errors.value }; keys.forEach(k => delete next[k]); errors.value = next }
const num = (v) => (String(v).trim() === '' ? null : Number(v))
const showValue = computed(() => !(isCoupon.value && form.free_delivery))
const percent = computed(() => form.type === 'percent')

function upperCode() { form.code = form.code.toUpperCase().replace(/\s+/g, ''); clear('code') }

async function save() {
  errors.value = {}
  message.value = ''
  const body = {
    name: form.name.trim(),
    type: showValue.value ? form.type : null,
    value: showValue.value ? num(form.value) : null,
    scope: form.scope,
    target_ids: form.scope === 'store' ? [] : form.targets.map(t => t.id),
    starts_at: form.starts_at || null,
    ends_at: form.ends_at || null,
    is_active: form.is_active,
  }
  if (isCoupon.value) {
    Object.assign(body, {
      code: form.code.trim().toUpperCase(), description: form.description.trim() || null, free_delivery: form.free_delivery,
      min_order: num(form.min_order), max_discount: num(form.max_discount), usage_limit: num(form.usage_limit), per_customer_limit: num(form.per_customer_limit),
      first_order_only: form.first_order_only,
    })
  } else {
    body.priority = num(form.priority) ?? 0
  }
  busy.value = true
  try {
    isEdit.value ? await api.put(`${endpoint.value}/${route.params.id}`, body) : await api.post(endpoint.value, body)
    ui.toast(`${isCoupon.value ? 'Coupon' : 'Discount'} ${isEdit.value ? 'updated' : 'added'}.`)
    router.push(listRoute.value)
  } catch (e) {
    if (e.status === 422 && Object.keys(e.errors).length) { errors.value = e.errors; message.value = 'Please check the highlighted fields.' }
    else message.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ isEdit ? 'Edit' : 'Add' }} {{ noun }}</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <RouterLink :to="listRoute">{{ isCoupon ? 'Coupons' : 'Discounts' }}</RouterLink> › <span>{{ isEdit ? 'Edit' : 'New' }}</span></div>
    </div>
  </div>

  <div v-if="loading" class="card"><TableSkeleton :rows="6" /></div>
  <form v-else id="promo-form" class="pf" novalidate @submit.prevent="save">
    <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>

    <div style="display: grid; gap: 12px">
      <section class="card">
        <div class="card__head"><h2>Details</h2></div>
        <div class="card__body form-grid">
          <template v-if="isCoupon">
            <div class="field">
              <label for="pcode">Code <span class="req">*</span></label>
              <input id="pcode" ref="first" v-model="form.code" class="input" maxlength="32" placeholder="e.g. WELCOME10" autocomplete="off" spellcheck="false" style="text-transform: uppercase" :class="{ 'has-error': err('code') }" @input="upperCode" />
              <p v-if="err('code')" class="err">{{ err('code') }}</p>
              <p v-else class="hint">3 to 32 letters, digits, dashes or underscores. Customers type it at checkout.</p>
            </div>
            <div class="field">
              <label for="pname">Name <span class="req">*</span></label>
              <input id="pname" v-model="form.name" class="input" maxlength="120" placeholder="For you only, e.g. Welcome offer" :class="{ 'has-error': err('name') }" @input="clear('name')" />
              <p v-if="err('name')" class="err">{{ err('name') }}</p>
            </div>
            <div class="field full">
              <label for="pdesc">Description <span class="muted">(optional)</span></label>
              <input id="pdesc" v-model="form.description" class="input" maxlength="255" :class="{ 'has-error': err('description') }" @input="clear('description')" />
              <p v-if="err('description')" class="err">{{ err('description') }}</p>
            </div>
          </template>
          <template v-else>
            <div class="field">
              <label for="pname">Name <span class="req">*</span></label>
              <input id="pname" ref="first" v-model="form.name" class="input" maxlength="120" placeholder="e.g. Summer sale" :class="{ 'has-error': err('name') }" @input="clear('name')" />
              <p v-if="err('name')" class="err">{{ err('name') }}</p>
            </div>
            <div class="field">
              <label for="pprio">Priority</label>
              <input id="pprio" v-model="form.priority" class="input" type="number" step="1" min="-1000" max="1000" :class="{ 'has-error': err('priority') }" @input="clear('priority')" />
              <p v-if="err('priority')" class="err">{{ err('priority') }}</p>
              <p v-else class="hint">When two discounts save the same amount on a line, the higher priority wins.</p>
            </div>
          </template>
          <div class="field full">
            <div style="display: flex; align-items: center; gap: 10px">
              <label class="switch" aria-label="Active"><input v-model="form.is_active" type="checkbox" /><span /></label>
              <div>
                <strong style="font-weight: 500">Active</strong>
                <p class="hint" style="margin: 0">When off, the {{ noun }} is never applied, whatever its dates.</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card__head"><h2>What it gives</h2></div>
        <div class="card__body form-grid">
          <div v-if="isCoupon" class="field full">
            <div style="display: flex; align-items: center; gap: 10px">
              <label class="switch" aria-label="Free delivery"><input v-model="form.free_delivery" type="checkbox" data-free-delivery @change="clear('type', 'value')" /><span /></label>
              <div>
                <strong style="font-weight: 500">Free delivery coupon</strong>
                <p class="hint" style="margin: 0">Takes the delivery fee off the order instead of discounting the items.</p>
              </div>
            </div>
          </div>
          <template v-if="showValue">
            <div class="field">
              <label for="ptype">Type <span class="req">*</span></label>
              <select id="ptype" v-model="form.type" class="select" :class="{ 'has-error': err('type') }" @change="clear('type', 'value')">
                <option value="percent">Percentage (%)</option>
                <option value="fixed">Fixed amount</option>
              </select>
              <p v-if="err('type')" class="err">{{ err('type') }}</p>
            </div>
            <div class="field">
              <label for="pvalue">Value <span class="req">*</span></label>
              <div class="input-affix" :class="{ 'input-affix--right': percent }">
                <span v-if="!percent">{{ currencySymbol() }}</span>
                <input id="pvalue" v-model="form.value" class="input" type="number" min="0" :max="percent ? 100 : undefined" step="0.01" :class="{ 'has-error': err('value') }" @input="clear('value')" />
                <span v-if="percent">%</span>
              </div>
              <p v-if="err('value')" class="err">{{ err('value') }}</p>
              <p v-else-if="!isCoupon && !percent" class="hint">On chosen products it is taken off every unit; on the whole store it is taken off the order once.</p>
            </div>
          </template>
          <div class="field" :class="{ full: form.scope === 'store' }">
            <label for="pscope">Applies to <span class="req">*</span></label>
            <select id="pscope" v-model="form.scope" class="select" :class="{ 'has-error': err('scope') }" @change="form.targets = []; clear('scope', 'target_ids')">
              <option v-for="[v, label] in SCOPES" :key="v" :value="v">{{ label }}</option>
            </select>
            <p v-if="err('scope')" class="err">{{ err('scope') }}</p>
            <p v-else-if="form.scope === 'categories'" class="hint">Includes the sub-categories of the chosen categories.</p>
          </div>
          <div v-if="form.scope !== 'store'" class="field full">
            <label>{{ TARGET_LABEL[form.scope] }} <span class="req">*</span> <span class="muted">· {{ form.targets.length }} chosen</span></label>
            <TargetPicker v-model="form.targets" :type="TARGET_TYPE[form.scope]" :invalid="Boolean(nestedErr('target_ids'))" @update:model-value="clear('target_ids')" />
            <p v-if="nestedErr('target_ids')" class="err">{{ nestedErr('target_ids') }}</p>
          </div>
        </div>
      </section>

      <section class="card">
        <div class="card__head"><h2>When</h2></div>
        <div class="card__body form-grid">
          <div class="field">
            <label for="pstart">Starts</label>
            <input id="pstart" v-model="form.starts_at" class="input" type="datetime-local" :class="{ 'has-error': err('starts_at') }" @input="clear('starts_at', 'ends_at')" />
            <p v-if="err('starts_at')" class="err">{{ err('starts_at') }}</p>
            <p v-else class="hint">Empty = right away. Store time ({{ timezone() }}).</p>
          </div>
          <div class="field">
            <label for="pend">Ends</label>
            <input id="pend" v-model="form.ends_at" class="input" type="datetime-local" :class="{ 'has-error': err('ends_at') }" @input="clear('ends_at')" />
            <p v-if="err('ends_at')" class="err">{{ err('ends_at') }}</p>
            <p v-else class="hint">Empty = no end. Store time ({{ timezone() }}).</p>
          </div>
        </div>
      </section>

      <section v-if="isCoupon" class="card">
        <div class="card__head"><h2>Limits</h2></div>
        <div class="card__body form-grid">
          <div class="field">
            <label for="pmin">Minimum order</label>
            <div class="input-affix"><span>{{ currencySymbol() }}</span><input id="pmin" v-model="form.min_order" class="input" type="number" min="0" step="0.01" placeholder="no minimum" :class="{ 'has-error': err('min_order') }" @input="clear('min_order')" /></div>
            <p v-if="err('min_order')" class="err">{{ err('min_order') }}</p>
          </div>
          <div class="field">
            <label for="pmax">Maximum discount</label>
            <div class="input-affix"><span>{{ currencySymbol() }}</span><input id="pmax" v-model="form.max_discount" class="input" type="number" min="0" step="0.01" placeholder="no cap" :class="{ 'has-error': err('max_discount') }" @input="clear('max_discount')" /></div>
            <p v-if="err('max_discount')" class="err">{{ err('max_discount') }}</p>
          </div>
          <div class="field">
            <label for="plimit">Total uses</label>
            <input id="plimit" v-model="form.usage_limit" class="input" type="number" min="1" step="1" placeholder="unlimited" :class="{ 'has-error': err('usage_limit') }" @input="clear('usage_limit')" />
            <p v-if="err('usage_limit')" class="err">{{ err('usage_limit') }}</p>
          </div>
          <div class="field">
            <label for="pcust">Uses per customer</label>
            <input id="pcust" v-model="form.per_customer_limit" class="input" type="number" min="1" step="1" placeholder="unlimited" :class="{ 'has-error': err('per_customer_limit') }" @input="clear('per_customer_limit')" />
            <p v-if="err('per_customer_limit')" class="err">{{ err('per_customer_limit') }}</p>
            <p v-else class="hint">Customers are told apart by phone number.</p>
          </div>
          <div class="field full">
            <div style="display: flex; align-items: center; gap: 10px">
              <label class="switch" aria-label="First order only"><input v-model="form.first_order_only" type="checkbox" /><span /></label>
              <div>
                <strong style="font-weight: 500">First order only</strong>
                <p class="hint" style="margin: 0">Only for a phone number that has no earlier order.</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="save-bar">
      <span v-if="message" class="msg">{{ message }}</span>
      <RouterLink class="btn btn-outline" :to="listRoute" data-cancel>Cancel</RouterLink>
      <button class="btn btn-primary" type="submit" form="promo-form" :disabled="busy"><span v-if="busy" class="spinner" /> {{ isEdit ? `Save ${noun}` : `Add ${noun}` }}</button>
    </div>
  </form>
</template>

<style scoped>
.pf { max-width: 900px; }
</style>

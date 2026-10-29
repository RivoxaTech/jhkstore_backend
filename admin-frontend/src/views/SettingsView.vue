<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Save } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { setCurrency } from '@/utils/format.js'

const ui = useUiStore()
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const errors = ref({})
const symbol = ref('Rs')
const channels = ref([])
const form = reactive({
  delivery_fee: 0, free_delivery_min_cart: 0, coin_value: 1, max_redeem_coins_per_order: 0,
  redeem_cap_order_percent: 100, redeem_cap_balance_percent: 100, cod_max_amount: 0, min_order_amount: 0,
})
const saved = ref('')

function fill(data) {
  Object.keys(form).forEach(k => { form[k] = data[k] })
  symbol.value = data.currency_symbol || 'Rs'
  setCurrency(symbol.value)
  channels.value = data.channels.map(c => ({ code: c.code, name: c.name, delivery_days: c.delivery_days }))
  saved.value = JSON.stringify({ form: { ...form }, channels: channels.value })
}

async function load() {
  loading.value = true
  loadError.value = ''
  try { fill(await api.get('admin/settings/commerce')) } catch (e) { loadError.value = e.message } finally { loading.value = false }
}
onMounted(load)

const dirty = computed(() => saved.value !== JSON.stringify({ form: { ...form }, channels: channels.value }))

async function save() {
  saving.value = true
  errors.value = {}
  try {
    fill(await api.put('admin/settings/commerce', { ...form, channels: channels.value.map(c => ({ code: c.code, delivery_days: c.delivery_days })) }))
    ui.toast('Settings saved.')
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) errors.value = e.errors
    else ui.toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

const fe = (field) => errors.value[field]?.[0] || ''
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Settings</span></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="submit" form="settings-form" :disabled="saving || loading || !dirty"><span v-if="saving" class="spinner" /><Save v-else :size="16" /> Save settings</button>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <form v-else id="settings-form" class="settings-grid" novalidate @submit.prevent="save">
    <section class="card">
      <div class="card__head"><h2>Delivery</h2></div>
      <div class="card__body form-grid">
        <div class="field">
          <label for="delivery_fee">Delivery charge</label>
          <div class="input-affix"><span>{{ symbol }}</span><input id="delivery_fee" v-model.number="form.delivery_fee" class="input" :class="{ 'has-error': fe('delivery_fee') }" type="number" min="0" step="0.01" /></div>
          <div class="hint">One fixed charge for every order, every postcode and both channels. 0 = delivery is always free.</div>
          <div v-if="fe('delivery_fee')" class="err">{{ fe('delivery_fee') }}</div>
        </div>
        <div class="field">
          <label for="free_delivery_min_cart">Free delivery from</label>
          <div class="input-affix"><span>{{ symbol }}</span><input id="free_delivery_min_cart" v-model.number="form.free_delivery_min_cart" class="input" :class="{ 'has-error': fe('free_delivery_min_cart') }" type="number" min="0" step="0.01" /></div>
          <div class="hint">Delivery is free when the cart subtotal reaches this amount. 0 = no free delivery.</div>
          <div v-if="fe('free_delivery_min_cart')" class="err">{{ fe('free_delivery_min_cart') }}</div>
        </div>
        <div v-for="(c, i) in channels" :key="c.code" class="field">
          <label :for="`days-${c.code}`">Delivery time: {{ c.name }}</label>
          <div class="input-affix input-affix--right"><input :id="`days-${c.code}`" v-model.number="c.delivery_days" class="input" :class="{ 'has-error': fe(`channels.${i}.delivery_days`) }" type="number" min="0" max="60" /><span>days</span></div>
          <div v-if="fe(`channels.${i}.delivery_days`)" class="err">{{ fe(`channels.${i}.delivery_days`) }}</div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Coins (Dentacoins)</h2></div>
      <div class="card__body form-grid">
        <div class="field">
          <label for="coin_value">Value of one coin</label>
          <div class="input-affix"><span>{{ symbol }}</span><input id="coin_value" v-model.number="form.coin_value" class="input" :class="{ 'has-error': fe('coin_value') }" type="number" min="0.01" step="0.01" /></div>
          <div class="hint">1 coin = {{ symbol }} {{ form.coin_value }} off the order.</div>
          <div v-if="fe('coin_value')" class="err">{{ fe('coin_value') }}</div>
        </div>
        <div class="field">
          <label for="max_redeem_coins_per_order">Most coins per order</label>
          <input id="max_redeem_coins_per_order" v-model.number="form.max_redeem_coins_per_order" class="input" :class="{ 'has-error': fe('max_redeem_coins_per_order') }" type="number" min="0" step="1" />
          <div v-if="fe('max_redeem_coins_per_order')" class="err">{{ fe('max_redeem_coins_per_order') }}</div>
        </div>
        <div class="field">
          <label for="redeem_cap_order_percent">Coins may pay at most</label>
          <div class="input-affix input-affix--right"><input id="redeem_cap_order_percent" v-model.number="form.redeem_cap_order_percent" class="input" :class="{ 'has-error': fe('redeem_cap_order_percent') }" type="number" min="0" max="100" step="1" /><span>% of order</span></div>
          <div class="hint">100 = coins can pay for the whole cart (delivery is always paid in money).</div>
          <div v-if="fe('redeem_cap_order_percent')" class="err">{{ fe('redeem_cap_order_percent') }}</div>
        </div>
        <div class="field">
          <label for="redeem_cap_balance_percent">One order may use at most</label>
          <div class="input-affix input-affix--right"><input id="redeem_cap_balance_percent" v-model.number="form.redeem_cap_balance_percent" class="input" :class="{ 'has-error': fe('redeem_cap_balance_percent') }" type="number" min="0" max="100" step="1" /><span>% of wallet</span></div>
          <div v-if="fe('redeem_cap_balance_percent')" class="err">{{ fe('redeem_cap_balance_percent') }}</div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>Orders and payment</h2></div>
      <div class="card__body form-grid">
        <div class="field">
          <label for="cod_max_amount">Cash on delivery limit</label>
          <div class="input-affix"><span>{{ symbol }}</span><input id="cod_max_amount" v-model.number="form.cod_max_amount" class="input" :class="{ 'has-error': fe('cod_max_amount') }" type="number" min="0" step="0.01" /></div>
          <div class="hint">Orders above this cannot be paid on delivery. 0 = no limit.</div>
          <div v-if="fe('cod_max_amount')" class="err">{{ fe('cod_max_amount') }}</div>
        </div>
        <div class="field">
          <label for="min_order_amount">Minimum order amount</label>
          <div class="input-affix"><span>{{ symbol }}</span><input id="min_order_amount" v-model.number="form.min_order_amount" class="input" :class="{ 'has-error': fe('min_order_amount') }" type="number" min="0" step="0.01" /></div>
          <div class="hint">Checked against the cart subtotal. 0 = no minimum.</div>
          <div v-if="fe('min_order_amount')" class="err">{{ fe('min_order_amount') }}</div>
        </div>
        <p class="muted full" style="grid-column: 1 / -1">All prices include taxes. The sales-tax rate of each product is saved on the order line for invoices.</p>
      </div>
    </section>
  </form>
</template>

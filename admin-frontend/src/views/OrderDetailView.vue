<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Ban, Check, FileText, PackageCheck, Printer, Save, Truck } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useSiteStore } from '@/stores/site.js'
import AppModal from '@/components/AppModal.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { formatDate, money } from '@/utils/format.js'

const route = useRoute()
const ui = useUiStore()
const site = useSiteStore()

const order = ref(null)
const loading = ref(true)
const error = ref('')
const modal = ref(null) // { status, title, confirm, tone }
const busy = ref(false)
const errors = ref({})
const form = reactive({ note: '', reason: '', courier: '', awb_number: '', tracking_url: '' })
const adminNote = ref('')
const noteBusy = ref(false)
const printMode = ref(['invoice', 'slip'].includes(String(route.query.print)) ? String(route.query.print) : 'invoice')

async function load() {
  loading.value = true
  error.value = ''
  try {
    order.value = (await api.get(`admin/orders/${route.params.id}`)).data
    adminNote.value = order.value.admin_note || ''
  } catch (e) {
    error.value = e.status === 404 ? 'This order does not exist.' : e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => route.params.id, load)

const o = computed(() => order.value)
const totals = computed(() => o.value?.totals || {})
// Reward-point rows only exist in the response when the feature is enabled: render them only if the key is there.
const has = (obj, key) => obj && obj[key] !== undefined && obj[key] !== null
const hasPoints = computed(() => has(totals.value, 'coins_to_earn'))
const hasItemPoints = computed(() => (o.value?.items || []).some(i => has(i, 'coins_earned')))
// tax: "Tax (incl.)" when the prices already contain it, plain "Tax" when it is added on top of the total
const taxLabel = computed(() => {
  const base = totals.value.tax_label || 'Tax'
  return totals.value.tax_inclusive ? `${base} (incl.)` : base
})
const hasTax = computed(() => has(totals.value, 'tax_total') && (Number(totals.value.tax_total) > 0 || !totals.value.tax_inclusive))
const storeAddress = computed(() => String(site.store.address || '').split(/\r?\n/).filter(Boolean))
const sameAddress = computed(() => JSON.stringify(o.value?.shipping_address) === JSON.stringify(o.value?.billing_address))

const NEXT = {
  confirmed: { title: 'Confirm this order', button: 'Confirm order', tone: 'primary', icon: Check },
  packed: { title: 'Mark as packed', button: 'Mark as packed', tone: 'primary', icon: PackageCheck },
  shipped: { title: 'Ship this order', button: 'Ship order', tone: 'primary', icon: Truck },
  delivered: { title: 'Mark as delivered', button: 'Mark as delivered', tone: 'success', icon: Check },
}
const next = computed(() => (o.value?.actions.next_status ? NEXT[o.value.actions.next_status] : null))

function openAction(status) {
  Object.assign(form, { note: '', reason: '', courier: '', awb_number: '', tracking_url: '' })
  errors.value = {}
  modal.value = status === 'cancelled'
    ? { status, title: 'Cancel this order', confirm: 'Cancel order', tone: 'danger' }
    : { status, title: NEXT[status].title, confirm: NEXT[status].button, tone: NEXT[status].tone }
}

async function submit() {
  busy.value = true
  errors.value = {}
  const body = { status: modal.value.status, note: form.note || undefined }
  if (modal.value.status === 'cancelled') body.reason = form.reason
  if (modal.value.status === 'shipped') Object.assign(body, { courier: form.courier, awb_number: form.awb_number, tracking_url: form.tracking_url || undefined })
  try {
    order.value = (await api.post(`admin/orders/${o.value.id}/status`, body)).data
    ui.toast(`Order ${o.value.order_number} is now ${o.value.status}.`)
    if (modal.value.status === 'confirmed' || modal.value.status === 'cancelled') ui.pendingOrders = Math.max(0, ui.pendingOrders - 1)
    modal.value = null
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) errors.value = e.errors
    else ui.toast(e.message, 'error')
  } finally {
    busy.value = false
  }
}

async function saveNote() {
  noteBusy.value = true
  try {
    order.value = (await api.patch(`admin/orders/${o.value.id}/note`, { admin_note: adminNote.value })).data
    ui.toast('Note saved.')
  } catch (e) {
    ui.toast(e.errors?.admin_note?.[0] || e.message, 'error')
  } finally {
    noteBusy.value = false
  }
}

async function print(mode) {
  printMode.value = mode
  await nextTick()
  window.print()
}

const fe = (field) => errors.value[field]?.[0] || ''
const addressLines = (a) => [a.line1, a.area, a.landmark && `Landmark: ${a.landmark}`, [a.city, a.state, a.postcode].filter(Boolean).join(', '), a.country].filter(Boolean)
const stepClass = (s) => ({ 'is-cancelled': s.status === 'cancelled', 'is-delivered': s.status === 'delivered' })
</script>

<template>
  <div class="page-head screen-only">
    <div>
      <h1 v-if="o">Order {{ o.order_number }}</h1>
      <h1 v-else>Order</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <RouterLink :to="{ name: 'orders' }">Orders</RouterLink> › <span>{{ o?.order_number || '...' }}</span></div>
    </div>
    <div v-if="o" class="act-bar">
      <button class="btn btn-outline" type="button" @click="print('invoice')"><Printer :size="16" /> Print invoice</button>
      <button class="btn btn-outline" type="button" @click="print('slip')"><FileText :size="16" /> Packing slip</button>
      <button v-if="o.actions.can_cancel" class="btn btn-soft-danger" type="button" @click="openAction('cancelled')"><Ban :size="16" /> Cancel order</button>
      <button v-if="next" class="btn" :class="`btn-${next.tone}`" type="button" @click="openAction(o.actions.next_status)"><component :is="next.icon" :size="16" /> {{ next.button }}</button>
    </div>
  </div>

  <div v-if="error" class="alert alert--error screen-only" role="alert">
    {{ error }} <RouterLink class="btn btn-outline btn-sm" style="margin-left: auto" :to="{ name: 'orders' }">Back to orders</RouterLink>
  </div>
  <TableSkeleton v-if="loading && !o" :rows="8" />

  <template v-if="o">
    <div class="screen-only">
      <div class="od-meta" style="margin-bottom: 12px">
        <StatusBadge :status="o.status" />
        <span>Placed {{ formatDate(o.placed_at, true) }}</span>
                <StatusBadge :status="o.payment.status" />
        <span v-if="o.estimated_delivery_date && o.status !== 'delivered' && o.status !== 'cancelled'">Estimated delivery {{ formatDate(o.estimated_delivery_date) }}</span>
        <span v-if="o.status === 'cancelled'" style="color: var(--danger)">Cancelled by {{ o.cancelled_by }}: {{ o.cancel_reason }}</span>
      </div>

      <div class="od-layout">
        <div>
          <section class="card">
            <div class="card__head"><h2>Items ({{ o.items.length }})<template v-if="hasPoints"> · {{ totals.coins_to_earn }} points to earn</template></h2></div>
            <div class="table-wrap">
              <table class="table od-items">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th><th v-if="hasItemPoints" class="num">Points</th></tr></thead>
                <tbody>
                  <tr v-for="i in o.items" :key="i.id">
                    <td>
                      <div class="cell-main">
                        <span class="thumb"><img v-if="i.image_url" :src="i.image_url" :alt="i.name" loading="lazy" /><template v-else>{{ i.name.slice(0, 2).toUpperCase() }}</template></span>
                        <div style="min-width: 0">
                          <strong style="white-space: normal">{{ i.name }}</strong>
                          <small v-if="i.option_name && i.option_name !== i.name" style="white-space: normal">{{ i.option_name }}</small>
                          <small style="white-space: normal">SKU {{ i.sku }}<template v-if="i.status === 'cancelled'"> · cancelled</template></small>
                        </div>
                      </div>
                    </td>
                    <td class="num">{{ i.qty }}</td>
                    <td class="num">
                      <strong>{{ money(i.unit_price) }}</strong>
                      <s v-if="i.mrp > i.unit_price" class="muted" style="margin-left: 4px">{{ money(i.mrp) }}</s>
                      <span v-if="i.bulk_applied" class="bulk-note">Bulk offer: Buy {{ i.bulk_applied.min_qty }}+ (option price {{ money(i.price) }})</span>
                    </td>
                    <td class="num"><strong>{{ money(i.line_total) }}</strong></td>
                    <td v-if="hasItemPoints" class="num">{{ i.coins_earned ?? 0 }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="totals" aria-label="Price breakdown">
              <div><span>Items total (regular prices)</span><span>{{ money(totals.items_total) }}</span></div>
              <div v-if="totals.product_discount > 0" class="minus"><span>Product discount</span><span>- {{ money(totals.product_discount) }}</span></div>
              <div v-if="totals.bulk_discount > 0" class="minus"><span>Bulk-offer discount</span><span>- {{ money(totals.bulk_discount) }}</span></div>
              <div><span>Subtotal</span><span>{{ money(totals.subtotal) }}</span></div>
              <div v-if="totals.coins_redeemed > 0" class="minus"><span>Points discount ({{ totals.coins_redeemed }} points)</span><span>- {{ money(totals.coins_discount) }}</span></div>
              <div><span>Delivery fee</span><span>{{ totals.delivery_fee > 0 ? money(totals.delivery_fee) : 'Free' }}</span></div>
              <div v-if="hasTax && !totals.tax_inclusive"><span>{{ taxLabel }}</span><span>+ {{ money(totals.tax_total) }}</span></div>
              <div class="grand"><span>Grand total</span><span>{{ money(totals.grand_total) }}</span></div>
              <small v-if="totals.tax_note || (hasTax && totals.tax_inclusive)">{{ totals.tax_note }}<template v-if="hasTax && totals.tax_inclusive"> ({{ taxLabel }}: {{ money(totals.tax_total) }})</template></small>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Status timeline</h2></div>
            <ol class="timeline">
              <li v-for="(s, idx) in o.timeline" :key="idx" :class="stepClass(s)">
                <strong>{{ s.label }}</strong> <small style="display: inline">· {{ formatDate(s.at, true) }} · {{ s.by.name || s.by.type }}</small>
                <span v-if="s.note" class="note" style="display: block">{{ s.note }}</span>
              </li>
            </ol>
          </section>
        </div>

        <div>
          <section class="card">
            <div class="card__head"><h2>Customer</h2><RouterLink class="btn btn-soft btn-sm" :to="{ name: 'orders', query: { user_id: o.customer.id } }">{{ o.customer.orders_count }} {{ o.customer.orders_count === 1 ? "order" : "orders" }}</RouterLink></div>
            <div class="kv-list">
              <div><span class="k">Name</span><span class="v">{{ o.customer.name }}</span></div>
              <div><span class="k">Email</span><span class="v">{{ o.customer.email || '-' }}</span></div>
              <div><span class="k">Phone</span><span class="v">{{ o.customer.phone || '-' }}</span></div>
              <div v-if="o.customer.tax_number"><span class="k">Tax number</span><span class="v">{{ o.customer.tax_number }}</span></div>
              <div><span class="k">Customer since</span><span class="v">{{ formatDate(o.customer.member_since) }}</span></div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Shipping address</h2></div>
            <div v-if="o.shipping_address" class="addr">
              <strong>{{ o.shipping_address.name }}<span v-if="o.shipping_address.type" class="tag">{{ o.shipping_address.type }}</span></strong>
              <div v-for="line in addressLines(o.shipping_address)" :key="line">{{ line }}</div>
              <div>Phone: {{ o.shipping_address.phone }}<template v-if="o.shipping_address.alt_phone"> · Alt: {{ o.shipping_address.alt_phone }}</template></div>
              <div v-if="o.shipping_address.tax_number">Tax number: {{ o.shipping_address.tax_number }}</div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Billing address</h2></div>
            <div v-if="sameAddress" class="addr muted">Same as the shipping address.</div>
            <div v-else-if="o.billing_address" class="addr">
              <strong>{{ o.billing_address.name }}<span v-if="o.billing_address.type" class="tag">{{ o.billing_address.type }}</span></strong>
              <div v-for="line in addressLines(o.billing_address)" :key="line">{{ line }}</div>
              <div>Phone: {{ o.billing_address.phone }}<template v-if="o.billing_address.alt_phone"> · Alt: {{ o.billing_address.alt_phone }}</template></div>
              <div v-if="o.billing_address.tax_number">Tax number: {{ o.billing_address.tax_number }}</div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Payment</h2><StatusBadge :status="o.payment.status" /></div>
            <div class="kv-list">
              <div><span class="k">Method</span><span class="v">{{ o.payment.method_label }}</span></div>
              <div><span class="k">Amount</span><span class="v">{{ money(o.payment.amount) }}</span></div>
              <div v-if="o.payment.collect_on_delivery"><span class="k">To collect on delivery</span><span class="v">{{ money(o.payment.collect_on_delivery) }}</span></div>
              <div v-if="o.payment.paid_at"><span class="k">Paid on</span><span class="v">{{ formatDate(o.payment.paid_at, true) }}</span></div>
            </div>
            <p v-if="o.payment.method === 'cod' && o.payment.status === 'cod_pending'" class="muted" style="padding: 0 14px 12px">Cash on delivery is marked as paid when the order is marked delivered.</p>
          </section>

          <section v-if="o.shipment" class="card">
            <div class="card__head"><h2>Shipment</h2></div>
            <div class="kv-list">
              <div><span class="k">Courier</span><span class="v">{{ o.shipment.courier }}</span></div>
              <div><span class="k">AWB / tracking no.</span><span class="v">{{ o.shipment.awb_number }}</span></div>
              <div v-if="o.shipment.tracking_url"><span class="k">Tracking</span><a class="v" style="color: var(--primary)" :href="o.shipment.tracking_url" target="_blank" rel="noopener">Open link</a></div>
              <div><span class="k">Shipped</span><span class="v">{{ formatDate(o.shipment.shipped_at, true) }}</span></div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Notes</h2></div>
            <div class="card__body" style="display: grid; gap: 10px">
              <div>
                <div class="muted">Customer note</div>
                <div>{{ o.customer_note || 'No note from the customer.' }}</div>
              </div>
              <div class="field">
                <label for="admin-note">Internal note (customers never see it)</label>
                <textarea id="admin-note" v-model="adminNote" class="textarea" rows="3" style="height: auto; padding: 8px 11px" maxlength="2000" />
                <button class="btn btn-outline btn-sm" type="button" style="margin-top: 6px" :disabled="noteBusy || adminNote === (o.admin_note || '')" @click="saveNote"><Save :size="14" /> Save note</button>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <!-- Printed version (invoice or packing slip); only visible when printing -->
    <div class="print-sheet" :class="{ 'is-slip': printMode === 'slip' }">
      <div class="ps-head">
        <img v-if="site.logoUrl" class="ps-logo" :src="site.logoUrl" :alt="site.name" />
        <div>
          <h1>{{ site.name }}</h1>
          <div v-for="line in storeAddress" :key="line">{{ line }}</div>
          <div v-if="site.store.phone || site.store.email">{{ [site.store.phone, site.store.email].filter(Boolean).join(' · ') }}</div>
        </div>
      </div>
      <div><strong>{{ printMode === 'slip' ? 'Packing slip' : 'Invoice' }}</strong> · Order {{ o.order_number }} · {{ formatDate(o.placed_at, true) }}</div>
      <div>Payment: {{ o.payment.method_label }} ({{ o.payment.status.replace('_', ' ') }})</div>
      <div class="ps-grid">
        <div>
          <strong>Ship to</strong>
          <div>{{ o.shipping_address.name }}</div>
          <div v-for="line in addressLines(o.shipping_address)" :key="line">{{ line }}</div>
          <div>Phone: {{ o.shipping_address.phone }}<template v-if="o.shipping_address.alt_phone"> / {{ o.shipping_address.alt_phone }}</template></div>
        </div>
        <div v-if="printMode !== 'slip'">
          <strong>Bill to</strong>
          <div>{{ o.billing_address.name }}</div>
          <div v-for="line in addressLines(o.billing_address)" :key="line">{{ line }}</div>
          <div v-if="o.billing_address.tax_number">Tax number: {{ o.billing_address.tax_number }}</div>
        </div>
      </div>
      <table>
        <thead><tr><th>#</th><th>Item</th><th>SKU</th><th class="num">Qty</th><th class="num price-col">Unit price</th><th class="num price-col">Total</th></tr></thead>
        <tbody>
          <tr v-for="(i, idx) in o.items" :key="i.id">
            <td>{{ idx + 1 }}</td><td>{{ i.name }}<template v-if="i.option_name && i.option_name !== i.name"> - {{ i.option_name }}</template></td><td>{{ i.sku }}</td>
            <td class="num">{{ i.qty }}</td><td class="num price-col">{{ money(i.unit_price) }}</td><td class="num price-col">{{ money(i.line_total) }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="printMode !== 'slip'" class="ps-totals">
        <div><span>Items total (regular prices)</span><span>{{ money(totals.items_total) }}</span></div>
        <div v-if="totals.product_discount > 0"><span>Product discount</span><span>- {{ money(totals.product_discount) }}</span></div>
        <div v-if="totals.bulk_discount > 0"><span>Bulk-offer discount</span><span>- {{ money(totals.bulk_discount) }}</span></div>
        <div v-if="totals.coins_redeemed > 0"><span>Points ({{ totals.coins_redeemed }})</span><span>- {{ money(totals.coins_discount) }}</span></div>
        <div><span>Delivery</span><span>{{ totals.delivery_fee > 0 ? money(totals.delivery_fee) : 'Free' }}</span></div>
        <div v-if="hasTax && !totals.tax_inclusive"><span>{{ taxLabel }}</span><span>{{ money(totals.tax_total) }}</span></div>
        <div class="grand"><span>Total</span><span>{{ money(totals.grand_total) }}</span></div>
        <div v-if="hasTax && totals.tax_inclusive"><span>{{ taxLabel }}</span><span>{{ money(totals.tax_total) }}</span></div>
        <div v-if="totals.tax_note"><span>{{ totals.tax_note }}</span><span /></div>
      </div>
      <p v-if="o.customer_note" style="margin-top: 12px"><strong>Customer note:</strong> {{ o.customer_note }}</p>
    </div>
  </template>

  <AppModal v-if="modal" :title="modal.title" @close="!busy && (modal = null)">
    <form id="status-form" style="display: grid; gap: 12px" @submit.prevent="submit">
      <p v-if="modal.status === 'delivered' && o.payment.collect_on_delivery" class="alert alert--info" style="margin: 0">
        The courier collected {{ money(o.payment.collect_on_delivery) }} in cash: the payment will be marked as paid<template v-if="hasPoints"> and {{ totals.coins_to_earn }} points will be credited to the customer</template>.
      </p>
      <p v-else-if="modal.status === 'delivered' && hasPoints" class="alert alert--info" style="margin: 0">{{ totals.coins_to_earn }} points will be credited to the customer.</p>
      <p v-if="modal.status === 'cancelled'" class="alert alert--info" style="margin: 0">The stock goes back on the shelf<template v-if="totals.coins_redeemed > 0"> and {{ totals.coins_redeemed }} redeemed points are returned to the customer</template>.</p>

      <template v-if="modal.status === 'shipped'">
        <div class="form-grid">
          <div class="field">
            <label for="courier">Courier <span class="req">*</span></label>
            <input id="courier" v-model="form.courier" class="input" :class="{ 'has-error': fe('courier') }" placeholder="e.g. DHL, FedEx" maxlength="60" />
            <div v-if="fe('courier')" class="err">{{ fe('courier') }}</div>
          </div>
          <div class="field">
            <label for="awb">AWB / tracking number <span class="req">*</span></label>
            <input id="awb" v-model="form.awb_number" class="input" :class="{ 'has-error': fe('awb_number') }" maxlength="60" />
            <div v-if="fe('awb_number')" class="err">{{ fe('awb_number') }}</div>
          </div>
          <div class="field full">
            <label for="track">Tracking link (optional)</label>
            <input id="track" v-model="form.tracking_url" class="input" :class="{ 'has-error': fe('tracking_url') }" placeholder="https://" maxlength="500" />
            <div v-if="fe('tracking_url')" class="err">{{ fe('tracking_url') }}</div>
          </div>
        </div>
      </template>

      <div v-if="modal.status === 'cancelled'" class="field">
        <label for="reason">Reason <span class="req">*</span></label>
        <textarea id="reason" v-model="form.reason" class="textarea" :class="{ 'has-error': fe('reason') }" rows="3" maxlength="255" style="height: auto; padding: 8px 11px" placeholder="Why is this order cancelled?" />
        <div v-if="fe('reason')" class="err">{{ fe('reason') }}</div>
      </div>
      <div v-else class="field">
        <label for="note">Note for the timeline (optional)</label>
        <input id="note" v-model="form.note" class="input" maxlength="255" />
      </div>
      <div v-if="fe('status')" class="alert alert--error" style="margin: 0">{{ fe('status') }}</div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="modal = null">Close</button>
      <button class="btn" :class="`btn-${modal.tone}`" type="submit" form="status-form" :disabled="busy"><span v-if="busy" class="spinner" /> {{ modal.confirm }}</button>
    </template>
  </AppModal>
</template>

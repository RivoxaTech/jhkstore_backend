<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Ban, Check, CircleX, FileText, MessageCircle, PackageCheck, Printer, Save, ShieldCheck, Truck, ZoomIn } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useSiteStore } from '@/stores/site.js'
import AppModal from '@/components/AppModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { currencySymbol, formatDate, money, whatsappUrl } from '@/utils/format.js'

const route = useRoute()
const ui = useUiStore()
const auth = useAuthStore()
const canManage = computed(() => auth.can('orders.manage'))   // staff without it can look but not change
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
const fee = ref('')                 // delivery fee box
const feeBusy = ref(false)
const feeError = ref('')
const proofUrl = ref('')            // object URL of the payment proof (the file is private: it is fetched with the login cookie)
const proofState = ref('')          // '' | loading | error
const zoom = ref(false)
const proofModal = ref(false)       // "reject the proof" reason dialog
const proofReason = ref('')
const proofError = ref('')
const payBusy = ref(false)
const printMode = ref(['invoice', 'slip'].includes(String(route.query.print)) ? String(route.query.print) : 'invoice')

async function load(silent = false) {
  const hit = silent !== true ? api.peek(`admin/orders/${route.params.id}`) : undefined
  if (hit) { order.value = hit.data; adminNote.value = order.value.admin_note || ''; loading.value = false; silent = true }   // the last answer at once, the fresh one follows
  if (silent !== true) loading.value = true
  error.value = ''
  try {
    const fresh = (await api.get(`admin/orders/${route.params.id}`)).data
    const changed = !order.value || JSON.stringify(order.value) !== JSON.stringify(fresh)
    const typing = order.value && order.value.id === fresh.id && adminNote.value !== (order.value.admin_note || '')
    order.value = fresh
    if (!typing) adminNote.value = order.value.admin_note || ''
    if (changed || !proofUrl.value) loadProof()
  } catch (e) {
    error.value = e.status === 404 ? 'This order does not exist.' : e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => route.params.id, load)
watch(() => ui.ordersVersion, () => { if (!loading.value && !busy.value) load(true) })   // the order changed somewhere else (a customer paid, another tab): follow it by itself

// The screenshot is on a private disk: only the authenticated route can show it, so it is fetched with the login cookie and shown from an object URL.
async function loadProof() {
  if (proofUrl.value) URL.revokeObjectURL(proofUrl.value)
  proofUrl.value = ''
  proofState.value = ''
  const current = order.value
  if (!current?.payment?.has_proof) return
  proofState.value = 'loading'
  try {
    const blob = await api.blob(`admin/orders/${current.id}/payment/proof`)
    if (order.value?.id !== current.id) return
    proofUrl.value = URL.createObjectURL(blob)
    proofState.value = ''
  } catch {
    proofState.value = 'error'
  }
}
onBeforeUnmount(() => { if (proofUrl.value) URL.revokeObjectURL(proofUrl.value) })

const o = computed(() => order.value)
const totals = computed(() => o.value?.totals || {})
// optional parts of the answer are rendered only when their key is there
const has = (obj, key) => obj && obj[key] !== undefined && obj[key] !== null
// tax: "Tax (incl.)" when the prices already contain it, plain "Tax" when it is added on top of the total
const taxLabel = computed(() => {
  const base = totals.value.tax_label || 'Tax'
  return totals.value.tax_inclusive ? `${base} (incl.)` : base
})
const hasTax = computed(() => has(totals.value, 'tax_total') && (Number(totals.value.tax_total) > 0 || !totals.value.tax_inclusive))
const storeAddress = computed(() => String(site.store.address || '').split(/\r?\n/).filter(Boolean))
const sameAddress = computed(() => JSON.stringify(o.value?.shipping_address) === JSON.stringify(o.value?.billing_address))
// the delivery fee row: null = the fee is not confirmed yet
const feePending = computed(() => totals.value.delivery_fee === null || totals.value.delivery_fee === undefined)
const deliveryText = computed(() => (feePending.value ? 'To be confirmed' : totals.value.delivery_fee > 0 ? money(totals.value.delivery_fee) : 'Free'))

const NEXT = {
  confirmed: { title: 'Confirm this order', button: 'Confirm order', tone: 'primary', icon: Check },
  packed: { title: 'Mark as packed', button: 'Mark as packed', tone: 'primary', icon: PackageCheck },
  shipped: { title: 'Ship this order', button: 'Ship order', tone: 'primary', icon: Truck },
  delivered: { title: 'Mark as delivered', button: 'Mark as delivered', tone: 'success', icon: Check },
}
const next = computed(() => (o.value?.actions.next_status ? NEXT[o.value.actions.next_status] : null))

// the order is still waiting for something: say what, and keep the Confirm button off (the API would answer 422 with the same code)
const BLOCKED = {
  delivery_fee_pending: 'Set the delivery fee first.',
  payment_not_verified: 'Verify the bank transfer payment first.',
}
const blockedTitle = computed(() => BLOCKED[o.value?.actions?.blocked_reason] || '')   // the hover text of the disabled button
const blockedText = computed(() => (o.value?.actions?.blocked_reason === 'payment_not_verified' ? '' : blockedTitle.value))   // the owner knows a bank transfer is checked first: no line of text for it
const isBank = computed(() => o.value?.payment?.method === 'bank_transfer')
const FORM_FIELDS = ['status', 'reason', 'courier', 'awb_number', 'tracking_url', 'note']
const PROOF_LABEL = { none: 'No proof yet', submitted: 'Proof to check', verified: 'Verified', rejected: 'Proof rejected' }
// a proof that was never checked on an order that was turned down or cancelled is not waiting for anybody
const proofLabel = computed(() => (o.value?.payment?.proof_status === 'submitted' && ['rejected', 'cancelled'].includes(o.value.status) ? 'Not checked' : PROOF_LABEL[o.value?.payment?.proof_status] || o.value?.payment?.proof_status))

// A status change asks for nothing: the button changes the status at once. Only cancelling and rejecting (they cannot be undone) ask "are you sure?".
const confirmMove = ref(null)
function openAction(status) {
  errors.value = {}
  if (status === 'cancelled' || status === 'rejected') { confirmMove.value = status; return }
  modal.value = { status }
  submit()
}
function confirmedMove() {
  modal.value = { status: confirmMove.value }
  confirmMove.value = null
  submit()
}

async function submit() {
  busy.value = true
  errors.value = {}
  const body = { status: modal.value.status }
  try {
    order.value = (await api.post(`admin/orders/${o.value.id}/status`, body)).data
    ui.toast(`Order ${o.value.order_number} is now ${o.value.status}.`)
    if (['confirmed', 'cancelled', 'rejected'].includes(modal.value.status)) ui.pendingOrders = Math.max(0, ui.pendingOrders - 1)
    modal.value = null
    loadProof()
  } catch (e) {
    // a field of the form gets its message under the field; anything else (delivery_fee_pending, payment_not_verified, ...) is the server's message
    if (e instanceof ApiError && FORM_FIELDS.some(k => e.errors[k])) errors.value = e.errors
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

// ---- delivery fee of an order that was placed without one
async function setFee() {
  feeError.value = ''
  if (String(fee.value).trim() === '') { feeError.value = 'Enter the delivery fee (0 for free delivery).'; return }
  feeBusy.value = true
  try {
    order.value = (await api.put(`admin/orders/${o.value.id}/delivery-fee`, { fee: Number(fee.value) })).data
    fee.value = ''
    ui.toast('Delivery fee set. The total was recalculated.')
  } catch (e) {
    feeError.value = e.errors?.fee?.[0] || e.message
  } finally {
    feeBusy.value = false
  }
}

// ---- bank transfer: verify the proof or send it back
async function verifyPayment() {
  payBusy.value = true
  try {
    order.value = (await api.post(`admin/orders/${o.value.id}/payment/verify`)).data
    ui.toast('Payment verified.')
  } catch (e) {
    ui.toast(e.message, 'error')
  } finally {
    payBusy.value = false
  }
}

function openRejectProof() { proofReason.value = ''; proofError.value = ''; proofModal.value = true }
async function rejectProof() {
  proofError.value = ''
  payBusy.value = true
  try {
    order.value = (await api.post(`admin/orders/${o.value.id}/payment/reject-proof`, { reason: proofReason.value })).data
    proofModal.value = false
    ui.toast('Proof sent back. The customer can upload a new one.')
    loadProof()
  } catch (e) {
    proofError.value = e.errors?.reason?.[0] || e.message
  } finally {
    payBusy.value = false
  }
}

async function print(mode) {
  printMode.value = mode
  await nextTick()
  window.print()
}

const fe = (field) => errors.value[field]?.[0] || ''
// WhatsApp with the customer, prefilled with the order number and where the order stands
const whatsapp = computed(() => {
  const x = o.value
  if (!x) return null
  const text = `Hello ${x.customer.name}, this is ${site.name} about your order ${x.order_number} (${x.status_label.toLowerCase()}).`
  return whatsappUrl(x.customer.phone, text)
})
const addressLines = (a) => [a.line1, a.area, a.landmark && `Landmark: ${a.landmark}`, [a.city, a.state, a.postcode].filter(Boolean).join(', '), a.country].filter(Boolean)
const stepClass = (s) => ({ 'is-cancelled': s.status === 'cancelled' || s.status === 'rejected', 'is-delivered': s.status === 'delivered' })
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
      <a v-if="whatsapp" class="btn btn-outline" :href="whatsapp" target="_blank" rel="noopener" data-testid="whatsapp"><MessageCircle :size="16" /> WhatsApp</a>
      <button v-if="canManage && o.actions.can_cancel" class="btn btn-soft-danger" type="button" @click="openAction('cancelled')"><Ban :size="16" /> Cancel order</button>
      <button v-if="canManage && o.actions.can_reject" class="btn btn-soft-danger" type="button" data-reject-order @click="openAction('rejected')"><CircleX :size="16" /> Reject order</button>
      <span v-if="canManage && next && blockedText" class="muted" data-blocked :data-reason="o.actions.blocked_reason" style="max-width: 220px; line-height: 1.25">{{ blockedText }}</span>
      <button v-if="canManage && next" class="btn" :class="`btn-${next.tone}`" type="button" :disabled="Boolean(o.actions.blocked_reason)" :title="blockedTitle || undefined" @click="openAction(o.actions.next_status)"><component :is="next.icon" :size="16" /> {{ next.button }}</button>
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
        <span v-if="o.estimated_delivery_date && !['delivered', 'cancelled', 'rejected'].includes(o.status)">Estimated delivery {{ formatDate(o.estimated_delivery_date) }}</span>
        <span v-if="o.status === 'cancelled'" style="color: var(--danger)">Cancelled by {{ o.cancelled_by }}: {{ o.cancel_reason }}</span>
        <span v-if="o.status === 'rejected'" style="color: var(--danger)" data-reject-reason>Rejected: {{ o.reject_reason }}</span>
        <span v-if="o.delivery.fee_pending" style="color: var(--warning)">Delivery fee to be confirmed</span>
      </div>

      <div class="od-layout">
        <div>
          <section class="card" data-section="payment">
            <div class="card__head"><h2>Payment</h2><StatusBadge :status="o.payment.status" /></div>
            <div class="pay" :class="{ 'pay--bank': isBank }">
              <div class="pay__main">
                <div class="kv-list">
                  <div><span class="k">Method</span><span class="v">{{ o.payment.method_label }}</span></div>
                  <div><span class="k">Amount</span><span class="v">{{ money(o.payment.amount) }}</span></div>
                  <div v-if="o.payment.collect_on_delivery"><span class="k">To collect on delivery</span><span class="v">{{ money(o.payment.collect_on_delivery) }}</span></div>
                  <div v-if="o.payment.paid_at"><span class="k">Paid on</span><span class="v">{{ formatDate(o.payment.paid_at, true) }}</span></div>
                  <template v-if="isBank">
                    <div><span class="k">Proof</span><span class="v"><span class="badge" :class="`badge--${o.payment.proof_status}`" data-proof-status>{{ proofLabel }}</span></span></div>
                    <div v-if="o.payment.proof_reference"><span class="k">Reference</span><span class="v" data-proof-reference>{{ o.payment.proof_reference }}</span></div>
                    <div v-if="o.payment.verified_at"><span class="k">Verified on</span><span class="v">{{ formatDate(o.payment.verified_at, true) }}</span></div>
                  </template>
                </div>
                <p v-if="o.payment.method === 'cod' && o.payment.status === 'cod_pending'" class="muted pay__note">Cash on delivery is marked as paid when the order is marked delivered.</p>
                <div v-if="isBank" class="pay__accounts">
                  <div class="muted">Accounts shown to the customer</div>
                  <p v-if="!o.payment.bank_accounts.length" class="muted" style="margin: 0">None (no active account).</p>
                  <ul v-else class="acct-show">
                    <li v-for="(a, i) in o.payment.bank_accounts" :key="i">
                      <strong>{{ a.bank }}</strong><span v-if="a.title"> · {{ a.title }}</span>
                      <div v-if="a.number" class="muted">Account {{ a.number }}</div>
                      <div v-if="a.iban" class="muted">IBAN {{ a.iban }}</div>
                    </li>
                  </ul>
                  <p v-if="o.payment.bank_instructions" class="muted" style="margin: 4px 0 0; white-space: pre-line">{{ o.payment.bank_instructions }}</p>
                </div>
              </div>

              <div v-if="isBank" class="pay__proof" data-bank-panel>
                <p v-if="o.payment.proof_reject_reason && o.payment.proof_status === 'rejected'" class="alert alert--error" style="margin: 0" data-proof-reject-reason>Proof rejected: {{ o.payment.proof_reject_reason }}</p>
                <div v-if="o.payment.has_proof">
                  <div class="muted" style="margin-bottom: 4px">Payment proof (customer's screenshot)</div>
                  <button v-if="proofUrl" class="proof-thumb" type="button" title="Click to enlarge" data-proof-thumb @click="zoom = true">
                    <img :src="proofUrl" alt="Payment proof" data-proof-img /><span class="proof-zoom"><ZoomIn :size="16" /></span>
                  </button>
                  <p v-else-if="proofState === 'loading'" class="muted" style="margin: 0">Loading the picture...</p>
                  <p v-else-if="proofState === 'error'" class="err" style="margin: 0">The picture could not be loaded. <button class="btn btn-outline btn-sm" type="button" @click="loadProof">Retry</button></p>
                </div>
                <p v-else class="muted" style="margin: 0" data-no-proof>The customer has not uploaded a proof yet.</p>
                <div v-if="canManage && o.actions.can_verify_payment" class="act-bar">
                  <button class="btn btn-success" type="button" :disabled="payBusy" data-verify-payment @click="verifyPayment"><span v-if="payBusy" class="spinner" /><ShieldCheck v-else :size="16" /> Verify payment</button>
                  <button class="btn btn-soft-danger" type="button" :disabled="payBusy" data-reject-proof @click="openRejectProof"><CircleX :size="16" /> Reject proof</button>
                </div>
              </div>
            </div>
          </section>
          <section class="card">
            <div class="card__head"><h2>Items ({{ o.items.length }})</h2></div>
            <div class="table-wrap">
              <table class="table od-items">
                <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Total</th></tr></thead>
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
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="totals" aria-label="Price breakdown">
              <div><span>Items total (regular prices)</span><span>{{ money(totals.items_total) }}</span></div>
              <div v-if="totals.product_discount > 0" class="minus"><span>Product discount</span><span>- {{ money(totals.product_discount) }}</span></div>
              <div v-if="totals.bulk_discount > 0" class="minus"><span>Bulk-offer discount</span><span>- {{ money(totals.bulk_discount) }}</span></div>
              <div v-if="totals.discount_total > 0" class="minus" data-discount-row><span>Discount</span><span>- {{ money(totals.discount_total) }}</span></div>
              <div v-if="totals.coupon_discount > 0" class="minus" data-coupon-row><span>{{ totals.coupon_code ? `Coupon ${totals.coupon_code}` : "Coupon" }}</span><span>- {{ money(totals.coupon_discount) }}</span></div>
              <div><span>Subtotal</span><span>{{ money(totals.subtotal) }}</span></div>
              <div><span>Delivery fee</span><span data-delivery-text>{{ deliveryText }}</span></div>
              <div v-if="hasTax && !totals.tax_inclusive"><span>{{ taxLabel }}</span><span>+ {{ money(totals.tax_total) }}</span></div>
              <div class="grand"><span>Grand total</span><span>{{ money(totals.grand_total) }}</span></div>
              <small v-if="feePending">The delivery fee is not set yet: the total does not include it.</small>
              <small v-if="totals.tax_note || (hasTax && totals.tax_inclusive)">{{ totals.tax_note }}<template v-if="hasTax && totals.tax_inclusive"> ({{ taxLabel }}: {{ money(totals.tax_total) }})</template></small>
            </div>
          </section>

        </div>

        <div>
          <section v-if="o.actions.needs_delivery_fee" class="card" data-section="delivery-fee">
            <div class="card__head"><h2>Delivery fee</h2><StatusBadge status="pending" /></div>
            <div class="card__body" style="display: grid; gap: 8px">
              <p class="muted" style="margin: 0">This order was placed without a delivery fee. Set it to recalculate the total; the order cannot be confirmed before.</p>
              <div v-if="canManage" class="field">
                <label for="fee">Delivery fee <span class="req">*</span></label>
                <div style="display: flex; gap: 8px">
                  <div class="input-affix" style="flex: 1"><span>{{ currencySymbol() }}</span><input id="fee" v-model="fee" class="input" type="number" min="0" step="0.01" placeholder="0 = free" :class="{ 'has-error': feeError }" @keyup.enter="setFee" /></div>
                  <button class="btn btn-primary" type="button" :disabled="feeBusy" data-set-fee @click="setFee"><span v-if="feeBusy" class="spinner" /> Set fee</button>
                </div>
                <div v-if="feeError" class="err">{{ feeError }}</div>
              </div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Customer</h2><RouterLink class="btn btn-soft btn-sm" :to="{ name: 'orders', query: { customer_id: o.customer.id } }">{{ o.customer.orders_count }} {{ o.customer.orders_count === 1 ? "order" : "orders" }}</RouterLink></div>
            <div class="kv-list">
              <div><span class="k">Name</span><span class="v">{{ o.customer.name }}</span></div>
              <div><span class="k">Email</span><span class="v">{{ o.customer.email || '-' }}</span></div>
              <div><span class="k">Phone</span><span class="v">{{ o.customer.phone || '-' }}</span></div>
              <div><span class="k">First order</span><span class="v">{{ formatDate(o.customer.first_order_at) }}</span></div>
            </div>
          </section>

          <section class="card">
            <div class="card__head"><h2>Shipping address</h2></div>
            <div v-if="o.shipping_address" class="addr">
              <strong>{{ o.shipping_address.name }}<span v-if="o.shipping_address.type" class="tag">{{ o.shipping_address.type }}</span></strong>
              <div v-for="line in addressLines(o.shipping_address)" :key="line">{{ line }}</div>
              <div>Phone: {{ o.shipping_address.phone }}</div>
            </div>
            <div v-if="sameAddress" class="addr-same muted">Billing address: same as the shipping address.</div>
          </section>

          <section v-if="!sameAddress" class="card">
            <div class="card__head"><h2>Billing address</h2></div>
            <div v-if="o.billing_address" class="addr">
              <strong>{{ o.billing_address.name }}<span v-if="o.billing_address.type" class="tag">{{ o.billing_address.type }}</span></strong>
              <div v-for="line in addressLines(o.billing_address)" :key="line">{{ line }}</div>
              <div>Phone: {{ o.billing_address.phone }}</div>
            </div>
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
                <textarea id="admin-note" v-model="adminNote" class="textarea" rows="3" :readonly="!canManage" style="height: auto; padding: 8px 11px" maxlength="2000" />
                <button v-if="canManage" class="btn btn-outline btn-sm" type="button" style="margin-top: 6px" :disabled="noteBusy || adminNote === (o.admin_note || '')" @click="saveNote"><Save :size="14" /> Save note</button>
              </div>
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
          <div>Phone: {{ o.shipping_address.phone }}</div>
        </div>
        <div v-if="printMode !== 'slip'">
          <strong>Bill to</strong>
          <div>{{ o.billing_address.name }}</div>
          <div v-for="line in addressLines(o.billing_address)" :key="line">{{ line }}</div>
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
        <div v-if="totals.discount_total > 0"><span>Discount</span><span>- {{ money(totals.discount_total) }}</span></div>
        <div v-if="totals.coupon_discount > 0"><span>{{ totals.coupon_code ? `Coupon ${totals.coupon_code}` : "Coupon" }}</span><span>- {{ money(totals.coupon_discount) }}</span></div>
        <div><span>Delivery</span><span>{{ deliveryText }}</span></div>
        <div v-if="hasTax && !totals.tax_inclusive"><span>{{ taxLabel }}</span><span>{{ money(totals.tax_total) }}</span></div>
        <div class="grand"><span>Total</span><span>{{ money(totals.grand_total) }}</span></div>
        <div v-if="hasTax && totals.tax_inclusive"><span>{{ taxLabel }}</span><span>{{ money(totals.tax_total) }}</span></div>
        <div v-if="totals.tax_note"><span>{{ totals.tax_note }}</span><span /></div>
      </div>
      <p v-if="o.customer_note" style="margin-top: 12px"><strong>Customer note:</strong> {{ o.customer_note }}</p>
    </div>
  </template>

  <ConfirmDialog
    v-if="confirmMove" :title="confirmMove === 'rejected' ? 'Reject this order' : 'Cancel this order'" :confirm-text="confirmMove === 'rejected' ? 'Reject order' : 'Cancel order'" tone="danger" :busy="busy"
    :message="confirmMove === 'rejected' ? 'Reject this order? The stock goes back on the shelf and a coupon use is given back. This cannot be undone.' : 'Cancel this order? The stock goes back on the shelf. This cannot be undone.'"
    @confirm="confirmedMove" @close="confirmMove = null"
  />
  <AppModal v-if="proofModal" title="Reject the payment proof" @close="!payBusy && (proofModal = false)">
    <form id="proof-form" style="display: grid; gap: 12px" @submit.prevent="rejectProof">
      <p class="alert alert--info" style="margin: 0">The order stays pending. The customer sees your reason and can upload a new screenshot.</p>
      <div class="field">
        <label for="proof-reason">Reason <span class="req">*</span></label>
        <textarea id="proof-reason" v-model="proofReason" class="textarea" :class="{ 'has-error': proofError }" rows="3" maxlength="300" style="height: auto; padding: 8px 11px" placeholder="e.g. The amount does not match the order total." />
        <div v-if="proofError" class="err">{{ proofError }}</div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="payBusy" @click="proofModal = false">Close</button>
      <button class="btn btn-danger" type="submit" form="proof-form" :disabled="payBusy"><span v-if="payBusy" class="spinner" /> Reject proof</button>
    </template>
  </AppModal>

  <AppModal v-if="zoom && proofUrl" title="Payment proof" large @close="zoom = false">
    <img :src="proofUrl" alt="Payment proof, enlarged" data-proof-zoom style="max-width: 100%; max-height: 72vh; margin: 0 auto; object-fit: contain" />
  </AppModal>
</template>

<style scoped>
.proof-thumb { position: relative; display: block; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: var(--muted-soft); cursor: zoom-in; padding: 0; max-width: 100%; }
.proof-thumb { aspect-ratio: 3 / 4; width: 168px; }   /* a phone screenshot is 3:4 portrait: the frame has that shape, the picture is shown whole inside it */
.proof-thumb img { display: block; width: 100%; height: 100%; object-fit: contain; }
.proof-zoom { position: absolute; right: 6px; bottom: 6px; background: rgba(15, 23, 42, .65); color: #fff; border-radius: 6px; padding: 3px; display: grid; }
.acct-show { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
.acct-show li { padding: 6px 9px; border: 1px solid var(--border-light); border-radius: 8px; background: #f8fafc; }
</style>

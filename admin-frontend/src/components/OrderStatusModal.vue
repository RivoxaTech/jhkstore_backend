<script setup>
import { computed, reactive, ref } from 'vue'
import { api, ApiError } from '@/api/client.js'
import { money } from '@/utils/format.js'
import AppModal from '@/components/AppModal.vue'

// The form behind a status change from the orders list: shipping needs the courier and the AWB, cancelling and rejecting need a reason,
// delivered says that a cash order is marked paid. (The order page has the same form; the server checks everything either way.)
const props = defineProps({
  order: { type: Object, required: true },     // a row of the orders list
  status: { type: String, required: true },    // the status chosen in the dropdown
})
const emit = defineEmits(['close', 'done'])

const TEXT = {
  shipped: { title: 'Ship this order', button: 'Ship order', tone: 'primary' },
  delivered: { title: 'Mark as delivered', button: 'Mark as delivered', tone: 'success' },
  cancelled: { title: 'Cancel this order', button: 'Cancel order', tone: 'danger' },
  rejected: { title: 'Reject this order', button: 'Reject order', tone: 'danger' },
}
const text = computed(() => TEXT[props.status])
const form = reactive({ reason: '', courier: '', awb_number: '', tracking_url: '', note: '' })
const errors = ref({})
const busy = ref(false)
const fe = (k) => errors.value[k]?.[0] || ''
const FIELDS = ['status', 'reason', 'courier', 'awb_number', 'tracking_url', 'note']

async function submit() {
  busy.value = true
  errors.value = {}
  const body = { status: props.status, note: form.note || undefined }
  if (props.status === 'cancelled' || props.status === 'rejected') body.reason = form.reason
  if (props.status === 'shipped') Object.assign(body, { courier: form.courier, awb_number: form.awb_number, tracking_url: form.tracking_url || undefined })
  try {
    const res = await api.post(`admin/orders/${props.order.id}/status`, body)
    emit('done', res.data)
  } catch (e) {
    if (e instanceof ApiError && FIELDS.some(k => e.errors[k])) errors.value = e.errors
    else errors.value = { status: [e.message] }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppModal :title="`${text.title}: ${order.order_number}`" @close="!busy && emit('close')">
    <form id="list-status-form" style="display: grid; gap: 12px" @submit.prevent="submit">
      <p v-if="status === 'delivered' && order.payment_method === 'cod'" class="alert alert--info" style="margin: 0">The courier collected {{ money(order.grand_total) }} in cash: the payment will be marked as paid.</p>
      <p v-if="status === 'cancelled'" class="alert alert--info" style="margin: 0">The stock goes back on the shelf.</p>
      <p v-if="status === 'rejected'" class="alert alert--info" style="margin: 0">The customer sees the reason on the order page. The stock goes back on the shelf and a coupon use is given back.</p>

      <template v-if="status === 'shipped'">
        <div class="form-grid">
          <div class="field">
            <label for="ls-courier">Courier <span class="req">*</span></label>
            <input id="ls-courier" v-model="form.courier" class="input" :class="{ 'has-error': fe('courier') }" placeholder="e.g. DHL, FedEx" maxlength="60" autofocus />
            <div v-if="fe('courier')" class="err">{{ fe('courier') }}</div>
          </div>
          <div class="field">
            <label for="ls-awb">AWB / tracking number <span class="req">*</span></label>
            <input id="ls-awb" v-model="form.awb_number" class="input" :class="{ 'has-error': fe('awb_number') }" maxlength="60" />
            <div v-if="fe('awb_number')" class="err">{{ fe('awb_number') }}</div>
          </div>
          <div class="field full">
            <label for="ls-track">Tracking link (optional)</label>
            <input id="ls-track" v-model="form.tracking_url" class="input" :class="{ 'has-error': fe('tracking_url') }" placeholder="https://" maxlength="500" />
            <div v-if="fe('tracking_url')" class="err">{{ fe('tracking_url') }}</div>
          </div>
        </div>
      </template>

      <div v-if="status === 'cancelled' || status === 'rejected'" class="field">
        <label for="ls-reason">Reason <span v-if="status === 'rejected'" class="req">*</span></label>
        <textarea id="ls-reason" v-model="form.reason" class="textarea" :class="{ 'has-error': fe('reason') }" rows="3" maxlength="300" style="height: auto; padding: 8px 11px" :placeholder="status === 'rejected' ? 'e.g. We do not deliver to this area' : 'e.g. The customer asked to cancel'" autofocus />
        <div v-if="fe('reason')" class="err">{{ fe('reason') }}</div>
      </div>

      <div v-if="status === 'shipped' || status === 'delivered'" class="field">
        <label for="ls-note">Note (optional)</label>
        <input id="ls-note" v-model="form.note" class="input" maxlength="500" />
      </div>

      <p v-if="fe('status')" class="alert alert--error" style="margin: 0" role="alert">{{ fe('status') }}</p>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Close</button>
      <button class="btn" :class="`btn-${text.tone}`" type="submit" form="list-status-form" :disabled="busy"><span v-if="busy" class="spinner" /> {{ text.button }}</button>
    </template>
  </AppModal>
</template>

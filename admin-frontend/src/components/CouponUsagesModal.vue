<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '@/api/client.js'
import { formatDate, money } from '@/utils/format.js'
import AppModal from '@/components/AppModal.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Where a coupon was used: when and for how much. The order number and the customer's phone are only sent to an admin who may see orders,
// so those two columns appear only when the API sends them.
const props = defineProps({ coupon: { type: Object, required: true } })
const emit = defineEmits(['close'])

const rows = ref([])
const meta = ref(null)
const loading = ref(true)
const error = ref('')
const page = ref(1)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get(`admin/coupons/${props.coupon.id}/usages`, { page: page.value, per_page: 25 })
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
const withOrders = computed(() => rows.value.some(r => 'order_number' in r))
const go = (n) => { page.value = n; load() }
</script>

<template>
  <AppModal :title="`Uses of ${coupon.code}`" large @close="emit('close')">
    <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>
    <TableSkeleton v-if="loading && !rows.length" :rows="4" />
    <p v-else-if="!rows.length && !error" class="muted" data-no-usages>This coupon has not been used yet.</p>
    <div v-else-if="rows.length" class="table-wrap" :style="{ opacity: loading ? 0.55 : 1 }">
      <table class="table table--dense" data-usages style="min-width: 0">
        <thead><tr><th>Used</th><th v-if="withOrders">Order</th><th v-if="withOrders">Phone</th><th class="num">Discount</th><th>State</th></tr></thead>
        <tbody>
          <tr v-for="u in rows" :key="u.id">
            <td class="nowrap">{{ formatDate(u.used_at, true) }}</td>
            <td v-if="withOrders"><RouterLink v-if="u.order_id" class="order-no" :to="{ name: 'order', params: { id: u.order_id } }" @click="emit('close')">{{ u.order_number }}</RouterLink><span v-else>-</span></td>
            <td v-if="withOrders">{{ u.phone || '-' }}</td>
            <td class="num">{{ money(u.amount) }}</td>
            <td><span v-if="u.released" class="muted" :title="`Given back ${formatDate(u.released_at, true)}`">Given back (order cancelled or rejected)</span><span v-else>Counted</span></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="meta && meta.last_page > 1" class="pager" style="padding: 8px 0 0; border: 0">
      <span>{{ meta.total }} uses</span>
      <div class="pager__btns">
        <button class="btn btn-outline btn-sm" type="button" :disabled="meta.current_page <= 1" @click="go(meta.current_page - 1)">Prev</button>
        <span style="align-self: center; padding: 0 8px">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
        <button class="btn btn-outline btn-sm" type="button" :disabled="meta.current_page >= meta.last_page" @click="go(meta.current_page + 1)">Next</button>
      </div>
    </div>
    <template #footer><button class="btn btn-outline" type="button" @click="emit('close')">Close</button></template>
  </AppModal>
</template>

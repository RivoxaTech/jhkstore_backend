<script setup>
import { onMounted, ref } from 'vue'
import { ArrowDown, ArrowUp, MapPin, Pencil, Plus, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { money } from '@/utils/format.js'
import { useUiStore } from '@/stores/ui.js'
import DeliveryZoneModal from '@/components/DeliveryZoneModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// The delivery zone manager inside Settings > Delivery. The first active zone (top to bottom) that matches the address wins, so the order matters:
// move a zone with the arrows (saved at once). `method` is the delivery method in the form: zones only count with the Zones method.
defineProps({ method: { type: String, default: '' } })
const ui = useUiStore()

const zones = ref([])
const loading = ref(true)
const error = ref('')
const modal = ref(null)       // { item } while the create/edit modal is open
const toDelete = ref(null)
const busy = ref(false)

async function load() {
  error.value = ''
  try { zones.value = (await api.get('admin/delivery-zones')).data } catch (e) { error.value = e.message } finally { loading.value = false }
}
onMounted(load)

async function onSaved(zone, wasEdit) {
  modal.value = null
  ui.toast(wasEdit ? 'Zone updated.' : 'Zone added.')
  await load()
}

async function flip(zone) {
  const value = !zone.is_active
  zone.is_active = value
  try {
    const res = await api.patch(`admin/delivery-zones/${zone.id}/active`, { is_active: value })
    zones.value = zones.value.map(z => (z.id === zone.id ? res.data : z))
    ui.toast(value ? 'Zone is active.' : 'Zone is switched off.', 'info')
  } catch (e) {
    zone.is_active = !value
    ui.toast(e.message, 'error')
  }
}

async function move(from, to) {
  if (to < 0 || to >= zones.value.length) return
  const before = zones.value
  const list = [...before]
  list.splice(to, 0, list.splice(from, 1)[0])
  zones.value = list
  try {
    zones.value = (await api.put('admin/delivery-zones/reorder', { ids: list.map(z => z.id) })).data
  } catch (e) {
    zones.value = before
    ui.toast(e.message || 'The order could not be saved.', 'error')
  }
}

async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/delivery-zones/${toDelete.value.id}`)
    ui.toast('Zone deleted.')
    toDelete.value = null
    busy.value = false   // the dialog is free again at once, not after the list was re-read
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.message, 'error')
  } finally {
    busy.value = false
  }
}

const TYPE_LABEL = { city: 'City', area: 'Area', postcode: 'Postcode' }
const shown = (z) => z.match_values.slice(0, 4).join(', ') + (z.match_values.length > 4 ? ` +${z.match_values.length - 4}` : '')
</script>

<template>
  <section class="card zones-panel" data-section="zones">
    <div class="card__head">
      <h2>Delivery zones <span v-if="!loading" class="muted">· {{ zones.length }}</span></h2>
      <button class="btn btn-soft btn-sm" type="button" data-add-zone :disabled="loading" @click="modal = { item: null }"><Plus :size="14" /> Add zone</button>
    </div>
    <div class="card__body">
      <p v-if="method && method !== 'zones'" class="alert alert--info" data-zones-hint style="margin-bottom: 10px">Zones are used only with the Zones delivery method. The current method is "{{ method.replace(/_/g, ' ') }}", so these zones are ignored until you switch to Zones.</p>
      <p class="hint" style="margin: 0 0 8px">The first active zone from the top that matches the delivery address sets the fee, so put the most specific zone first.</p>

      <div v-if="error" class="alert alert--error" role="alert">{{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
      <TableSkeleton v-if="loading" :rows="4" />
      <EmptyState v-else-if="!zones.length && !error" title="No delivery zones yet" text="Add a zone for a city, an area or a postcode range with its own fee.">
        <template #icon><MapPin :size="36" /></template>
      </EmptyState>

      <div v-else-if="zones.length" class="table-wrap">
        <table class="table table--dense">
          <thead><tr><th>Zone</th><th>Matches</th><th class="num">Fee</th><th class="num">Free above</th><th class="num">Min order</th><th>Estimate</th><th>Active</th><th /></tr></thead>
          <tbody>
            <tr v-for="(z, i) in zones" :key="z.id" :data-zone="z.name" :style="z.is_active ? null : { opacity: 0.6 }">
              <td><strong class="truncate" :title="z.name">{{ z.name }}</strong></td>
              <td><span class="muted">{{ TYPE_LABEL[z.match_type] }}:</span> <span class="truncate" style="display: inline-block; vertical-align: bottom" :title="z.match_values.join(', ')">{{ shown(z) }}</span></td>
              <td class="num">{{ z.fee > 0 ? money(z.fee) : 'Free' }}</td>
              <td class="num">{{ z.free_above === null ? '-' : money(z.free_above) }}</td>
              <td class="num">{{ z.min_order === null ? '-' : money(z.min_order) }}</td>
              <td><span class="truncate" :title="z.eta_text || ''">{{ z.eta_text || '-' }}</span></td>
              <td><label class="switch" :title="z.is_active ? 'Active' : 'Off'"><input type="checkbox" :checked="z.is_active" :aria-label="`${z.name} active`" @change="flip(z)" /><span /></label></td>
              <td>
                <div class="row-actions">
                  <button class="btn btn-outline btn-sm btn-icon" type="button" :disabled="i === 0" :aria-label="`Move ${z.name} up`" @click="move(i, i - 1)"><ArrowUp :size="14" /></button>
                  <button class="btn btn-outline btn-sm btn-icon" type="button" :disabled="i === zones.length - 1" :aria-label="`Move ${z.name} down`" @click="move(i, i + 1)"><ArrowDown :size="14" /></button>
                  <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${z.name}`" @click="modal = { item: z }"><Pencil :size="14" /></button>
                  <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :aria-label="`Delete ${z.name}`" @click="toDelete = z"><Trash2 :size="14" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <DeliveryZoneModal v-if="modal" :item="modal.item" @close="modal = null" @saved="onSaved" />
    <ConfirmDialog
      v-if="toDelete" title="Delete delivery zone" confirm-text="Delete" tone="danger" :busy="busy"
      :message="`Delete the zone &quot;${toDelete.name}&quot;? Addresses it matched fall back to the next zone or to the &quot;outside every zone&quot; rule.`"
      @confirm="confirmDelete" @close="toDelete = null"
    />
  </section>
</template>

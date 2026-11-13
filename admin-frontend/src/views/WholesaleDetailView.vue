<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { MessageCircle } from '@lucide/vue'
import CopyButton from '@/components/CopyButton.vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import { useSiteStore } from '@/stores/site.js'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { formatDate, whatsappUrl } from '@/utils/format.js'

// One wholesale request on a page of its own: what the customer asked for, the status, an internal note and a WhatsApp button.
const STATUSES = ['new', 'contacted', 'quoted', 'closed']
const LABEL = { new: 'New', contacted: 'Contacted', quoted: 'Quoted', closed: 'Closed' }

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const ui = useUiStore()
const site = useSiteStore()
const canManage = computed(() => auth.can('wholesale.manage'))

const req = ref(null)
const loading = ref(true)
const loadError = ref('')
const form = ref({ status: 'new', admin_note: '' })
const saving = ref(false)
const formError = ref('')

async function load() {
  const hit = api.peek(`admin/wholesale/${route.params.id}`)
  if (hit) { req.value = hit.data; form.value = { status: req.value.status, admin_note: req.value.admin_note || '' }; loading.value = false } else loading.value = true   // the last answer at once, the fresh one follows
  loadError.value = ''
  try {
    const fresh = (await api.get(`admin/wholesale/${route.params.id}`)).data
    const typing = hit && (form.value.status !== (hit.data.status) || form.value.admin_note !== (hit.data.admin_note || ''))
    req.value = fresh
    if (!typing) form.value = { status: req.value.status, admin_note: req.value.admin_note || '' }
  } catch (e) {
    loadError.value = e.status === 404 ? 'This request does not exist (any more).' : e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(() => route.params.id, load)

async function save() {
  saving.value = true
  formError.value = ''
  try {
    const res = await api.patch(`admin/wholesale/${req.value.id}`, { status: form.value.status, admin_note: form.value.admin_note })
    ui.toast(`${res.data.reference} saved.`)
    router.push({ name: 'wholesale' })
  } catch (e) {
    formError.value = e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message
  } finally {
    saving.value = false
  }
}

const whatsapp = computed(() => (req.value ? whatsappUrl(req.value.phone, `Hello ${req.value.name}, this is ${site.name} about your wholesale request ${req.value.reference} (${req.value.quantity} × ${req.value.product_name}).`) : ''))
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ req ? `Request ${req.reference}` : 'Wholesale request' }}</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <RouterLink :to="{ name: 'wholesale' }">Wholesale</RouterLink> › <span>{{ req?.reference || 'Request' }}</span></div>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }}</div>
  <div v-else-if="loading" class="card"><TableSkeleton :rows="6" /></div>
  <div v-else class="wd-page">
    <section class="card">
      <div class="card__head"><h2>What the customer asked</h2></div>
      <div class="card__body">
        <dl class="wd__grid">
          <div><dt>Customer</dt><dd>{{ req.name }}</dd></div>
          <div><dt>Business</dt><dd>{{ req.business_name || '–' }}</dd></div>
          <div v-if="req.brn"><dt>BRN</dt><dd>{{ req.brn }} <CopyButton :text="req.brn" label="Copy BRN" /></dd></div>
          <div><dt>Phone</dt><dd>{{ req.phone }} <CopyButton :text="req.phone" label="Copy phone" /></dd></div>
          <div><dt>E-mail</dt><dd>{{ req.email || '–' }} <CopyButton :text="req.email" label="Copy e-mail" /></dd></div>
          <div><dt>Product</dt><dd>{{ req.product_name }}</dd></div>
          <div><dt>Quantity</dt><dd>{{ req.quantity }}</dd></div>
          <div><dt>Received</dt><dd>{{ formatDate(req.created_at, true) }}</dd></div>
          <div v-if="req.handled_at"><dt>Last handled</dt><dd>{{ formatDate(req.handled_at, true) }}</dd></div>
        </dl>
        <div v-if="req.message" class="wd__msg"><strong>Message</strong><p>{{ req.message }}</p></div>
      </div>
    </section>

    <form class="card" novalidate @submit.prevent="save">
      <div class="card__head"><h2>Your answer</h2></div>
      <div class="card__body">
        <div v-if="formError" class="alert alert--error" role="alert">{{ formError }}</div>
        <div class="form-grid">
          <div class="field">
            <label for="wstatus">Status</label>
            <select id="wstatus" v-model="form.status" class="select" :disabled="!canManage">
              <option v-for="s in STATUSES" :key="s" :value="s">{{ LABEL[s] }}</option>
            </select>
          </div>
          <div class="field full">
            <label for="wnote">Internal note</label>
            <textarea id="wnote" v-model="form.admin_note" class="textarea" rows="4" maxlength="2000" :disabled="!canManage" placeholder="Only you see this: what you quoted, when to call back..." />
          </div>
        </div>
      </div>
      <div class="wd__actions">
        <a v-if="whatsapp" class="btn btn-outline" :href="whatsapp" target="_blank" rel="noopener" data-testid="whatsapp" style="margin-right: auto"><MessageCircle :size="16" /> WhatsApp</a>
        <RouterLink class="btn btn-outline" :to="{ name: 'wholesale' }">{{ canManage ? 'Cancel' : 'Back' }}</RouterLink>
        <button v-if="canManage" class="btn btn-primary" type="submit" data-testid="save-request" :disabled="saving"><span v-if="saving" class="spinner" /> Save</button>
      </div>
    </form>
  </div>
</template>

<style scoped>
.wd-page { display: grid; gap: 12px; max-width: 900px; }
.wd__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px 20px; }
.wd__grid dt { font-size: 11.5px; color: var(--text-3); }
.wd__grid dd { font-weight: 500; overflow-wrap: anywhere; }
.wd__msg { background: var(--bg); border-radius: 8px; padding: 10px 12px; margin-top: 14px; }
.wd__msg p { white-space: pre-wrap; margin-top: 4px; }
.wd__actions { display: flex; gap: 10px; align-items: center; padding: 0 16px 16px; }
@media (max-width: 640px) { .wd__grid { grid-template-columns: 1fr; } }
</style>

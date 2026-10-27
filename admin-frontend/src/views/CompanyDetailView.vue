<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { Ban, CheckCircle2, Download, FileText, Pencil, Plus, Tags, Trash2, XCircle } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useLookupsStore } from '@/stores/lookups.js'
import AppModal from '@/components/AppModal.vue'
import BrandModal from '@/components/BrandModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import LogoBox from '@/components/LogoBox.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { formatBytes, formatDate } from '@/utils/format.js'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const lookups = useLookupsStore()

const company = ref(null)
const countries = ref([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

// which dialog is open: 'approve' | 'reject' | 'suspend' | 'delete' | null
const dialog = ref(null)
const reason = ref('')
const reasonError = ref('')
const brandModal = ref(null) // { brand } ; brand null = create
const brandToDelete = ref(null)

const id = computed(() => route.params.id)
const approved = computed(() => company.value?.status === 'approved')
const status = computed(() => company.value?.status)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [c, o] = await Promise.all([api.get(`admin/companies/${id.value}`), lookups.load()])
    company.value = c.data
    countries.value = o.countries
  } catch (e) {
    error.value = e.status === 404 ? 'This company does not exist (it may have been deleted).' : e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)
watch(id, load)

function openDialog(name) { reason.value = ''; reasonError.value = ''; dialog.value = name }

async function runReview(kind) {
  if (kind === 'reject' && reason.value.trim().length < 3) {
    reasonError.value = 'Please write a reason (at least 3 characters).'
    return
  }
  busy.value = true
  try {
    const body = kind === 'approve' ? undefined : { reason: reason.value.trim() }
    const res = await api.post(`admin/companies/${id.value}/${kind}`, body)
    // approve/reject/suspend return the company without the media list, so reload for the full view
    company.value = { ...company.value, ...res.data, catalogue: company.value.catalogue, brands: res.data.brands ?? company.value.brands }
    await load()
    ui.toast({ approve: 'Company approved. Its brands are now live.', reject: 'Company rejected.', suspend: 'Company suspended.' }[kind])
    dialog.value = null
    const pending = await api.get('admin/companies', { status: 'pending', per_page: 1 })
    ui.pendingCount = pending.counts?.pending ?? 0
  } catch (e) {
    if (e.status === 422) reasonError.value = e.firstError('reason') || e.message
    else ui.toast(e.message, 'error')
  } finally {
    busy.value = false
  }
}

async function deleteCompany() {
  busy.value = true
  try {
    await api.del(`admin/companies/${id.value}`)
    ui.toast('Company deleted.')
    router.replace({ name: 'companies' })
  } catch (e) {
    ui.toast(e.message, 'error', 6000)
    dialog.value = null
  } finally {
    busy.value = false
  }
}

function onBrandSaved(brand, wasEdit) {
  brandModal.value = null
  ui.toast(wasEdit ? 'Brand updated.' : 'Brand added.')
  load()
}

async function toggleBrand(brand) {
  const next = !brand.is_active
  brand.is_active = next // optimistic
  try {
    const fd = new FormData()
    fd.append('_method', 'PUT')
    fd.append('is_active', next ? '1' : '0')
    await api.postForm(`admin/brands/${brand.id}`, fd)
  } catch (e) {
    brand.is_active = !next
    ui.toast(e.message, 'error')
  }
}

async function deleteBrand() {
  busy.value = true
  try {
    await api.del(`admin/brands/${brandToDelete.value.id}`)
    ui.toast('Brand deleted.')
    brandToDelete.value = null
    await load()
  } catch (e) {
    ui.toast(e.message, 'error', 6000)
    brandToDelete.value = null
  } finally {
    busy.value = false
  }
}

async function downloadFile(file) {
  try {
    const url = new URL(file.download_url)
    await api.download(url.pathname.replace(/^\/api\/v1\//, ''), file.name)
  } catch (e) {
    ui.toast(e.status === 404 ? 'File not found.' : e.message, 'error')
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ company?.name || 'Company' }}</h1>
      <div class="crumbs">
        <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> ›
        <RouterLink :to="{ name: 'companies' }">Companies</RouterLink> ›
        <span>{{ company?.name || '...' }}</span>
      </div>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">
    {{ error }}
    <RouterLink class="btn btn-outline btn-sm" :to="{ name: 'companies' }" style="margin-left: auto">Back to companies</RouterLink>
  </div>
  <section v-else-if="loading && !company" class="card"><TableSkeleton :rows="9" /></section>

  <div v-else-if="company" class="stack">
    <!-- header with status + actions -->
    <section class="card">
      <div class="hero">
        <div class="hero__left">
          <LogoBox :name="company.name" :src="company.logo_thumb_url || company.logo_url" large />
          <div style="min-width: 0">
            <h2>{{ company.name }}</h2>
            <div class="hero__meta">
              <StatusBadge :status="company.status" />
              <span>{{ company.source === 'public_form' ? 'Website registration' : 'Created by admin' }}</span>
              <span>· Submitted {{ formatDate(company.submitted_at) }}</span>
            </div>
          </div>
        </div>
        <div class="page-actions">
          <button v-if="status === 'pending' || status === 'rejected' || status === 'suspended'" class="btn btn-success" type="button" @click="openDialog('approve')">
            <CheckCircle2 :size="17" /> {{ status === 'suspended' ? 'Reactivate' : 'Approve' }}
          </button>
          <button v-if="status === 'pending'" class="btn btn-soft-danger" type="button" @click="openDialog('reject')"><XCircle :size="17" /> Reject</button>
          <button v-if="status === 'approved'" class="btn btn-outline" type="button" @click="openDialog('suspend')"><Ban :size="17" /> Suspend</button>
          <RouterLink class="btn btn-outline" :to="{ name: 'company-edit', params: { id: company.id } }"><Pencil :size="16" /> Edit</RouterLink>
          <button class="btn btn-outline" type="button" style="color: var(--danger)" aria-label="Delete company" @click="openDialog('delete')"><Trash2 :size="16" /></button>
        </div>
      </div>
      <div v-if="company.rejection_reason && status !== 'approved'" class="alert alert--error" style="margin: 0 14px 12px">
        <div><strong>{{ status === 'suspended' ? 'Suspension note' : 'Rejection reason' }}:</strong> {{ company.rejection_reason }}</div>
      </div>
    </section>

    <div class="grid-2">
      <div class="stack">
        <section class="card">
          <div class="card__head"><h3>Company information</h3></div>
          <div class="card__body">
            <dl class="kv">
              <div><dt>Registered as</dt><dd>{{ company.registration_type?.name || '-' }}</dd></div>
              <div><dt>Vendor type</dt><dd>{{ company.vendor_type?.name || '-' }}</dd></div>
              <div><dt>Origin country</dt><dd>{{ company.origin_country?.name || '-' }}</dd></div>
              <div><dt>VAT / GST number</dt><dd>{{ company.vat_gst_number }}</dd></div>
              <div><dt>Company email</dt><dd>{{ company.email }}</dd></div>
              <div><dt>Company contact number</dt><dd>{{ company.contact_number }}</dd></div>
              <div class="full"><dt>Address</dt><dd>{{ [company.address.address, company.address.landmark, company.address.city, company.address.zip_code].filter(Boolean).join(', ') }}</dd></div>
            </dl>
          </div>
        </section>

        <section class="card">
          <div class="card__head"><h3>Contact person</h3></div>
          <div class="card__body">
            <dl class="kv">
              <div><dt>Name</dt><dd>{{ company.contact.name }}</dd></div>
              <div><dt>Mobile</dt><dd>{{ company.contact.mobile }}</dd></div>
              <div><dt>Email</dt><dd>{{ company.contact.email }}</dd></div>
              <div><dt>WhatsApp</dt><dd>{{ company.contact.whatsapp || '-' }}</dd></div>
              <div class="full"><dt>Support offered to promote the products</dt><dd style="font-weight: 400; white-space: pre-line">{{ company.promotion_support }}</dd></div>
            </dl>
          </div>
        </section>
      </div>

      <div class="stack">
        <section class="card">
          <div class="card__head"><h3>Review</h3></div>
          <div class="card__body">
            <dl class="kv" style="grid-template-columns: 1fr">
              <div><dt>Status</dt><dd><StatusBadge :status="company.status" /></dd></div>
              <div><dt>Reviewed by</dt><dd>{{ company.reviewed_by || 'Not reviewed yet' }}</dd></div>
              <div><dt>Reviewed on</dt><dd>{{ formatDate(company.reviewed_at, true) }}</dd></div>
            </dl>
          </div>
        </section>

        <section class="card">
          <div class="card__head"><h3>Product catalogue (private)</h3><span class="badge badge--plain">{{ company.catalogue?.length || 0 }}</span></div>
          <div class="card__body">
            <p v-if="!company.catalogue?.length" class="muted">No catalogue files uploaded.</p>
            <div v-for="f in company.catalogue" :key="f.id" class="list-item">
              <span class="activity-dot"><FileText :size="16" /></span>
              <div class="grow"><strong :title="f.name">{{ f.name }}</strong><small class="muted">{{ formatBytes(f.size) }}</small></div>
              <button class="btn btn-soft btn-sm btn-icon" type="button" :aria-label="`Download ${f.name}`" @click="downloadFile(f)"><Download :size="15" /></button>
            </div>
          </div>
        </section>
      </div>
    </div>

    <!-- brands -->
    <section class="card">
      <div class="card__head">
        <h3>Brands <span class="badge badge--plain" style="margin-left: 6px">{{ company.brands?.length || 0 }}</span></h3>
        <button class="btn btn-primary btn-sm" type="button" @click="brandModal = { brand: null }"><Plus :size="15" /> Add brand</button>
      </div>
      <div v-if="!approved && company.brands?.length" class="alert alert--info" style="margin: 10px 14px 0">
        Brands stay hidden from customers until this company is approved.
      </div>
      <EmptyState v-if="!company.brands?.length" title="No brands yet" text="Add the brands this company sells.">
        <template #icon><Tags :size="40" /></template>
        <button class="btn btn-primary btn-sm" type="button" @click="brandModal = { brand: null }"><Plus :size="15" /> Add brand</button>
      </EmptyState>
      <div v-else class="table-wrap">
        <table class="table">
          <thead><tr><th>Brand</th><th>Country</th><th>Slug</th><th>On website</th><th /></tr></thead>
          <tbody>
            <tr v-for="b in company.brands" :key="b.id">
              <td>
                <div class="cell-main">
                  <LogoBox :name="b.name" :src="b.logo_thumb_url || b.logo_url" />
                  <div>
                    <strong>{{ b.name }}</strong>
                    <small v-if="b.logo_source === 'company'" title="This brand has no logo of its own, so the company logo is shown">uses company logo</small>
                  </div>
                </div>
              </td>
              <td>{{ b.origin_country?.name || '-' }}</td>
              <td><span class="muted">{{ b.slug }}</span></td>
              <td>
                <label class="switch" :title="approved ? '' : 'Approve the company first'">
                  <input type="checkbox" :checked="b.is_active" :disabled="!approved" :aria-label="`${b.name} visible on website`" @change="toggleBrand(b)" />
                  <span />
                </label>
              </td>
              <td>
                <div class="row-actions">
                  <button class="btn btn-soft btn-sm btn-icon" type="button" :aria-label="`Edit ${b.name}`" @click="brandModal = { brand: b }"><Pencil :size="15" /></button>
                  <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Delete ${b.name}`" :disabled="company.brands.length <= 1" :title="company.brands.length <= 1 ? 'A company needs at least one brand' : 'Delete brand'" @click="brandToDelete = b"><Trash2 :size="15" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <!-- dialogs -->
  <ConfirmDialog v-if="dialog === 'approve'" :title="status === 'suspended' ? 'Reactivate company' : 'Approve company'" confirm-text="Approve" tone="success" :busy="busy" :message="`Approve ${company.name}? Its ${company.brands?.length || 0} brand(s) will become visible on the website.`" @close="dialog = null" @confirm="runReview('approve')" />

  <ConfirmDialog v-if="dialog === 'reject'" title="Reject company" confirm-text="Reject" tone="danger" :busy="busy" @close="dialog = null" @confirm="runReview('reject')">
    <div class="field">
      <label for="reason">Reason <span class="req">*</span></label>
      <textarea id="reason" v-model="reason" class="textarea" :class="{ 'has-error': reasonError }" rows="3" placeholder="Tell the company why the request was rejected" />
      <p v-if="reasonError" class="err">{{ reasonError }}</p>
    </div>
  </ConfirmDialog>

  <ConfirmDialog v-if="dialog === 'suspend'" title="Suspend company" confirm-text="Suspend" tone="danger" :busy="busy" message="The company and all its brands will be hidden from customers. You can reactivate it later." @close="dialog = null" @confirm="runReview('suspend')">
    <div class="field" style="margin-top: 14px">
      <label for="sreason">Note (optional)</label>
      <textarea id="sreason" v-model="reason" class="textarea" rows="2" />
    </div>
  </ConfirmDialog>

  <ConfirmDialog v-if="dialog === 'delete'" title="Delete company" confirm-text="Delete" tone="danger" :busy="busy" :message="`Delete ${company?.name}? Its brands are removed too. A company that already has products cannot be deleted (suspend it instead).`" @close="dialog = null" @confirm="deleteCompany" />

  <ConfirmDialog v-if="brandToDelete" title="Delete brand" confirm-text="Delete" tone="danger" :busy="busy" :message="`Delete the brand ${brandToDelete.name}? A brand that already has products cannot be deleted.`" @close="brandToDelete = null" @confirm="deleteBrand" />

  <BrandModal v-if="brandModal" :company-id="id" :company-name="company?.name" :company-logo="company?.logo_thumb_url || company?.logo_url" :company-approved="approved" :brand="brandModal.brand" :countries="countries" @close="brandModal = null" @saved="onBrandSaved" />
</template>

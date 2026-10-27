<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { FileText, Plus, Trash2, UploadCloud } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useLookupsStore } from '@/stores/lookups.js'
import LogoBox from '@/components/LogoBox.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import { formatBytes } from '@/utils/format.js'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const lookups = useLookupsStore()

const id = computed(() => route.params.id)
const isEdit = computed(() => Boolean(id.value))

const PROMO_MIN = 200
const PROMO_MAX = 500
const FILE_TYPES = ['png', 'jpg', 'jpeg', 'webp', 'pdf']

const options = ref({ registration_types: [], vendor_types: [], countries: [] })
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')
const errors = ref({})
const files = ref([])
const dragOver = ref(false)
const fileInput = ref(null)
const logo = ref(null)          // newly chosen logo file
const logoPreview = ref('')     // preview of the chosen logo, or the current one when editing
const logoInput = ref(null)

const form = reactive({
  name: '', registration_type_id: '', vendor_type_id: '', origin_country_id: '', email: '', contact_number: '',
  vat_gst_number: '', contact_name: '', contact_mobile: '', contact_email: '', contact_whatsapp: '',
  address: '', landmark: '', city: '', zip_code: '', promotion_support: '',
})
const brands = ref([{ name: '', origin_country_id: '' }])
const existingCatalogue = ref([])

// A field's error disappears as soon as the user edits that field.
const clearError = (match) => {
  const next = { ...errors.value }
  Object.keys(next).filter(match).forEach(k => delete next[k])
  errors.value = next
}
watch(() => ({ ...form }), (now, before) => {
  Object.keys(now).filter(k => now[k] !== before[k]).forEach(k => clearError(key => key === k))
})
watch(brands, () => clearError(k => k.startsWith('brands')), { deep: true })
watch(files, () => clearError(k => k.startsWith('catalogue')), { deep: true })

const promoLength = computed(() => form.promotion_support.length)
const promoBad = computed(() => promoLength.value > 0 && (promoLength.value < PROMO_MIN || promoLength.value > PROMO_MAX))
const err = (key) => errors.value[key]?.[0] || ''

onMounted(async () => {
  try {
    options.value = await lookups.load()
    if (isEdit.value) {
      const c = (await api.get(`admin/companies/${id.value}`)).data
      Object.assign(form, {
        name: c.name, registration_type_id: c.registration_type?.id ?? '', vendor_type_id: c.vendor_type?.id ?? '',
        origin_country_id: c.origin_country?.id ?? '', email: c.email, contact_number: c.contact_number,
        vat_gst_number: c.vat_gst_number, contact_name: c.contact.name, contact_mobile: c.contact.mobile,
        contact_email: c.contact.email, contact_whatsapp: c.contact.whatsapp ?? '', address: c.address.address,
        landmark: c.address.landmark ?? '', city: c.address.city, zip_code: c.address.zip_code,
        promotion_support: c.promotion_support,
      })
      existingCatalogue.value = c.catalogue || []
      logoPreview.value = c.logo_thumb_url || c.logo_url || ''
    } else {
      // Sensible defaults so the admin only changes what differs.
      const india = options.value.countries.find(x => x.code === 'IN')
      if (india) form.origin_country_id = india.id
    }
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
})

function pickLogo(e) {
  const f = e.target.files[0]
  e.target.value = ''
  if (!f) return
  const ext = f.name.split('.').pop().toLowerCase()
  if (!['png', 'jpg', 'jpeg', 'webp'].includes(ext)) { errors.value = { ...errors.value, logo: ['The logo must be a PNG, JPG or WEBP image.'] }; return }
  if (f.size > 4 * 1024 * 1024) { errors.value = { ...errors.value, logo: ['The logo must be 4 MB or smaller.'] }; return }
  logo.value = f
  logoPreview.value = URL.createObjectURL(f)
  clearError(k => k === 'logo')
}

function addBrand() { brands.value.push({ name: '', origin_country_id: '' }) }
function removeBrand(i) { brands.value.splice(i, 1) }

function addFiles(list) {
  const rejected = []
  for (const f of list) {
    const ext = f.name.split('.').pop().toLowerCase()
    if (!FILE_TYPES.includes(ext)) rejected.push(`${f.name} (type not allowed)`)
    else if (f.size > 10 * 1024 * 1024) rejected.push(`${f.name} (over 10 MB)`)
    else if (files.value.length >= 10) rejected.push(`${f.name} (max 10 files)`)
    else files.value.push(f)
  }
  if (rejected.length) ui.toast(`Skipped: ${rejected.join(', ')}`, 'error', 6000)
}
function onDrop(e) { dragOver.value = false; addFiles([...e.dataTransfer.files]) }
function onPick(e) { addFiles([...e.target.files]); e.target.value = '' }

function clientValidate() {
  const e = {}
  const need = ['name', 'registration_type_id', 'vendor_type_id', 'origin_country_id', 'email', 'contact_number', 'vat_gst_number',
    'contact_name', 'contact_mobile', 'contact_email', 'address', 'city', 'zip_code', 'promotion_support']
  need.forEach(k => { if (!String(form[k]).trim()) e[k] = ['This field is required.'] })
  if (form.promotion_support.trim() && (promoLength.value < PROMO_MIN || promoLength.value > PROMO_MAX)) {
    e.promotion_support = [`Write between ${PROMO_MIN} and ${PROMO_MAX} characters (now ${promoLength.value}).`]
  }
  if (!isEdit.value) {
    const named = brands.value.filter(b => b.name.trim())
    if (!named.length) e['brands.0.name'] = ['Add at least one brand.']
    if (!logo.value) e.logo = ['The company logo is required.']
  }
  return e
}

async function submit() {
  formError.value = ''
  errors.value = clientValidate()
  if (Object.keys(errors.value).length) {
    formError.value = 'Please fix the highlighted fields.'
    document.querySelector('.has-error')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    return
  }

  const fd = new FormData()
  Object.entries(form).forEach(([k, v]) => { if (String(v).trim() !== '' || isEdit.value) fd.append(k, String(v).trim()) })
  if (!isEdit.value) {
    brands.value.filter(b => b.name.trim()).forEach((b, i) => {
      fd.append(`brands[${i}][name]`, b.name.trim())
      if (b.origin_country_id) fd.append(`brands[${i}][origin_country_id]`, b.origin_country_id)
    })
  } else {
    fd.append('_method', 'PUT')
  }
  if (logo.value) fd.append('logo', logo.value)
  files.value.forEach(f => fd.append('catalogue[]', f))

  saving.value = true
  try {
    const res = await api.postForm(isEdit.value ? `admin/companies/${id.value}` : 'admin/companies', fd)
    ui.toast(isEdit.value ? 'Company updated.' : 'Company created and approved.')
    router.push({ name: 'company', params: { id: res.data.id } })
  } catch (e) {
    if (e.status === 422) {
      errors.value = e.errors
      formError.value = 'Please fix the highlighted fields.'
      await Promise.resolve()
      document.querySelector('.has-error')?.scrollIntoView({ block: 'center', behavior: 'smooth' })
    } else {
      formError.value = e.message
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ isEdit ? 'Edit company' : 'Create new company' }}</h1>
      <div class="crumbs">
        <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> ›
        <RouterLink :to="{ name: 'companies' }">Companies</RouterLink> ›
        <span>{{ isEdit ? 'Edit' : 'New' }}</span>
      </div>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }}</div>
  <section v-else-if="loading" class="card"><TableSkeleton :rows="8" /></section>

  <form v-else class="card" novalidate @submit.prevent="submit">
    <div class="card__body">
      <div v-if="!isEdit" class="alert alert--info">Companies created here are <strong>approved immediately</strong> and their brands go live on the website.</div>
      <div v-if="formError" class="alert alert--error" role="alert">{{ formError }}</div>

      <div class="form-section">
        <h3 class="section-title">Company details</h3>
        <div class="form-grid">
          <div class="field full">
            <label>Company logo <span v-if="!isEdit" class="req">*</span></label>
            <div style="display: flex; gap: 12px; align-items: center">
              <LogoBox :name="form.name || 'Logo'" :src="logoPreview" large :class="{ 'has-error': err('logo') }" />
              <div>
                <button class="btn btn-outline btn-sm" type="button" @click="logoInput.click()">{{ logoPreview ? 'Change logo' : 'Choose logo' }}</button>
                <input ref="logoInput" type="file" accept=".png,.jpg,.jpeg,.webp" hidden @change="pickLogo" />
                <p class="hint">PNG, JPG or WEBP, up to 4 MB. This is also the default logo of all the company's brands.</p>
              </div>
            </div>
            <p v-if="err('logo')" class="err">{{ err('logo') }}</p>
          </div>
          <div class="field full">
            <label for="name">Company name <span class="req">*</span></label>
            <input id="name" v-model="form.name" class="input" :class="{ 'has-error': err('name') }" maxlength="190" />
            <p v-if="err('name')" class="err">{{ err('name') }}</p>
          </div>
          <div class="field">
            <label for="rt">Company registered as <span class="req">*</span></label>
            <select id="rt" v-model="form.registration_type_id" class="select" :class="{ 'has-error': err('registration_type_id') }">
              <option value="" disabled>Select</option>
              <option v-for="o in options.registration_types" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <p v-if="err('registration_type_id')" class="err">{{ err('registration_type_id') }}</p>
          </div>
          <div class="field">
            <label for="vt">Vendor type <span class="req">*</span></label>
            <select id="vt" v-model="form.vendor_type_id" class="select" :class="{ 'has-error': err('vendor_type_id') }">
              <option value="" disabled>Select</option>
              <option v-for="o in options.vendor_types" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <p v-if="err('vendor_type_id')" class="err">{{ err('vendor_type_id') }}</p>
          </div>
          <div class="field">
            <label for="oc">Origin country of company <span class="req">*</span></label>
            <select id="oc" v-model="form.origin_country_id" class="select" :class="{ 'has-error': err('origin_country_id') }">
              <option value="" disabled>Select</option>
              <option v-for="o in options.countries" :key="o.id" :value="o.id">{{ o.name }}</option>
            </select>
            <p v-if="err('origin_country_id')" class="err">{{ err('origin_country_id') }}</p>
          </div>
          <div class="field">
            <label for="gst">VAT / GST number <span class="req">*</span></label>
            <input id="gst" v-model="form.vat_gst_number" class="input" :class="{ 'has-error': err('vat_gst_number') }" maxlength="30" />
            <p v-if="err('vat_gst_number')" class="err">{{ err('vat_gst_number') }}</p>
          </div>
          <div class="field">
            <label for="email">Company email <span class="req">*</span></label>
            <input id="email" v-model="form.email" type="email" class="input" :class="{ 'has-error': err('email') }" />
            <p v-if="err('email')" class="err">{{ err('email') }}</p>
          </div>
          <div class="field">
            <label for="cn">Company contact number <span class="req">*</span></label>
            <input id="cn" v-model="form.contact_number" class="input" :class="{ 'has-error': err('contact_number') }" placeholder="+91 9876543210" />
            <p v-if="err('contact_number')" class="err">{{ err('contact_number') }}</p>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="section-title">Contact person</h3>
        <div class="form-grid">
          <div class="field">
            <label for="cname">Contact name <span class="req">*</span></label>
            <input id="cname" v-model="form.contact_name" class="input" :class="{ 'has-error': err('contact_name') }" />
            <p v-if="err('contact_name')" class="err">{{ err('contact_name') }}</p>
          </div>
          <div class="field">
            <label for="cmob">Mobile number <span class="req">*</span></label>
            <input id="cmob" v-model="form.contact_mobile" class="input" :class="{ 'has-error': err('contact_mobile') }" />
            <p v-if="err('contact_mobile')" class="err">{{ err('contact_mobile') }}</p>
          </div>
          <div class="field">
            <label for="cmail">Contact email <span class="req">*</span></label>
            <input id="cmail" v-model="form.contact_email" type="email" class="input" :class="{ 'has-error': err('contact_email') }" />
            <p v-if="err('contact_email')" class="err">{{ err('contact_email') }}</p>
          </div>
          <div class="field">
            <label for="cwa">WhatsApp number</label>
            <input id="cwa" v-model="form.contact_whatsapp" class="input" :class="{ 'has-error': err('contact_whatsapp') }" />
            <p v-if="err('contact_whatsapp')" class="err">{{ err('contact_whatsapp') }}</p>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="section-title">Company address</h3>
        <div class="form-grid">
          <div class="field full">
            <label for="addr">Address <span class="req">*</span></label>
            <input id="addr" v-model="form.address" class="input" :class="{ 'has-error': err('address') }" />
            <p v-if="err('address')" class="err">{{ err('address') }}</p>
          </div>
          <div class="field">
            <label for="land">Landmark</label>
            <input id="land" v-model="form.landmark" class="input" />
          </div>
          <div class="field">
            <label for="city">City <span class="req">*</span></label>
            <input id="city" v-model="form.city" class="input" :class="{ 'has-error': err('city') }" />
            <p v-if="err('city')" class="err">{{ err('city') }}</p>
          </div>
          <div class="field">
            <label for="zip">ZIP / PIN code <span class="req">*</span></label>
            <input id="zip" v-model="form.zip_code" class="input" :class="{ 'has-error': err('zip_code') }" maxlength="12" />
            <p v-if="err('zip_code')" class="err">{{ err('zip_code') }}</p>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="section-title">Promotion support</h3>
        <div class="field">
          <label for="promo">What support can the company provide to help us promote its products? <span class="req">*</span></label>
          <textarea id="promo" v-model="form.promotion_support" class="textarea" :class="{ 'has-error': err('promotion_support') }" rows="4" />
          <p v-if="err('promotion_support')" class="err">{{ err('promotion_support') }}</p>
          <p class="char-count" :class="{ bad: promoBad }">{{ promoLength }} / {{ PROMO_MAX }} (minimum {{ PROMO_MIN }})</p>
        </div>
      </div>

      <div v-if="!isEdit" class="form-section">
        <h3 class="section-title">Brands</h3>
        <p v-if="err('brands.0.name') || err('brands')" class="err" style="margin-bottom: 8px">{{ err('brands.0.name') || err('brands') }}</p>
        <div class="brand-rows">
          <div v-for="(b, i) in brands" :key="i" class="brand-row">
            <div class="form-grid" style="gap: 10px">
              <div class="field">
                <input v-model="b.name" class="input" :class="{ 'has-error': err(`brands.${i}.name`) && i > 0 }" :placeholder="`Brand name ${i + 1}`" :aria-label="`Brand name ${i + 1}`" maxlength="160" />
                <p v-if="err(`brands.${i}.name`) && i > 0" class="err">{{ err(`brands.${i}.name`) }}</p>
              </div>
              <div class="field">
                <select v-model="b.origin_country_id" class="select" :aria-label="`Country of origin of brand ${i + 1}`">
                  <option value="">Country of origin (optional)</option>
                  <option v-for="o in options.countries" :key="o.id" :value="o.id">{{ o.name }}</option>
                </select>
              </div>
            </div>
            <button class="btn btn-soft-danger btn-icon" type="button" :disabled="brands.length === 1" :aria-label="`Remove brand ${i + 1}`" @click="removeBrand(i)"><Trash2 :size="16" /></button>
          </div>
        </div>
        <button class="btn btn-soft btn-sm" type="button" style="margin-top: 12px" @click="addBrand"><Plus :size="15" /> Add another brand</button>
      </div>

      <div class="form-section">
        <h3 class="section-title">Product catalogue (optional) <span class="muted" style="font-weight: 400">price lists or brochures for the team; kept private, never shown on the website</span></h3>
        <div v-if="existingCatalogue.length" class="file-list" style="margin: 0 0 12px">
          <div v-for="m in existingCatalogue" :key="m.id" class="file-row">
            <FileText :size="18" /><span class="name">{{ m.name }}</span><small>{{ formatBytes(m.size) }} · already uploaded</small>
          </div>
        </div>
        <div class="dropzone" :class="{ over: dragOver }" role="button" tabindex="0" @click="fileInput.click()" @keydown.enter="fileInput.click()" @dragover.prevent="dragOver = true" @dragleave="dragOver = false" @drop.prevent="onDrop">
          <UploadCloud :size="24" style="margin: 0 auto 4px" />
          <div><strong>Click to upload</strong> or drag files here</div>
          <small>PNG, JPG, WEBP or PDF · up to 10 MB each · max 10 files</small>
          <input ref="fileInput" type="file" multiple hidden accept=".png,.jpg,.jpeg,.webp,.pdf" @change="onPick" />
        </div>
        <p v-if="err('catalogue') || err('catalogue.0')" class="err" style="margin-top: 6px">{{ err('catalogue') || err('catalogue.0') }}</p>
        <div v-if="files.length" class="file-list">
          <div v-for="(f, i) in files" :key="f.name + i" class="file-row">
            <FileText :size="18" /><span class="name">{{ f.name }}</span><small>{{ formatBytes(f.size) }}</small>
            <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :aria-label="`Remove ${f.name}`" @click="files.splice(i, 1)"><Trash2 :size="14" /></button>
          </div>
        </div>
      </div>
    </div>

    <div class="modal__foot" style="border-top: 1px solid var(--border-light); padding: 10px 14px">
      <RouterLink class="btn btn-outline" :to="isEdit ? { name: 'company', params: { id } } : { name: 'companies' }">Cancel</RouterLink>
      <button class="btn btn-primary" type="submit" :disabled="saving">
        <span v-if="saving" class="spinner" /> {{ isEdit ? 'Save changes' : 'Create company' }}
      </button>
    </div>
  </form>
</template>

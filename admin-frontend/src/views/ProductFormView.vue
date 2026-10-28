<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronDown, ChevronRight, Plus, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import ProductImages from '@/components/ProductImages.vue'
import RelatedProducts from '@/components/RelatedProducts.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import VariantEditor from '@/components/VariantEditor.vue'
import { money } from '@/utils/format.js'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const catalog = useCatalogStore()

const id = computed(() => (route.params.id ? Number(route.params.id) : null))
const isEdit = computed(() => id.value !== null)

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const formError = ref('')
const errors = ref({})
const product = ref(null)          // the saved product (edit mode)
const images = ref([])             // the server's pictures of the product and of its options (edit mode)
const pendingProduct = ref([])     // pictures chosen for the product gallery before it is saved: [{ key, file, preview }]
const related = ref([])            // [{ id, name, sku, thumb_url }]

const form = reactive({
  company_id: '', brand_id: '', category_id: '', sub_category_id: '',
  name: '', sku: '', short_description: '', description: '', badge_text: '', hsn_code: '', gst_rate: 18,
  country_of_origin: '', warranty_text: '', min_order_qty: 1, max_order_qty: 10,
  is_returnable: true, return_days: 10, is_cod_available: true, is_professional_only: false, status: 'draft',
  meta_title: '', meta_description: '',
})
const channelState = reactive({})  // code -> { enabled, active }
const tagIds = ref([])
const tabs = reactive({})          // key -> { content, format, manual_url }
const openTabs = ref(new Set())

const channels = computed(() => catalog.options.channels)
const enabledChannels = computed(() => channels.value.filter(c => channelState[c.code]?.enabled))
const symbol = computed(() => catalog.currency.symbol)

const blankStock = () => Object.fromEntries(channels.value.map(c => [c.code, 0]))
const blankVariant = (over = {}) => ({
  id: null, name: '', sku: '', mrp: '', selling_price: '', reward_points: 0, expiry_date: '', weight_grams: '', is_active: true, is_default: false,
  price_tiers: [], pending: [], stock: blankStock(), ...over,
})
const variants = ref([])   // the options: every product has at least one; the cheapest one gives the starting price

// ---- dropdowns that depend on each other (the saved company / brand / category stay selectable even if switched off since)
const companyOptions = computed(() => {
  const list = [...catalog.options.companies]
  const p = product.value
  if (p && !list.some(c => c.id === p.company?.id)) list.push({ id: p.company.id, name: `${p.company.name} (not approved)`, brands: [] })
  return list
})
const brandOptions = computed(() => {
  const list = [...(companyOptions.value.find(c => c.id === Number(form.company_id))?.brands ?? [])]
  const p = product.value
  if (p && p.brand.company_id === Number(form.company_id) && !list.some(b => b.id === p.brand.id)) list.push({ id: p.brand.id, name: `${p.brand.name} (inactive)` })
  return list
})
const categoryOptions = computed(() => {
  const list = [...catalog.categories]
  const p = product.value
  if (p && !list.some(c => c.id === p.category.id)) list.push({ id: p.category.id, name: `${p.category.name} (inactive)`, sub_categories: [] })
  return list
})
const subOptions = computed(() => {
  const category = categoryOptions.value.find(c => c.id === Number(form.category_id))
  if (!category) return []
  const list = category.sub_categories.flatMap(s => [{ id: s.id, label: s.name }, ...s.children.map(ch => ({ id: ch.id, label: `— ${ch.name}` }))])
  const p = product.value
  if (p?.sub_category && p.category.id === Number(form.category_id) && !list.some(s => s.id === p.sub_category.id)) list.push({ id: p.sub_category.id, label: `${p.sub_category.name} (inactive)` })
  return list
})
watch(() => form.company_id, () => { if (!brandOptions.value.some(b => b.id === Number(form.brand_id))) form.brand_id = '' })
watch(() => form.category_id, () => { if (!subOptions.value.some(s => s.id === Number(form.sub_category_id))) form.sub_category_id = '' })

const num = (v) => (v === '' || v === null || v === undefined ? NaN : Number(v))

// "Starting at": the cheapest active option
const startingPrice = computed(() => {
  const prices = variants.value.filter(v => v.is_active && !Number.isNaN(num(v.selling_price))).map(v => num(v.selling_price))
  return prices.length ? Math.min(...prices) : null
})

// A product with a single option: that option follows the product name and SKU until the admin types its own.
watch(() => form.name, (now, before) => {
  const v = variants.value[0]
  if (variants.value.length === 1 && v && (v.name === '' || v.name === before)) v.name = now
})
watch(() => form.sku, (now, before) => {
  const v = variants.value[0]
  if (variants.value.length === 1 && v && (v.sku === '' || v.sku === before)) v.sku = now
})

// ---- errors
const err = (key) => errors.value[key]?.[0] || ''
const clearError = (...keys) => { const next = { ...errors.value }; keys.forEach(k => delete next[k]); errors.value = next }

onMounted(async () => {
  try {
    await catalog.load()
    channels.value.forEach(c => { channelState[c.code] = { enabled: c.code === 'standard', active: true } })
    catalog.options.content_tabs.forEach(t => { tabs[t.key] = { content: '', format: 'paragraphs', manual_url: '' } })
    if (isEdit.value) {
      applyProduct((await api.get(`admin/products/${id.value}`)).data)
    } else {
      variants.value = [blankVariant({ is_default: true })]
    }
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
})

function applyProduct(p) {
  product.value = p
  images.value = p.images
  pendingProduct.value = []
  related.value = p.related.map(r => ({ id: r.id, name: r.name, sku: r.sku, thumb_url: r.thumb_url }))
  Object.assign(form, {
    company_id: p.company?.id ?? '', brand_id: p.brand?.id ?? '', category_id: p.category?.id ?? '', sub_category_id: p.sub_category?.id ?? '',
    name: p.name, sku: p.sku, short_description: p.short_description ?? '', description: p.description ?? '', badge_text: p.badge_text ?? '',
    hsn_code: p.hsn_code ?? '', gst_rate: p.gst_rate, country_of_origin: p.country_of_origin ?? '', warranty_text: p.warranty_text ?? '',
    min_order_qty: p.min_order_qty, max_order_qty: p.max_order_qty, is_returnable: p.is_returnable, return_days: p.return_days ?? 10,
    is_cod_available: p.is_cod_available, is_professional_only: p.is_professional_only, status: p.status,
    meta_title: p.meta_title ?? '', meta_description: p.meta_description ?? '',
  })
  const toRow = (v) => blankVariant({
    id: v.id, name: v.name, sku: v.sku, mrp: v.mrp, selling_price: v.selling_price, reward_points: v.reward_points,
    expiry_date: v.expiry_date ?? '', weight_grams: v.weight_grams ?? '', is_active: v.is_active, is_default: v.is_default,
    price_tiers: v.price_tiers.map(t => ({ min_qty: t.min_qty, price: t.price })), stock: { ...blankStock(), ...v.stock },
  })
  variants.value = p.variants.map(toRow)
  channels.value.forEach((c) => {
    const saved = p.channels.find(x => x.code === c.code)
    channelState[c.code] = { enabled: Boolean(saved), active: saved ? saved.is_active : true }
  })
  tagIds.value = [...p.tag_ids]
  Object.keys(tabs).forEach((k) => { tabs[k] = { content: '', format: 'paragraphs', manual_url: '' } })
  p.content_tabs.forEach((t) => { tabs[t.tab_key] = { content: t.content ?? '', format: t.format || 'paragraphs', manual_url: t.manual_url ?? '' } })
  openTabs.value = new Set(p.content_tabs.map(t => t.tab_key))
}

// ---- options
const addVariant = () => variants.value.push(blankVariant())

// The default option is the one preselected on the product page. It is always an active option.
const activeCount = computed(() => variants.value.filter(v => v.is_active).length)
function makeDefault(i) { variants.value.forEach((v, j) => { v.is_default = j === i }) }
function ensureDefault() {
  const current = variants.value.find(v => v.is_default && v.is_active)
  if (!current) { const first = variants.value.find(v => v.is_active); variants.value.forEach(v => { v.is_default = v === first }) }
}
function onActiveChange(v) { clearError('variants'); ensureDefault(); return v }
function removeVariant(i) { variants.value.splice(i, 1); ensureDefault() }

const toggleTab = (key) => {
  const next = new Set(openTabs.value)
  next.has(key) ? next.delete(key) : next.add(key)
  openTabs.value = next
}
const tabFilled = (key) => Boolean(tabs[key]?.content?.trim() || tabs[key]?.manual_url?.trim())

// ---- save
function clientValidate() {
  const e = {}
  const need = (key, label) => { if (!String(form[key]).trim()) e[key] = [`${label} is required.`] }
  need('company_id', 'Company'); need('brand_id', 'Brand'); need('category_id', 'Category'); need('name', 'Product name'); need('sku', 'SKU')
  if (Number(form.max_order_qty) < Number(form.min_order_qty)) e.max_order_qty = ['The maximum cannot be lower than the minimum.']
  if (form.is_returnable && !(Number(form.return_days) >= 1)) e.return_days = ['Enter the number of return days.']

  const checkRow = (row, prefix) => {
    if (!row.name.trim()) e[`${prefix}.name`] = ['Name the option (for example "Maarc Pure Eugenol (15ml)").']
    if (!row.sku.trim()) e[`${prefix}.sku`] = ['Enter a SKU.']
    if (!(num(row.mrp) > 0)) e[`${prefix}.mrp`] = ['Enter the MRP.']
    if (Number.isNaN(num(row.selling_price))) e[`${prefix}.selling_price`] = ['Enter the selling price.']
    else if (num(row.selling_price) > num(row.mrp)) e[`${prefix}.selling_price`] = ['The selling price cannot be higher than the MRP.']

    row.price_tiers = row.price_tiers.filter(t => String(t.min_qty).trim() !== '' || String(t.price).trim() !== '') // empty rows are ignored
    const seen = new Set()
    row.price_tiers.forEach((t, j) => {
      const at = `${prefix}.price_tiers.${j}`
      if (!(Number.isInteger(num(t.min_qty)) && num(t.min_qty) >= 2)) e[`${at}.min_qty`] = ['Quantity must be 2 or more.']
      else if (seen.has(num(t.min_qty))) e[`${at}.min_qty`] = ['This quantity is already used.']
      seen.add(num(t.min_qty))
      if (Number.isNaN(num(t.price))) e[`${at}.price`] = ['Enter the price.']
      else if (num(t.price) >= num(row.selling_price)) e[`${at}.price`] = ['Must be lower than the selling price.']
    })
    let previous = num(row.selling_price)
    row.price_tiers.map((t, j) => ({ t, j })).sort((a, b) => num(a.t.min_qty) - num(b.t.min_qty)).forEach(({ t, j }) => {
      const at = `${prefix}.price_tiers.${j}.price`
      if (!e[at] && !Number.isNaN(num(t.price))) {
        if (num(t.price) >= previous) e[at] = ['A larger quantity must be cheaper than the smaller one.']
        previous = num(t.price)
      }
    })
  }
  if (!variants.value.length) e.variants = ['Add at least one option.']
  else if (!activeCount.value) e.variants = ['At least one option must be active, otherwise the product has nothing to sell.']
  variants.value.forEach((v, i) => checkRow(v, `variants.${i}`))
  if (!enabledChannels.value.length) e.channels = ['Choose at least one sales channel.']
  return e
}

function variantPayload(v, index) {
  const stock = {}
  enabledChannels.value.forEach((c) => { stock[c.code] = Number(v.stock[c.code]) || 0 })
  return {
    id: v.id, name: v.name.trim(), sku: v.sku.trim() || undefined, is_default: Boolean(v.is_default), mrp: v.mrp, selling_price: v.selling_price,
    reward_points: Number(v.reward_points) || 0, expiry_date: v.expiry_date || null, weight_grams: String(v.weight_grams).trim() === '' ? null : Number(v.weight_grams),
    is_active: v.is_active, sort_order: index, stock,
    price_tiers: v.price_tiers.map(t => ({ min_qty: Number(t.min_qty), price: Number(t.price) })),
  }
}

function buildPayload() {
  const text = (v) => (String(v).trim() === '' ? null : String(v).trim())
  return {
    company_id: Number(form.company_id), brand_id: Number(form.brand_id), category_id: Number(form.category_id),
    sub_category_id: form.sub_category_id ? Number(form.sub_category_id) : null,
    name: form.name.trim(), sku: form.sku.trim(), short_description: text(form.short_description), description: text(form.description),
    badge_text: text(form.badge_text), hsn_code: text(form.hsn_code), gst_rate: form.gst_rate === '' ? null : Number(form.gst_rate),
    country_of_origin: text(form.country_of_origin), warranty_text: text(form.warranty_text),
    min_order_qty: Number(form.min_order_qty) || 1, max_order_qty: Number(form.max_order_qty) || 1,
    is_returnable: form.is_returnable, return_days: form.is_returnable ? Number(form.return_days) : null,
    is_cod_available: form.is_cod_available, is_professional_only: form.is_professional_only,
    status: form.status, meta_title: text(form.meta_title), meta_description: text(form.meta_description),
    variants: variants.value.map((v, i) => variantPayload(v, i)),
    tag_ids: tagIds.value,
    related_ids: related.value.map(r => r.id),
    channels: enabledChannels.value.map(c => ({ code: c.code, is_active: channelState[c.code].active })),
    content_tabs: Object.entries(tabs).map(([tab_key, t]) => ({ tab_key, format: t.format, content: t.content.trim() || null, manual_url: t.manual_url.trim() || null })),
  }
}

/** Pictures chosen before the save (product gallery, and the galleries of new options): one upload per gallery. */
function collectUploads(saved) {
  const jobs = []
  if (pendingProduct.value.length) jobs.push({ files: pendingProduct.value.map(p => p.file), variantId: null })
  variants.value.forEach((v, i) => {
    if (v.pending.length && saved.variants[i]) jobs.push({ files: v.pending.map(p => p.file), variantId: saved.variants[i].id })
  })
  return jobs
}

/** @returns {Promise<{ list: ?Array, failed: string }>} the server's latest picture list, and an error text when an upload failed */
async function runUploads(productId, jobs) {
  let list = null
  for (const job of jobs) {
    try {
      const fd = new FormData()
      job.files.forEach(f => fd.append('images[]', f))
      if (job.variantId !== null) fd.append('variant_id', job.variantId)
      list = (await api.postForm(`admin/products/${productId}/images`, fd)).data
    } catch (e) {
      return { list, failed: e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message }
    }
  }
  return { list, failed: '' }
}

async function submit() {
  formError.value = ''
  errors.value = clientValidate()
  if (Object.keys(errors.value).length) return showErrors()

  saving.value = true
  try {
    if (isEdit.value) {
      const res = await api.put(`admin/products/${id.value}`, buildPayload())
      const jobs = collectUploads(res.data)
      applyProduct(res.data)
      const { list, failed } = await runUploads(id.value, jobs)
      if (list) images.value = list
      if (failed) ui.toast(`Product saved, but some pictures were not uploaded (${failed}).`, 'error', 7000)
      else ui.toast('Product saved.')
    } else {
      const res = await api.post('admin/products', buildPayload())
      const { failed } = await runUploads(res.data.id, collectUploads(res.data))
      if (failed) ui.toast(`Product added, but some pictures were not uploaded (${failed}). Add them from the edit page.`, 'error', 7000)
      else ui.toast('Product added.')
      router.replace({ name: 'products' })
    }
  } catch (e) {
    if (e.status === 422) {
      errors.value = e.errors
      showErrors(e.message)
    } else {
      formError.value = e.message
    }
  } finally {
    saving.value = false
  }
}

function showErrors(message) {
  formError.value = message && message !== 'The given data was invalid.' ? message : 'Please fix the highlighted fields.'
  requestAnimationFrame(() => document.querySelector('.has-error, .err')?.scrollIntoView({ block: 'center', behavior: 'smooth' }))
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>{{ isEdit ? 'Edit product' : 'Add product' }}</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <RouterLink :to="{ name: 'products' }">Products</RouterLink> › <span>{{ isEdit ? (product?.name || 'Edit') : 'New' }}</span></div>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }}</div>
  <div v-else-if="loading" class="card"><TableSkeleton :rows="9" /></div>

  <form v-else novalidate @submit.prevent="submit">
    <div v-if="formError" class="alert alert--error" role="alert">{{ formError }}</div>

    <div class="pf-layout">
      <div class="pf-col">
        <!-- ================= Basic ================= -->
        <section id="basic" class="card pf-card">
          <div class="card__head"><h2>Basic details</h2></div>
          <div class="card__body">
            <div class="form-grid">
              <div class="field">
                <label for="company">Company <span class="req">*</span></label>
                <select id="company" v-model="form.company_id" class="select" :class="{ 'has-error': err('company_id') }" @change="clearError('company_id', 'brand_id')">
                  <option value="">Select company</option>
                  <option v-for="c in companyOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="err('company_id')" class="err">{{ err('company_id') }}</p>
              </div>
              <div class="field">
                <label for="brand">Brand <span class="req">*</span></label>
                <select id="brand" v-model="form.brand_id" class="select" :disabled="!form.company_id" :class="{ 'has-error': err('brand_id') }" @change="clearError('brand_id')">
                  <option value="">{{ form.company_id ? 'Select brand' : 'Choose a company first' }}</option>
                  <option v-for="b in brandOptions" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
                <p v-if="err('brand_id')" class="err">{{ err('brand_id') }}</p>
                <p v-else class="hint">Only the brands of the selected company.</p>
              </div>
              <div class="field">
                <label for="category">Category <span class="req">*</span></label>
                <select id="category" v-model="form.category_id" class="select" :class="{ 'has-error': err('category_id') }" @change="clearError('category_id', 'sub_category_id')">
                  <option value="">Select category</option>
                  <option v-for="c in categoryOptions" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <p v-if="err('category_id')" class="err">{{ err('category_id') }}</p>
              </div>
              <div class="field">
                <label for="subcategory">Sub-category</label>
                <select id="subcategory" v-model="form.sub_category_id" class="select" :disabled="!subOptions.length" :class="{ 'has-error': err('sub_category_id') }" @change="clearError('sub_category_id')">
                  <option value="">{{ form.category_id && !subOptions.length ? 'No sub-categories' : 'None' }}</option>
                  <option v-for="s in subOptions" :key="s.id" :value="s.id">{{ s.label }}</option>
                </select>
                <p v-if="err('sub_category_id')" class="err">{{ err('sub_category_id') }}</p>
              </div>
              <div class="field full">
                <label for="name">Product name <span class="req">*</span></label>
                <input id="name" v-model="form.name" class="input" maxlength="255" :class="{ 'has-error': err('name') }" @input="clearError('name')" />
                <p v-if="err('name')" class="err">{{ err('name') }}</p>
                <p v-else-if="product" class="hint">Page address: /product/{{ product.slug }} (stays the same when you rename).</p>
              </div>
              <div class="field">
                <label for="sku">SKU <span class="req">*</span></label>
                <input id="sku" v-model="form.sku" class="input" maxlength="80" :class="{ 'has-error': err('sku') }" @input="clearError('sku', 'variants.0.sku')" />
                <p v-if="err('sku')" class="err">{{ err('sku') || err('variants.0.sku') }}</p>
              </div>
              <div class="field">
                <label for="badge">Badge text</label>
                <input id="badge" v-model="form.badge_text" class="input" maxlength="40" placeholder="e.g. MADE IN USA" :class="{ 'has-error': err('badge_text') }" />
                <p v-if="err('badge_text')" class="err">{{ err('badge_text') }}</p>
              </div>
              <div class="field full">
                <label for="short">Short description</label>
                <textarea id="short" v-model="form.short_description" class="textarea" rows="2" maxlength="500" :class="{ 'has-error': err('short_description') }" />
                <p v-if="err('short_description')" class="err">{{ err('short_description') }}</p>
              </div>
              <div class="field full">
                <label for="desc">Description</label>
                <textarea id="desc" v-model="form.description" class="textarea" rows="5" :class="{ 'has-error': err('description') }" />
                <p v-if="err('description')" class="err">{{ err('description') }}</p>
              </div>
            </div>
            <div class="form-grid-4">
              <div class="field"><label for="hsn">HS code</label><input id="hsn" v-model="form.hsn_code" class="input" maxlength="12" placeholder="Customs / tax code" /></div>
              <div class="field">
                <label for="gst">Sales tax %</label>
                <input id="gst" v-model="form.gst_rate" class="input" type="number" min="0" max="99.99" step="0.01" :class="{ 'has-error': err('gst_rate') }" />
                <p v-if="err('gst_rate')" class="err">{{ err('gst_rate') }}</p>
              </div>
              <div class="field"><label for="origin">Country of origin</label><input id="origin" v-model="form.country_of_origin" class="input" maxlength="80" /></div>
              <div class="field"><label for="warranty">Warranty</label><input id="warranty" v-model="form.warranty_text" class="input" maxlength="255" placeholder="e.g. 1 year" /></div>
              <div class="field">
                <label for="minq">Min order qty</label>
                <input id="minq" v-model="form.min_order_qty" class="input" type="number" min="1" :class="{ 'has-error': err('min_order_qty') }" />
                <p v-if="err('min_order_qty')" class="err">{{ err('min_order_qty') }}</p>
              </div>
              <div class="field">
                <label for="maxq">Max order qty</label>
                <input id="maxq" v-model="form.max_order_qty" class="input" type="number" min="1" :class="{ 'has-error': err('max_order_qty') }" @input="clearError('max_order_qty')" />
                <p v-if="err('max_order_qty')" class="err">{{ err('max_order_qty') }}</p>
              </div>
              <div v-if="form.is_returnable" class="field">
                <label for="returndays">Return period (days)</label>
                <input id="returndays" v-model="form.return_days" class="input" type="number" min="1" max="365" :class="{ 'has-error': err('return_days') }" @input="clearError('return_days')" />
                <p v-if="err('return_days')" class="err">{{ err('return_days') }}</p>
                <p v-else class="hint">Shown as "10-Days Returnable".</p>
              </div>
            </div>
            <div style="display: flex; gap: 22px; flex-wrap: wrap">
              <label style="display: flex; gap: 8px; align-items: center"><span class="switch"><input v-model="form.is_returnable" type="checkbox" /><span /></span> Returnable</label>
              <label style="display: flex; gap: 8px; align-items: center"><span class="switch"><input v-model="form.is_cod_available" type="checkbox" /><span /></span> Cash on delivery</label>
              <label style="display: flex; gap: 8px; align-items: center"><span class="switch"><input v-model="form.is_professional_only" type="checkbox" /><span /></span> Professionals only</label>
            </div>
          </div>
        </section>

        <!-- ================= Pricing ================= -->
        <section id="pricing" class="card pf-card">
          <div class="card__head">
            <h2>Options and pricing</h2>
            <span v-if="startingPrice !== null" class="off-badge" data-testid="starting-price">Starting at {{ money(startingPrice) }}</span>
          </div>
          <div class="card__body">
            <p v-if="err('variants')" class="err">{{ err('variants') }}</p>
            <p class="muted" style="margin: 0">
              A product is a group of options, and customers add an option to the cart. Even a product with one model has one option.
              Each option has its own name, SKU, price, stock and pictures, like "Maarc Pure Eugenol (15ml)" and "Maarc Pure Eugenol (30ml)". The cheapest option gives the starting price.
            </p>
            <div v-for="(v, i) in variants" :key="v.id ?? `n${i}`" class="option-card">
              <div class="option-card__head">
                <strong>Option {{ i + 1 }}<template v-if="v.name"> - {{ v.name }}</template></strong>
                <span v-if="v.is_default" class="badge badge--plain" title="Preselected on the product page">Default</span>
                <button v-else-if="v.is_active" class="btn btn-outline btn-sm" type="button" :aria-label="`Make option ${i + 1} the default`" @click="makeDefault(i)">Make default</button>
                <label style="display: flex; gap: 6px; align-items: center" :title="v.is_active && activeCount < 2 ? 'The only active option cannot be switched off' : ''">
                  <span class="switch"><input v-model="v.is_active" type="checkbox" :disabled="v.is_active && activeCount < 2" :aria-label="`Option ${i + 1} active`" @change="onActiveChange(v)" /><span /></span> Active
                </label>
                <button class="btn btn-soft-danger btn-sm btn-icon" type="button" :disabled="variants.length < 2" :title="variants.length < 2 ? 'A product needs at least one option' : 'Remove option'" :aria-label="`Remove option ${i + 1}`" @click="removeVariant(i)"><Trash2 :size="14" /></button>
              </div>
              <div class="option-card__body">
                <VariantEditor
                  :row="v" :prefix="`variants.${i}`" :label="`Option ${i + 1}`" :errors="errors" :product-id="id" :images="images" :channels="enabledChannels"
                  @clear="clearError(...$event)" @images="images = $event"
                />
              </div>
            </div>
            <div><button class="btn btn-outline btn-sm" type="button" @click="addVariant"><Plus :size="15" /> Add option</button></div>
          </div>
        </section>

        <!-- ================= Sales channels ================= -->
        <section id="stock" class="card pf-card">
          <div class="card__head"><h2>Sales channels</h2></div>
          <div class="card__body">
            <p v-if="err('channels')" class="err">{{ err('channels') }}</p>
            <div v-for="c in channels" :key="c.code" class="chan-row" :class="{ 'is-off': !channelState[c.code]?.enabled }">
              <label class="switch"><input v-model="channelState[c.code].enabled" type="checkbox" :aria-label="`Sell on ${c.name}`" :data-testid="`channel-${c.code}`" @change="clearError('channels')" /><span /></label>
              <div class="grow">
                <strong style="font-weight: 600">{{ c.name }}</strong>
                <div class="muted">{{ c.requires_login ? 'Only logged-in customers see it. Has its own stock.' : 'Visible to everyone.' }}</div>
              </div>
              <label v-if="channelState[c.code]?.enabled" style="display: flex; align-items: center; gap: 8px">
                <span class="switch"><input v-model="channelState[c.code].active" type="checkbox" :aria-label="`${c.name} active`" /><span /></span> Active on the website
              </label>
              <span v-else class="muted">Not sold here</span>
            </div>
            <p class="muted" style="margin: 0">The quantity is set inside each option (above): every option has its own stock in every channel you switch on here. The price is the same on both channels.</p>
          </div>
        </section>

        <!-- ================= Content tabs ================= -->
        <section id="content" class="card pf-card">
          <div class="card__head"><h2>Content tabs</h2><span class="muted">Shown as tabs on the product page. Empty tabs are not shown.</span></div>
          <div class="card__body">
            <div v-for="t in catalog.options.content_tabs" :key="t.key" class="tab-block">
              <button type="button" :aria-expanded="openTabs.has(t.key)" @click="toggleTab(t.key)">
                <span>{{ t.label }}<i v-if="tabFilled(t.key)" class="filled" title="Has content" /></span>
                <ChevronDown v-if="openTabs.has(t.key)" :size="16" /><ChevronRight v-else :size="16" />
              </button>
              <div v-if="openTabs.has(t.key)" class="tab-body">
                <div class="form-grid">
                  <div class="field">
                    <label :for="`fmt-${t.key}`">Format</label>
                    <select :id="`fmt-${t.key}`" v-model="tabs[t.key].format" class="select">
                      <option value="paragraphs">Paragraphs</option>
                      <option value="bullets">Bullet points (one per line)</option>
                      <option value="table">Table (label: value per line)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label :for="`url-${t.key}`">Manual / PDF link</label>
                    <input :id="`url-${t.key}`" v-model="tabs[t.key].manual_url" class="input" type="url" placeholder="https://..." :class="{ 'has-error': err(`content_tabs.${Object.keys(tabs).indexOf(t.key)}.manual_url`) }" />
                    <p v-if="err(`content_tabs.${Object.keys(tabs).indexOf(t.key)}.manual_url`)" class="err">{{ err(`content_tabs.${Object.keys(tabs).indexOf(t.key)}.manual_url`) }}</p>
                  </div>
                </div>
                <textarea v-model="tabs[t.key].content" class="textarea" rows="4" :aria-label="`${t.label} content`" />
              </div>
            </div>
          </div>
        </section>

        <!-- ================= SEO ================= -->
        <section id="seo" class="card pf-card">
          <div class="card__head"><h2>Search engines</h2><span class="muted">Optional. Used for the page title and description in Google.</span></div>
          <div class="card__body">
            <div class="field">
              <label for="mtitle">Page title</label>
              <input id="mtitle" v-model="form.meta_title" class="input" maxlength="255" :class="{ 'has-error': err('meta_title') }" />
              <p v-if="err('meta_title')" class="err">{{ err('meta_title') }}</p>
            </div>
            <div class="field">
              <label for="mdesc">Page description</label>
              <textarea id="mdesc" v-model="form.meta_description" class="textarea" rows="2" maxlength="255" :class="{ 'has-error': err('meta_description') }" />
              <p v-if="err('meta_description')" class="err">{{ err('meta_description') }}</p>
            </div>
          </div>
        </section>
      </div>

      <!-- ================= right column ================= -->
      <div class="pf-col">
        <section class="card pf-card">
          <div class="card__head"><h2>Status</h2></div>
          <div class="card__body">
            <div class="field">
              <label for="status">Status</label>
              <select id="status" v-model="form.status" class="select" :class="{ 'has-error': err('status') }">
                <option v-for="s in catalog.options.statuses" :key="s" :value="s">{{ s[0].toUpperCase() + s.slice(1) }}</option>
              </select>
              <p class="hint">Only <strong>Active</strong> products show on the website, and only when their brand, category and sales channel are on too.</p>
            </div>
          </div>
        </section>

        <section id="tags" class="card pf-card">
          <div class="card__head"><h2>Tags</h2></div>
          <div class="card__body">
            <p class="muted" style="margin: 0">Tags decide the website sections: best seller, hot selling, new arrival ...</p>
            <div class="tag-pick">
              <label v-for="t in catalog.options.tags" :key="t.id">
                <input v-model="tagIds" type="checkbox" :value="t.id" />
                <span class="tag-chip" :style="{ '--c': t.color || undefined }"><i class="dot" />{{ t.name }}</span>
              </label>
            </div>
            <p v-if="err('tag_ids') || err('tag_ids.0')" class="err">{{ err('tag_ids') || err('tag_ids.0') }}</p>
          </div>
        </section>

        <section id="images" class="card pf-card">
          <div class="card__head"><h2>General pictures</h2></div>
          <div class="card__body">
            <ProductImages v-model="images" v-model:pending="pendingProduct" :product-id="id" :immediate="isEdit" />
            <p class="hint" style="font-size: 11.5px; color: var(--text-3); margin: 0">General pictures of the whole product (all options together). Each option has its own pictures too, in the Options section.</p>
          </div>
        </section>

        <section id="related" class="card pf-card">
          <div class="card__head"><h2>Related products</h2></div>
          <div class="card__body">
            <RelatedProducts v-model="related" :exclude-id="id" />
            <p v-if="err('related_ids') || err('related_ids.0')" class="err">{{ err('related_ids') || err('related_ids.0') }}</p>
          </div>
        </section>
      </div>
    </div>

    <div class="save-bar">
      <span v-if="formError" class="msg">{{ formError }}</span>
      <RouterLink class="btn btn-outline" :to="{ name: 'products' }">Cancel</RouterLink>
      <button class="btn btn-primary" type="submit" :disabled="saving">
        <span v-if="saving" class="spinner" /> {{ isEdit ? 'Save product' : 'Add product' }}
      </button>
    </div>
  </form>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ChevronDown, ChevronRight, Plus, Trash2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import ProductImages from '@/components/ProductImages.vue'
import RelatedProducts from '@/components/RelatedProducts.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import VariantEditor from '@/components/VariantEditor.vue'
import { money } from '@/utils/format.js'

const route = useRoute()
const router = useRouter()
const ui = useUiStore()
const catalog = useCatalogStore()
const auth = useAuthStore()
const site = useSiteStore()

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
  category_id: '', sub_category_id: '',
  name: '', sku: '', short_description: '', description: '', badge_text: '', tax_rate: '',
  min_order_qty: 1, max_order_qty: 10,
  is_cod_available: true, status: 'draft',
  meta_title: '', meta_description: '',
})
const tagIds = ref([])
const tabs = reactive({})          // key -> { content, format, manual_url }
const openTabs = ref(new Set())

const symbol = computed(() => catalog.currency.symbol)
const canManage = computed(() => auth.can('products.manage'))   // an admin without it sees the product read-only
const taxPlaceholder = computed(() => (site.tax.rate !== null && site.tax.rate !== undefined && site.tax.rate !== '' ? `Store default (${site.tax.rate}%)` : 'Store default'))

const blankVariant = (over = {}) => ({
  id: null, name: '', sku: '', mrp: '', selling_price: '', weight_grams: '', is_active: true, is_default: false,
  price_tiers: [], pending: [], stock: 0, stock_was: null, reserved: 0, low_stock_threshold: 0, ...over,
})
const variants = ref([])   // the options: every product has at least one; the cheapest one gives the starting price

// ---- dropdowns (the saved category stays selectable even if switched off since)
const categoryOptions = computed(() => {
  const list = [...catalog.categories]
  const p = product.value
  if (p && !list.some(c => c.id === p.category.id)) list.push({ id: p.category.id, name: `${p.category.name} (inactive)`, sub_categories: [] })
  return list
})
const subOptions = computed(() => {
  const category = categoryOptions.value.find(c => c.id === Number(form.category_id))
  if (!category) return []
  const list = category.sub_categories.map(s => ({ id: s.id, label: s.name }))
  const p = product.value
  if (p?.sub_category && p.category.id === Number(form.category_id) && !list.some(s => s.id === p.sub_category.id)) list.push({ id: p.sub_category.id, label: `${p.sub_category.name} (inactive)` })
  return list
})
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
    category_id: p.category?.id ?? '', sub_category_id: p.sub_category?.id ?? '',
    name: p.name, sku: p.sku, short_description: p.short_description ?? '', description: p.description ?? '', badge_text: p.badge_text ?? '',
    tax_rate: p.tax_rate ?? '',
    min_order_qty: p.min_order_qty, max_order_qty: p.max_order_qty,
    is_cod_available: p.is_cod_available, status: p.status,
    meta_title: p.meta_title ?? '', meta_description: p.meta_description ?? '',
  })
  const toRow = (v) => blankVariant({
    id: v.id, name: v.name, sku: v.sku, mrp: v.mrp, selling_price: v.selling_price,
    weight_grams: v.weight_grams ?? '', is_active: v.is_active, is_default: v.is_default,
    price_tiers: v.price_tiers.map(t => ({ min_qty: t.min_qty, price: t.price })), stock: v.stock ?? 0, stock_was: Number(v.stock ?? 0),
    reserved: v.reserved ?? 0, low_stock_threshold: v.low_stock_threshold ?? 0,
  })
  variants.value = p.variants.map(toRow)
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
  need('category_id', 'Category'); need('name', 'Product name'); need('sku', 'SKU')
  if (Number(form.max_order_qty) < Number(form.min_order_qty)) e.max_order_qty = ['The maximum cannot be lower than the minimum.']
  if (form.is_returnable && !(Number(form.return_days) >= 1)) e.return_days = ['Enter the number of return days.']

  const checkRow = (row, prefix) => {
    if (!row.name.trim()) e[`${prefix}.name`] = ['Name the option (for example "Blue, 500 ml").']
    if (!row.sku.trim()) e[`${prefix}.sku`] = ['Enter a SKU.']
    if (!(num(row.mrp) > 0)) e[`${prefix}.mrp`] = ['Enter the regular price.']
    if (Number.isNaN(num(row.selling_price))) e[`${prefix}.selling_price`] = ['Enter the selling price.']
    else if (num(row.selling_price) > num(row.mrp)) e[`${prefix}.selling_price`] = ['The selling price cannot be higher than the regular price.']

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
  if (form.tax_rate !== '' && form.tax_rate !== null && !(num(form.tax_rate) >= 0 && num(form.tax_rate) <= 100)) e.tax_rate = ['Enter a rate between 0 and 100, or leave it empty.']
  variants.value.forEach((v, i) => { if (v.stock !== '' && !(Number.isInteger(num(v.stock)) && num(v.stock) >= 0)) e[`variants.${i}.stock`] = ['Stock must be a whole number, 0 or more.'] })
  return e
}

function variantPayload(v, index) {
  return {
    id: v.id, name: v.name.trim(), sku: v.sku.trim() || undefined, is_default: Boolean(v.is_default), mrp: v.mrp, selling_price: v.selling_price,
    weight_grams: String(v.weight_grams).trim() === '' ? null : Number(v.weight_grams),
    is_active: v.is_active, sort_order: index, stock: Number(v.stock) || 0,
    // the quantity this form showed when it was opened: lets the server apply the edit as a change, so sales made meanwhile are not undone
    ...(v.id && v.stock_was !== null && v.stock_was !== undefined ? { stock_was: Number(v.stock_was) } : {}),
    price_tiers: v.price_tiers.map(t => ({ min_qty: Number(t.min_qty), price: Number(t.price) })),
  }
}

function buildPayload() {
  const text = (v) => (String(v).trim() === '' ? null : String(v).trim())
  return {
    category_id: Number(form.category_id),
    sub_category_id: form.sub_category_id ? Number(form.sub_category_id) : null,
    name: form.name.trim(), sku: form.sku.trim(), short_description: text(form.short_description), description: text(form.description),
    badge_text: text(form.badge_text), tax_rate: form.tax_rate === '' || form.tax_rate === null ? null : Number(form.tax_rate),
    min_order_qty: Number(form.min_order_qty) || 1, max_order_qty: Number(form.max_order_qty) || 1,
    is_cod_available: form.is_cod_available,
    status: form.status, meta_title: text(form.meta_title), meta_description: text(form.meta_description),
    variants: variants.value.map((v, i) => variantPayload(v, i)),
    tag_ids: tagIds.value,
    related_ids: related.value.map(r => r.id),
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
    <div v-if="!canManage" class="alert alert--info" role="status">You can look at this product but not change it.</div>
    <fieldset :disabled="!canManage" style="border: 0; padding: 0; margin: 0; min-width: 0">

    <div class="pf-layout">
      <div class="pf-col">
        <!-- ================= Basic ================= -->
        <section id="basic" class="card pf-card">
          <div class="card__head"><h2>Basic details</h2></div>
          <div class="card__body">
            <div class="form-grid">
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
                <input id="badge" v-model="form.badge_text" class="input" maxlength="40" placeholder="e.g. BEST SELLER" :class="{ 'has-error': err('badge_text') }" />
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
              <div class="field">
                <label for="taxrate">Tax rate (%)</label>
                <input id="taxrate" v-model="form.tax_rate" class="input" type="number" min="0" max="100" step="0.01" :placeholder="taxPlaceholder" :class="{ 'has-error': err('tax_rate') }" @input="clearError('tax_rate')" />
                <p v-if="err('tax_rate')" class="err">{{ err('tax_rate') }}</p>
                <p v-else class="hint">Leave empty to use the store default.</p>
              </div>
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
            </div>
            <div style="display: flex; gap: 22px; flex-wrap: wrap">
              <label style="display: flex; gap: 8px; align-items: center"><span class="switch"><input v-model="form.is_cod_available" type="checkbox" /><span /></span> Cash on delivery</label>
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
              Each option has its own name, SKU, price, stock and pictures, like "Blue, 500 ml" and "Blue, 1 litre". The cheapest option gives the starting price.
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
                  :row="v" :prefix="`variants.${i}`" :label="`Option ${i + 1}`" :errors="errors" :product-id="id" :images="images"
                  @clear="clearError(...$event)" @images="images = $event"
                />
              </div>
            </div>
            <div><button class="btn btn-outline btn-sm" type="button" @click="addVariant"><Plus :size="15" /> Add option</button></div>
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
              <p class="hint">Only <strong>Active</strong> products show on the website, and only when their category and sub-category are active too.</p>
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

    </fieldset>

    <div class="save-bar">
      <span v-if="formError" class="msg">{{ formError }}</span>
      <RouterLink class="btn btn-outline" :to="{ name: 'products' }">{{ canManage ? 'Cancel' : 'Back' }}</RouterLink>
      <button v-if="canManage" class="btn btn-primary" type="submit" :disabled="saving">
        <span v-if="saving" class="spinner" /> {{ isEdit ? 'Save product' : 'Add product' }}
      </button>
    </div>
  </form>
</template>

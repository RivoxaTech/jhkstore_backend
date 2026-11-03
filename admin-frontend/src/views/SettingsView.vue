<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { Save } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import { currencySymbol } from '@/utils/format.js'
import AccountsEditor from '@/components/AccountsEditor.vue'
import LinksEditor from '@/components/LinksEditor.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import UploadField from '@/components/UploadField.vue'
import BannersPanel from '@/components/BannersPanel.vue'
import DeliveryZonesPanel from '@/components/DeliveryZonesPanel.vue'
import NotificationsPanel from '@/components/NotificationsPanel.vue'
import ThemePreview from '@/components/ThemePreview.vue'

// Generic settings screen: the API describes the tabs the admin may open (each is guarded by its own permission), their sections and fields
// (key, type, label, help, min, max, options, value); this page renders an input per field type and saves only the keys that changed (all tabs in one save).
// Types: string, text, bool, int, money, percent, file, links, accounts (bank accounts), color (#RRGGBB) and choice (a select of `options`).
// The Banners tab has no fields: it shows the banner manager. The Delivery tab adds the delivery zone manager, the Theme tab (developer only) a live preview.
const ui = useUiStore()
const auth = useAuthStore()
const canManage = computed(() => auth.can('settings.*'))   // the API lists only the tabs the admin may use, and refuses writes to the others
const catalog = useCatalogStore()
const site = useSiteStore()
const route = useRoute()
const router = useRouter()

const NUMBER_TYPES = ['int', 'money', 'percent']

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const errors = ref({})
const tabs = ref([])
const platforms = ref([])
const saved = ref({})         // the values the server has
const files = ref({})         // setting key -> picture url
const form = reactive({})     // the values being edited
let keySeq = 0

const visibleTabs = computed(() => tabs.value)
const isBanners = computed(() => activeKey.value === 'banners')
const activeKey = computed(() => (visibleTabs.value.some(t => t.key === route.query.tab) ? route.query.tab : visibleTabs.value[0]?.key))
const activeTab = computed(() => visibleTabs.value.find(t => t.key === activeKey.value))

const fieldsByKey = computed(() => Object.fromEntries(tabs.value.flatMap(t => t.sections).flatMap(s => s.fields).map(f => [f.key, f])))
const tabOfKey = computed(() => Object.fromEntries(tabs.value.flatMap(t => t.sections.flatMap(s => s.fields.map(f => [f.key, t.key])))))

const withKeys = (rows) => (rows || []).map(l => ({ ...l, _key: `l${keySeq++}` }))
const bareLinks = (links) => (links || []).map(({ platform, label, url, active }) => ({ platform, label: (label || '').trim(), url: (url || '').trim(), active: Boolean(active) }))
const bareAccounts = (rows) => (rows || []).map(({ bank, title, number, iban, active }) => ({ bank: (bank || '').trim(), title: (title || '').trim(), number: (number || '').trim(), iban: (iban || '').trim(), active: Boolean(active) }))
const LIST_TYPES = ['links', 'accounts']

function fill(payload) {
  tabs.value = payload.tabs || []
  platforms.value = payload.link_platforms || []
  files.value = { ...(payload.files || {}) }
  saved.value = { ...(payload.values || {}) }
  Object.keys(form).forEach(k => delete form[k])
  Object.assign(form, payload.values || {})
  for (const f of Object.values(fieldsByKey.value)) {
    if (LIST_TYPES.includes(f.type)) form[f.key] = withKeys(payload.values?.[f.key])
  }
}

async function load() {
  loading.value = true
  loadError.value = ''
  try { fill(await api.get('admin/settings')) } catch (e) { loadError.value = e.message } finally { loading.value = false }
}
onMounted(load)

const isNumber = (f) => NUMBER_TYPES.includes(f.type)
const empty = (v) => v === null || v === undefined || v === ''
function same(field, a, b) {
  if (field.type === 'bool') return Boolean(a) === Boolean(b)
  if (field.type === 'links') return JSON.stringify(bareLinks(a)) === JSON.stringify(bareLinks(b))
  if (field.type === 'accounts') return JSON.stringify(bareAccounts(a)) === JSON.stringify(bareAccounts(b))
  if (field.type === 'color') return String(a ?? '').toUpperCase() === String(b ?? '').toUpperCase()
  if (isNumber(field)) return empty(a) || empty(b) ? empty(a) === empty(b) : Number(a) === Number(b)
  return String(a ?? '') === String(b ?? '')
}
function valueOf(field) {
  const v = form[field.key]
  if (field.type === 'bool') return Boolean(v)
  if (field.type === 'links') return bareLinks(v)
  if (field.type === 'accounts') return bareAccounts(v)
  if (field.type === 'color') return String(v ?? '').trim().toUpperCase()
  if (isNumber(field)) return empty(v) ? null : Number(v)
  return v ?? ''
}

const changed = computed(() => Object.values(fieldsByKey.value).filter(f => f.type !== 'file' && f.key in form && !same(f, form[f.key], saved.value[f.key])))
const dirty = computed(() => changed.value.length > 0)
const dirtyTabs = computed(() => new Set(changed.value.map(f => tabOfKey.value[f.key])))
const errorTabs = computed(() => new Set(Object.keys(errors.value).map(k => tabOfKey.value[k.split('.')[0]]).filter(Boolean)))

function pickTab(key) {
  router.replace({ query: { ...route.query, tab: key } })
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body = Object.fromEntries(changed.value.map(f => [f.key, valueOf(f)]))
    const res = await api.put('admin/settings', body)
    // the answer normally carries the fresh settings; fall back to reading them
    fill(res?.tabs ? res : await api.get('admin/settings'))
    ui.toast('Settings saved.')
    await refreshCaches()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) {
      errors.value = e.errors
      ui.toast(Object.values(e.errors).flat()[0] || e.message, 'error')
      const first = Object.keys(e.errors).map(k => tabOfKey.value[k.split('.')[0]]).find(Boolean)
      if (first && first !== activeKey.value) pickTab(first)
      await nextTick()
      document.querySelector('.has-error')?.focus?.()
    } else ui.toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

/** The store name, logo and currency are cached by other stores: refresh them after a change. */
async function refreshCaches() {
  await Promise.allSettled([site.reload(), auth.can('products.view') || auth.can('categories.view') ? catalog.reload() : null])
}

/** A picture was uploaded or removed: it is saved at once; unsaved edits in the form stay as they are. */
async function pictureChanged(payload, message) {
  files.value = { ...(payload.files || {}) }
  ui.toast(message)
  await refreshCaches()
}

// leaving with unsaved edits asks first
onBeforeRouteLeave(() => (dirty.value ? window.confirm('You have unsaved settings. Leave without saving?') : true))

const fe = (key) => errors.value[key]?.[0] || ''
const listErrors = (key) => Object.fromEntries(Object.entries(errors.value).filter(([k]) => k === key || k.startsWith(`${key}.`)))
const affix = (f) => (f.type === 'money' ? 'left' : f.type === 'percent' ? 'right' : '')

// ---- colour: a colour picker and a hex box that stay in sync
const HEX = /^#[0-9a-f]{6}$/i
const swatch = (key) => (HEX.test(String(form[key] ?? '')) ? String(form[key]).toLowerCase() : '#000000')
const setColour = (key, value) => { form[key] = String(value).toUpperCase() }

// ---- choice: readable names for the values of a few selects
const OPTION_LABELS = {
  delivery_method: { flat: 'Flat fee', free: 'Always free', free_above: 'Free above an amount', zones: 'By delivery zone', owner_confirms: 'The owner confirms the fee' },
  delivery_unmatched: { block: 'Refuse the order', owner_confirms: 'The owner confirms the fee', flat: 'Use the flat delivery charge' },
  theme_radius: { '999px': '999px (pill)', '0px': '0px (square)' },
}
const optionLabel = (key, value) => OPTION_LABELS[key]?.[value] || (key.startsWith('theme_font') ? value : value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' '))

// ---- Delivery tab: show only the fee fields the chosen method uses
const method = computed(() => String(form.delivery_method ?? ''))
const METHOD_HELP = {
  flat: 'Every order pays the delivery charge below.',
  free: 'Delivery is always free: no fee is needed.',
  free_above: 'Orders pay the delivery charge unless the cart reaches the "free delivery from" amount.',
  zones: 'The fee comes from the delivery zone that matches the address (manage the zones below). Choose what happens to an address outside every zone.',
  owner_confirms: 'Orders are placed without a delivery fee; you set the fee on each order afterwards.',
}
function showField(f) {
  if (!('delivery_method' in form)) return true
  if (f.key === 'free_delivery_min_cart') return method.value === 'free_above'
  if (f.key === 'delivery_unmatched') return method.value === 'zones'
  if (f.key === 'delivery_fee') return method.value === 'flat' || method.value === 'free_above' || (method.value === 'zones' && form.delivery_unmatched === 'flat')
  return true
}

// ---- Payments tab: a bank transfer needs an account customers can pay into
const hasActiveAccount = computed(() => bareAccounts(form.pay_bank_accounts).some(a => a.active && a.bank && (a.number || a.iban)))
const bankWarning = computed(() => 'pay_bank_enabled' in form && Boolean(form.pay_bank_enabled) && !hasActiveAccount.value)
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Settings</span></div>
    </div>
    <div class="page-actions">
      <span v-if="dirty" class="muted" style="margin-right: 4px">Unsaved changes</span>
      <button v-if="canManage && !isBanners" class="btn btn-primary" type="submit" form="settings-form" :disabled="saving || loading || !dirty"><span v-if="saving" class="spinner" /><Save v-else :size="16" /> Save settings</button>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <form v-else-if="!loadError" id="settings-form" novalidate @submit.prevent="save">
    <div class="card" style="margin-bottom: 12px">
      <div class="tabs" role="tablist" aria-label="Settings sections">
        <button
          v-for="t in visibleTabs" :id="`tab-${t.key}`" :key="t.key" type="button" role="tab" class="tab"
          :class="{ 'is-active': t.key === activeKey }" :aria-selected="t.key === activeKey" :aria-controls="`panel-${t.key}`" @click="pickTab(t.key)"
        >
          {{ t.label }}
          <span v-if="errorTabs.has(t.key)" class="count" style="background: var(--danger-soft); color: var(--danger)" title="Needs attention">!</span>
          <span v-else-if="dirtyTabs.has(t.key)" class="count" title="Unsaved changes">•</span>
        </button>
      </div>
    </div>

    <div v-if="isBanners" id="panel-banners" role="tabpanel" aria-labelledby="tab-banners"><BannersPanel /></div>
    <fieldset v-else-if="activeTab" :disabled="!canManage" style="border: 0; padding: 0; margin: 0; min-width: 0">
    <div :id="`panel-${activeTab.key}`" role="tabpanel" :aria-labelledby="`tab-${activeTab.key}`" class="settings-grid">
      <section v-for="s in activeTab.sections" :key="s.key" class="card" :data-section="s.key">
        <div class="card__head"><h2>{{ s.label }}</h2></div>
        <div class="card__body form-grid">
          <template v-for="f in s.fields" :key="f.key">
          <div v-if="showField(f)" class="field" :data-field="f.key" :class="{ full: ['text', 'links', 'accounts', 'file'].includes(f.type) || f.key === 'delivery_method' }">
            <UploadField
              v-if="f.type === 'file'" :label="f.label" :url="files[f.key] || ''" :name="site.name" :help="f.help || ''" :upload="f.upload"
              @updated="pictureChanged"
            />
            <template v-else-if="f.type === 'links'">
              <label>{{ f.label }}</label>
              <LinksEditor v-model="form[f.key]" :platforms="platforms" :errors="listErrors(f.key)" :field="f.key" />
              <div v-if="f.help" class="hint">{{ f.help }}</div>
              <div v-if="fe(f.key)" class="err">{{ fe(f.key) }}</div>
            </template>
            <template v-else-if="f.type === 'accounts'">
              <label>{{ f.label }}</label>
              <AccountsEditor v-model="form[f.key]" :errors="listErrors(f.key)" :field="f.key" />
              <div v-if="f.help" class="hint">{{ f.help }}</div>
              <div v-if="fe(f.key)" class="err">{{ fe(f.key) }}</div>
            </template>
            <template v-else-if="f.type === 'bool'">
              <div style="display: flex; align-items: center; gap: 10px">
                <label class="switch" :aria-label="f.label"><input :id="`s-${f.key}`" v-model="form[f.key]" type="checkbox" /><span /></label>
                <div>
                  <label :for="`s-${f.key}`" style="font-weight: 500; margin: 0">{{ f.label }}</label>
                  <p v-if="f.help" class="hint" style="margin: 0">{{ f.help }}</p>
                  <p v-if="f.key === 'pay_bank_enabled' && bankWarning" class="hint" data-bank-warning style="margin: 2px 0 0; color: var(--warning)">Add at least one active bank account below, or customers will have nowhere to pay.</p>
                </div>
              </div>
            </template>
            <template v-else>
              <label :for="`s-${f.key}`">{{ f.label }}</label>
              <textarea v-if="f.type === 'text'" :id="`s-${f.key}`" v-model="form[f.key]" class="textarea" rows="3" :class="{ 'has-error': fe(f.key) }" />
              <div v-else-if="f.type === 'color'" class="color-field">
                <input class="color-swatch" type="color" :value="swatch(f.key)" :aria-label="`${f.label} picker`" @input="setColour(f.key, $event.target.value)" />
                <input :id="`s-${f.key}`" v-model="form[f.key]" class="input" type="text" maxlength="7" spellcheck="false" placeholder="#RRGGBB" :class="{ 'has-error': fe(f.key) }" />
              </div>
              <select v-else-if="f.type === 'choice'" :id="`s-${f.key}`" v-model="form[f.key]" class="select" :class="{ 'has-error': fe(f.key) }">
                <option v-for="o in f.options" :key="o" :value="o">{{ optionLabel(f.key, o) }}</option>
              </select>
              <div v-else-if="isNumber(f)" class="input-affix" :class="{ 'input-affix--right': affix(f) === 'right' }">
                <span v-if="affix(f) === 'left'">{{ currencySymbol() }}</span>
                <input :id="`s-${f.key}`" v-model.number="form[f.key]" class="input" type="number" :min="f.min ?? undefined" :max="f.max ?? undefined" :step="f.type === 'int' ? 1 : 0.01" :class="{ 'has-error': fe(f.key) }" />
                <span v-if="affix(f) === 'right'">%</span>
              </div>
              <input v-else :id="`s-${f.key}`" v-model="form[f.key]" class="input" type="text" :class="{ 'has-error': fe(f.key) }" />
              <div v-if="f.help" class="hint">{{ f.help }}</div>
              <div v-if="f.key === 'delivery_method' && METHOD_HELP[method]" class="hint" data-method-help style="color: var(--primary-dark)">{{ METHOD_HELP[method] }}</div>
            </template>
            <div v-if="!LIST_TYPES.includes(f.type) && fe(f.key)" class="err">{{ fe(f.key) }}</div>
          </div>
          </template>
        </div>
      </section>

      <section v-if="activeTab.key === 'theme'" class="card" data-section="theme-preview">
        <div class="card__head"><h2>Live preview</h2></div>
        <div class="card__body">
          <ThemePreview :values="form" />
          <p class="hint" style="margin-top: 8px">A product card drawn with the values above. Save to publish them to the storefront. The admin panel keeps its own look.</p>
        </div>
      </section>

      <DeliveryZonesPanel v-if="activeTab.key === 'delivery'" :method="method" style="grid-column: 1 / -1" />
      <NotificationsPanel v-if="activeTab.key === 'notifications'" style="grid-column: 1 / -1" />
    </div>
    </fieldset>
  </form>
</template>

<style scoped>
.color-field { display: flex; align-items: center; gap: 8px; }
.color-swatch { width: 40px; height: 34px; padding: 2px; border: 1px solid var(--border); border-radius: 8px; background: #fff; cursor: pointer; flex: none; }
.color-field .input { max-width: 130px; font-variant-numeric: tabular-nums; text-transform: uppercase; }
</style>

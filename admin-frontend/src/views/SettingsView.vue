<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { Save, Undo2 } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import SettingField from '@/components/SettingField.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import BannersPanel from '@/components/BannersPanel.vue'
import DeliveryZonesPanel from '@/components/DeliveryZonesPanel.vue'
import NotificationsPanel from '@/components/NotificationsPanel.vue'
import ThemePreview from '@/components/ThemePreview.vue'
import WebsiteStatusCard from '@/components/WebsiteStatusCard.vue'

// Generic settings screen: the API describes the tabs the admin may open (each is guarded by its own permission), their sections and fields
// (key, type, label, help, tier, min, max, options, value); only fields the admin may use are sent (technical ones need `settings.advanced`).
// Sections are small cards in a two-column grid; one sticky bar saves only the keys that changed (all tabs in one save).
// The Banners tab shows the banner manager, Delivery adds the zones table, Notifications is its own compact page, Theme (developer) adds a preview.
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
const events = ref([])
const platforms = ref([])
const saved = ref({})         // the values the server has
const files = ref({})         // setting key -> picture url
const form = reactive({})     // the values being edited
let keySeq = 0

const visibleTabs = computed(() => tabs.value)
const isBanners = computed(() => activeKey.value === 'banners')
const isNotifications = computed(() => activeKey.value === 'notifications')
const isWebsite = computed(() => activeKey.value === 'website')
const websiteCard = ref(null)
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
  events.value = payload.events || []
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
  if (field.type === 'secret') return !(field.key in form) || form[field.key] === undefined   // only typed (or cleared) secrets are sent
  if (field.type === 'bool') return Boolean(a) === Boolean(b)
  if (field.type === 'links') return JSON.stringify(bareLinks(a)) === JSON.stringify(bareLinks(b))
  if (field.type === 'accounts') return JSON.stringify(bareAccounts(a)) === JSON.stringify(bareAccounts(b))
  if (field.type === 'color') return String(a ?? '').toUpperCase() === String(b ?? '').toUpperCase()
  if (isNumber(field)) return empty(a) || empty(b) ? empty(a) === empty(b) : Number(a) === Number(b)
  return String(a ?? '') === String(b ?? '')
}
function valueOf(field) {
  const v = form[field.key]
  if (field.type === 'secret') return String(v ?? '')
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

// ---- inline validation of edited fields (the server checks everything again on save)
const EMAIL_KEYS = ['store_email', 'notify_admin_email', 'mail_reply_to']
const URL_KEYS = ['storefront_track_url', 'agency_url', 'rebuild_hook_url']
function problem(f) {
  const v = form[f.key]
  if (isNumber(f)) {
    if (empty(v)) return 'Enter a number.'
    const n = Number(v)
    if (Number.isNaN(n)) return 'Enter a number.'
    const min = f.min ?? 0
    if (n < min) return `The smallest value is ${min}.`
    if (f.max != null && n > f.max) return `The largest value is ${f.max}.`
    return ''
  }
  const text = String(v ?? '').trim()
  if (EMAIL_KEYS.includes(f.key) && text && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(text)) return 'Enter a valid e-mail address.'
  if (URL_KEYS.includes(f.key) && text && !/^https?:\/\/\S+\.\S+/i.test(text)) return 'Enter a full web address starting with https://.'
  if (f.key === 'rebuild_hook_url' && text && !/^https:\/\//i.test(text)) return 'The rebuild hook must start with https://.'
  if (f.type === 'color' && !/^#[0-9a-f]{6}$/i.test(text)) return 'Enter a colour as #RRGGBB.'
  return ''
}
const clientErrors = computed(() => Object.fromEntries(changed.value.map(f => [f.key, problem(f)]).filter(([, msg]) => msg)))
const errorTabs = computed(() => new Set([...Object.keys(errors.value), ...Object.keys(clientErrors.value)].map(k => tabOfKey.value[k.split('.')[0]]).filter(Boolean)))

function pickTab(key) {
  router.replace({ query: { ...route.query, tab: key } })
}

async function save() {
  errors.value = {}
  const firstBad = Object.keys(clientErrors.value)[0]
  if (firstBad) {
    ui.toast(clientErrors.value[firstBad], 'error')
    const tab = tabOfKey.value[firstBad]
    if (tab && tab !== activeKey.value) pickTab(tab)
    await nextTick()
    document.querySelector('.has-error')?.focus?.()
    return
  }
  saving.value = true
  try {
    const body = Object.fromEntries(changed.value.map(f => [f.key, valueOf(f)]))
    const res = await api.put('admin/settings', body)
    // the answer normally carries the fresh settings; fall back to reading them
    fill(res?.tabs ? res : await api.get('admin/settings'))
    ui.toast('Settings saved.')
    websiteCard.value?.load()
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

/** Put the form back to what the server has. */
function discard() {
  errors.value = {}
  Object.keys(form).forEach(k => delete form[k])
  Object.assign(form, saved.value)
  for (const f of Object.values(fieldsByKey.value)) {
    if (LIST_TYPES.includes(f.type)) form[f.key] = withKeys(saved.value[f.key])
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

// ---- Delivery tab: show only the fee fields the chosen method uses
const method = computed(() => String(form.delivery_method ?? ''))
function showField(f) {
  if (!('delivery_method' in form)) return true
  if (f.key === 'free_delivery_min_cart') return method.value === 'free_above'
  if (f.key === 'delivery_unmatched') return method.value === 'zones'
  if (f.key === 'delivery_fee') return method.value === 'flat' || method.value === 'free_above' || (method.value === 'zones' && form.delivery_unmatched === 'flat')
  return true
}
const shown = (s) => s.fields.filter(showField)
// a section with wide controls (links, accounts, pictures) or many fields takes the whole row and lays its fields out in two columns
const wide = (s) => shown(s).some(f => ['links', 'accounts', 'file'].includes(f.type)) || shown(s).length > 3
const full = (f) => ['text', 'links', 'accounts', 'file'].includes(f.type) || f.key === 'delivery_method'
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Settings</span></div>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <form v-else-if="!loadError" id="settings-form" class="settings" novalidate @submit.prevent="save">
    <div class="card" style="margin-bottom: 10px">
      <div class="tabs tabs--scroll" role="tablist" aria-label="Settings sections">
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

    <p v-if="!visibleTabs.length" class="muted" data-testid="no-tabs">There is nothing to change here for your account.</p>

    <div v-if="isBanners" id="panel-banners" role="tabpanel" aria-labelledby="tab-banners"><BannersPanel /></div>
    <fieldset v-else-if="activeTab" :disabled="!canManage" style="border: 0; padding: 0; margin: 0; min-width: 0">
      <div :id="`panel-${activeTab.key}`" role="tabpanel" :aria-labelledby="`tab-${activeTab.key}`" class="settings-grid">
        <NotificationsPanel v-if="isNotifications" :form="form" :fields="fieldsByKey" :events="events" :errors="errors" style="grid-column: 1 / -1" />

        <template v-else>
          <WebsiteStatusCard v-if="isWebsite" ref="websiteCard" style="grid-column: 1 / -1" />
          <section v-for="s in activeTab.sections" v-show="shown(s).length" :key="s.key" class="card set-card" :class="{ 'set-card--wide': wide(s) }" :data-section="s.key">
            <div class="card__head"><h2>{{ s.label }}</h2></div>
            <div class="card__body set-fields" :class="{ 'set-fields--cols': wide(s) }">
              <SettingField
                v-for="f in shown(s)" :key="f.key" :field="f" :form="form" :errors="errors" :client-error="clientErrors[f.key] || ''" :platforms="platforms" :files="files" :site-name="site.name"
                :class="{ full: full(f) }" @picture="pictureChanged"
              />
            </div>
          </section>

          <section v-if="activeTab.key === 'theme'" class="card set-card set-card--wide" data-section="theme-preview">
            <div class="card__head"><h2>Live preview</h2></div>
            <div class="card__body">
              <ThemePreview :values="form" />
              <p class="hint" style="margin-top: 8px; font-size: 11.5px">A product card drawn with the values above. Save to publish them to the storefront. The admin panel keeps its own look.</p>
            </div>
          </section>

          <DeliveryZonesPanel v-if="activeTab.key === 'delivery'" :method="method" style="grid-column: 1 / -1" />
        </template>
      </div>
    </fieldset>

    <div v-if="canManage && visibleTabs.length && !isBanners" class="save-bar settings-bar" :class="{ 'is-dirty': dirty }" data-testid="save-bar">
      <span class="settings-bar__state" :class="{ 'is-dirty': dirty }" role="status" data-testid="save-state">
        <i class="settings-bar__dot" aria-hidden="true" />{{ dirty ? `Unsaved changes (${changed.length})` : 'All changes saved' }}
      </span>
      <button class="btn btn-outline" type="button" :disabled="!dirty || saving" data-testid="discard" @click="discard"><Undo2 :size="15" /> Discard</button>
      <button class="btn btn-primary" type="submit" data-testid="save-settings" :disabled="saving || loading || !dirty"><span v-if="saving" class="spinner" /><Save v-else :size="16" /> Save changes</button>
    </div>
  </form>
</template>

<style scoped>
.tabs--scroll { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: thin; }
.tabs--scroll .tab { white-space: nowrap; flex: none; }
.settings-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: start; }
.set-card--wide { grid-column: 1 / -1; }
.set-fields { display: grid; gap: 9px; padding: 10px 14px 12px; }
.set-fields--cols { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px 16px; }
.set-fields .full { grid-column: 1 / -1; }
.set-card .card__head { padding: 8px 14px; }
.set-card .card__head h2 { font-size: 13px; }
@media (max-width: 900px) {
  .settings-grid { grid-template-columns: minmax(0, 1fr); }
  .set-fields--cols { grid-template-columns: minmax(0, 1fr); }
}
.settings-bar { justify-content: flex-end; margin-top: 10px; margin-bottom: -8px; }
.settings-bar__state { margin-right: auto; display: inline-flex; align-items: center; gap: 8px; color: var(--text-2); font-weight: 500; }
.settings-bar__state.is-dirty { color: var(--warning); }
.settings-bar__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--success); }
.is-dirty .settings-bar__dot { background: var(--warning); }
</style>

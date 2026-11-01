<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { Save } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import LinksEditor from '@/components/LinksEditor.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'
import UploadField from '@/components/UploadField.vue'

// Generic settings screen: the API describes the tabs, their sections and fields (key, type, label, help, min, max, value),
// this page renders an input per field type and saves only the keys that changed (all tabs in one save).
const ui = useUiStore()
const auth = useAuthStore()
const canManage = computed(() => auth.can('settings.manage'))   // managers can look but not change
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

// The reward-points section only exists while the feature is on (judged by the saved value).
const visibleTabs = computed(() => tabs.value.map(t => ({
  ...t,
  sections: t.sections.filter(s => s.key !== 'rewards' || saved.value.rewards_enabled === true),
})))
const activeKey = computed(() => (visibleTabs.value.some(t => t.key === route.query.tab) ? route.query.tab : visibleTabs.value[0]?.key))
const activeTab = computed(() => visibleTabs.value.find(t => t.key === activeKey.value))

const fieldsByKey = computed(() => Object.fromEntries(tabs.value.flatMap(t => t.sections).flatMap(s => s.fields).map(f => [f.key, f])))
const tabOfKey = computed(() => Object.fromEntries(tabs.value.flatMap(t => t.sections.flatMap(s => s.fields.map(f => [f.key, t.key])))))

const withKeys = (links) => (links || []).map(l => ({ ...l, _key: `l${keySeq++}` }))
const bareLinks = (links) => (links || []).map(({ platform, label, url, active }) => ({ platform, label: (label || '').trim(), url: (url || '').trim(), active: Boolean(active) }))

function fill(payload) {
  tabs.value = payload.tabs || []
  platforms.value = payload.link_platforms || []
  files.value = { ...(payload.files || {}) }
  saved.value = { ...(payload.values || {}) }
  Object.keys(form).forEach(k => delete form[k])
  Object.assign(form, payload.values || {})
  for (const f of Object.values(fieldsByKey.value)) {
    if (f.type === 'links') form[f.key] = withKeys(payload.values?.[f.key])
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
  if (isNumber(field)) return empty(a) || empty(b) ? empty(a) === empty(b) : Number(a) === Number(b)
  return String(a ?? '') === String(b ?? '')
}
function valueOf(field) {
  const v = form[field.key]
  if (field.type === 'bool') return Boolean(v)
  if (field.type === 'links') return bareLinks(v)
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

/** The store name, logo, currency and features are cached by other stores: refresh them after a change. */
async function refreshCaches() {
  await Promise.allSettled([site.reload(), catalog.reload()])
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
const linkErrors = (key) => Object.fromEntries(Object.entries(errors.value).filter(([k]) => k === key || k.startsWith(`${key}.`)))
const affix = (f) => (f.type === 'money' ? 'left' : f.type === 'percent' ? 'right' : '')
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Settings</span></div>
    </div>
    <div class="page-actions">
      <span v-if="dirty" class="muted" style="margin-right: 4px">Unsaved changes</span>
      <button v-if="canManage" class="btn btn-primary" type="submit" form="settings-form" :disabled="saving || loading || !dirty"><span v-if="saving" class="spinner" /><Save v-else :size="16" /> Save settings</button>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <form v-else-if="!loadError" id="settings-form" novalidate @submit.prevent="save">
    <div v-if="!canManage" class="alert alert--info" role="status">You can look at the settings but not change them.</div>
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

    <fieldset v-if="activeTab" :disabled="!canManage" style="border: 0; padding: 0; margin: 0; min-width: 0">
    <div :id="`panel-${activeTab.key}`" role="tabpanel" :aria-labelledby="`tab-${activeTab.key}`" class="settings-grid">
      <section v-for="s in activeTab.sections" :key="s.key" class="card" :data-section="s.key">
        <div class="card__head"><h2>{{ s.label }}</h2></div>
        <div class="card__body form-grid">
          <div v-for="f in s.fields" :key="f.key" class="field" :class="{ full: f.type === 'text' || f.type === 'links' || f.type === 'file' }">
            <UploadField
              v-if="f.type === 'file'" :label="f.label" :url="files[f.key] || ''" :name="site.name" :help="f.help || ''" :upload="f.upload"
              @updated="pictureChanged"
            />
            <template v-else-if="f.type === 'links'">
              <label>{{ f.label }}</label>
              <LinksEditor v-model="form[f.key]" :platforms="platforms" :errors="linkErrors(f.key)" :field="f.key" />
              <div v-if="f.help" class="hint">{{ f.help }}</div>
              <div v-if="fe(f.key)" class="err">{{ fe(f.key) }}</div>
            </template>
            <template v-else-if="f.type === 'bool'">
              <div style="display: flex; align-items: center; gap: 10px">
                <label class="switch" :aria-label="f.label"><input :id="`s-${f.key}`" v-model="form[f.key]" type="checkbox" /><span /></label>
                <div>
                  <label :for="`s-${f.key}`" style="font-weight: 500; margin: 0">{{ f.label }}</label>
                  <p v-if="f.help" class="hint" style="margin: 0">{{ f.help }}</p>
                </div>
              </div>
            </template>
            <template v-else>
              <label :for="`s-${f.key}`">{{ f.label }}</label>
              <textarea v-if="f.type === 'text'" :id="`s-${f.key}`" v-model="form[f.key]" class="textarea" rows="3" :class="{ 'has-error': fe(f.key) }" />
              <div v-else-if="isNumber(f)" class="input-affix" :class="{ 'input-affix--right': affix(f) === 'right' }">
                <span v-if="affix(f) === 'left'">{{ catalog.currency.symbol }}</span>
                <input :id="`s-${f.key}`" v-model.number="form[f.key]" class="input" type="number" :min="f.min ?? undefined" :max="f.max ?? undefined" :step="f.type === 'int' ? 1 : 0.01" :class="{ 'has-error': fe(f.key) }" />
                <span v-if="affix(f) === 'right'">%</span>
              </div>
              <input v-else :id="`s-${f.key}`" v-model="form[f.key]" class="input" type="text" :class="{ 'has-error': fe(f.key) }" />
              <div v-if="f.help" class="hint">{{ f.help }}</div>
            </template>
            <div v-if="f.type !== 'links' && fe(f.key)" class="err">{{ fe(f.key) }}</div>
          </div>
        </div>
      </section>
    </div>
    </fieldset>
  </form>
</template>

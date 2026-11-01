<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Save } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useCatalogStore } from '@/stores/catalog.js'
import { useSiteStore } from '@/stores/site.js'
import LogoBox from '@/components/LogoBox.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Generic settings screen: the API describes the groups and fields (key, type, label, help, min, max, value),
// this page renders an input per field type and saves only the keys that changed.
const ui = useUiStore()
const catalog = useCatalogStore()
const site = useSiteStore()

const NUMBER_TYPES = ['int', 'money', 'percent']
const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp']
const MAX_LOGO_MB = 2

const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const errors = ref({})
const groups = ref([])
const saved = ref({})         // the values the server has
const form = reactive({})     // the values being edited
const logoUrl = ref('')
const logoBusy = ref(false)
const logoError = ref('')

// The reward-points group only exists while the feature is on (judged by the saved value).
const visibleGroups = computed(() => groups.value
  .filter(g => g.key !== 'rewards' || saved.value.rewards_enabled === true)
  .map(g => ({ ...g, fields: g.fields.filter(f => f.type !== 'file') }))
  .filter(g => g.fields.length))

const fieldsByKey = computed(() => Object.fromEntries(groups.value.flatMap(g => g.fields).map(f => [f.key, f])))

function fill(payload) {
  groups.value = payload.groups || []
  saved.value = { ...(payload.values || {}) }
  Object.keys(form).forEach(k => delete form[k])
  Object.assign(form, payload.values || {})
  logoUrl.value = payload.logo_url || ''
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
  if (isNumber(field)) return empty(a) || empty(b) ? empty(a) === empty(b) : Number(a) === Number(b)
  return String(a ?? '') === String(b ?? '')
}
function valueOf(field) {
  const v = form[field.key]
  if (field.type === 'bool') return Boolean(v)
  if (isNumber(field)) return empty(v) ? null : Number(v)
  return v ?? ''
}

const changed = computed(() => Object.values(fieldsByKey.value).filter(f => f.type !== 'file' && f.key in form && !same(f, form[f.key], saved.value[f.key])))
const dirty = computed(() => changed.value.length > 0)

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const body = Object.fromEntries(changed.value.map(f => [f.key, valueOf(f)]))
    const res = await api.put('admin/settings', body)
    // the answer normally carries the fresh settings; fall back to reading them
    fill(res?.groups ? res : await api.get('admin/settings'))
    ui.toast('Settings saved.')
    await refreshCaches()
  } catch (e) {
    if (e instanceof ApiError && Object.keys(e.errors).length) {
      errors.value = e.errors
      ui.toast(Object.values(e.errors).flat()[0] || e.message, 'error')
    } else ui.toast(e.message, 'error')
  } finally {
    saving.value = false
  }
}

/** The store name, logo, currency and features are cached by other stores: refresh them after a change. */
async function refreshCaches() {
  await Promise.allSettled([site.reload(), catalog.reload()])
}

// ---- logo
async function pickLogo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  logoError.value = ''
  if (!LOGO_TYPES.includes(file.type)) { logoError.value = 'The logo must be a PNG, JPG or WEBP picture.'; return }
  if (file.size > MAX_LOGO_MB * 1024 * 1024) { logoError.value = `The logo must be ${MAX_LOGO_MB} MB or smaller.`; return }
  const fd = new FormData()
  fd.append('logo', file)
  await changeLogo(() => api.postForm('admin/settings/logo', fd), 'Logo updated.')
}
const removeLogo = () => changeLogo(() => api.del('admin/settings/logo'), 'Logo removed.')

async function changeLogo(call, message) {
  logoBusy.value = true
  logoError.value = ''
  try {
    const res = await call()
    logoUrl.value = res?.logo_url || '' // unsaved edits in the form stay as they are
    ui.toast(message)
    await refreshCaches()
  } catch (e) {
    logoError.value = e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message
  } finally {
    logoBusy.value = false
  }
}

const fe = (key) => errors.value[key]?.[0] || ''
const affix = (f) => (f.type === 'money' ? 'left' : f.type === 'percent' ? 'right' : '')
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Settings</span></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="submit" form="settings-form" :disabled="saving || loading || !dirty"><span v-if="saving" class="spinner" /><Save v-else :size="16" /> Save settings</button>
    </div>
  </div>

  <div v-if="loadError" class="alert alert--error" role="alert">{{ loadError }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <form v-else-if="!loadError" id="settings-form" class="settings-grid" novalidate @submit.prevent="save">
    <section class="card">
      <div class="card__head"><h2>Logo</h2></div>
      <div class="card__body">
        <div style="display: flex; gap: 14px; align-items: center">
          <LogoBox :name="site.name" :src="logoUrl" large />
          <div style="min-width: 0">
            <div style="display: flex; gap: 6px; flex-wrap: wrap">
              <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0" :aria-disabled="logoBusy">
                <span v-if="logoBusy" class="spinner" /> {{ logoUrl ? 'Change logo' : 'Upload logo' }}
                <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden :disabled="logoBusy" @change="pickLogo" />
              </label>
              <button v-if="logoUrl" class="btn btn-soft-danger btn-sm" type="button" :disabled="logoBusy" @click="removeLogo">Remove</button>
            </div>
            <p class="hint">Shown in the admin panel, on the store and on printed invoices. PNG, JPG or WEBP, up to {{ MAX_LOGO_MB }} MB.</p>
          </div>
        </div>
        <p v-if="logoError" class="err">{{ logoError }}</p>
      </div>
    </section>

    <section v-for="g in visibleGroups" :key="g.key" class="card" :data-group="g.key">
      <div class="card__head"><h2>{{ g.key === 'rewards' ? 'Reward points' : g.label }}</h2></div>
      <div class="card__body form-grid">
        <div v-for="f in g.fields" :key="f.key" class="field" :class="{ full: f.type === 'text' }">
          <template v-if="f.type === 'bool'">
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
          <div v-if="fe(f.key)" class="err">{{ fe(f.key) }}</div>
        </div>
      </div>
    </section>
  </form>
</template>

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'
import { setCurrency } from '@/utils/format.js'

/**
 * Public store settings (name, logo, contact details, currency, tax, features). The endpoint needs no login,
 * so it is loaded once at app start and used to brand the panel (sidebar, login page, document title, invoices).
 * `reload()` after the settings were saved.
 */
export const useSiteStore = defineStore('site', () => {
  const data = ref(null)
  const loaded = ref(false)
  let loading = null

  const store = computed(() => data.value?.store || {})
  const name = computed(() => (store.value.name || '').trim() || 'Store')
  const logoUrl = computed(() => store.value.logo_url || '')
  const tax = computed(() => data.value?.tax || { rate: null, inclusive: true, label: 'Tax' })
  const features = computed(() => ({ brands: false, rewards: false, ...(data.value?.features || {}) }))

  function load(force = false) {
    if (loaded.value && !force) return Promise.resolve()
    loading ||= api.get('store/settings')
      .then((res) => { data.value = res; setCurrency(res.currency); loaded.value = true })
      .catch(() => { /* branding is optional: the panel keeps its neutral defaults */ })
      .finally(() => { loading = null })
    return loading
  }

  return { data, loaded, store, name, logoUrl, tax, features, load, reload: () => load(true) }
})

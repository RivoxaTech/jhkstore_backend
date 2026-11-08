import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'
import { setCurrency } from '@/utils/format.js'
import { applyPrimary } from '@/utils/theme.js'

/** The favicon is a setting: point the tab icon at it, and back at the panel's own icon when the favicon is removed. */
let defaultIcon = null
function applyFavicon(url) {
  let link = document.querySelector('link[rel~="icon"]')
  if (!link) { link = document.createElement('link'); link.rel = 'icon'; document.head.appendChild(link) }
  if (defaultIcon === null) defaultIcon = link.getAttribute('href') || ''
  link.removeAttribute('type')   // the default is an svg; an uploaded favicon can be png or ico
  if (url) link.href = url
  else if (defaultIcon) link.setAttribute('href', defaultIcon)
}

/**
 * Public store settings (name, logo, contact details, currency, tax). The endpoint needs no login,
 * so it is loaded once at app start and used to label the panel (sidebar, login page, document title, invoices).
 * `reload()` after the settings were saved.
 */
export const useSiteStore = defineStore('site', () => {
  const data = ref(null)
  const loaded = ref(false)
  let loading = null

  const store = computed(() => data.value?.store || {})
  const name = computed(() => (store.value.name || '').trim() || 'Store')
  const logoUrl = computed(() => store.value.logo_url || '')
  const faviconUrl = computed(() => store.value.favicon_url || '')
  /** The name as the client typed it; empty until the settings are loaded (the document title then says just "Admin"). */
  const rawName = computed(() => (store.value.name || '').trim())

  const tax = computed(() => data.value?.tax || { rate: null, inclusive: true, label: 'Tax' })

  function load(force = false) {
    if (loaded.value && !force) return Promise.resolve()
    loading ||= api.get('store/settings')
      .then((res) => { data.value = res; setCurrency(res.currency); applyFavicon(res.store?.favicon_url); applyPrimary(res.theme?.primary); loaded.value = true })
      .catch(() => { /* branding is optional: the panel keeps its neutral defaults */ })
      .finally(() => { loading = null })
    return loading
  }

  return { data, loaded, store, name, rawName, logoUrl, faviconUrl, tax, load, reload: () => load(true) }
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client.js'
import { setCurrency } from '@/utils/format.js'

/**
 * Dropdown data of the product screens (approved companies with their active brands, tags, channels, categories tree).
 * Loaded once per session and shared by the product list and the product form; `reload()` after tags change.
 */
export const useCatalogStore = defineStore('catalog', () => {
  const options = ref({ companies: [], tags: [], channels: [], statuses: [], content_tabs: [] })
  const currency = ref({ code: 'PKR', symbol: 'Rs' })
  const categories = ref([]) // [{ id, name, sub_categories: [{ id, name, children: [{ id, name }] }] }]
  const loaded = ref(false)
  let loading = null

  function load(force = false) {
    if (loaded.value && !force) return Promise.resolve()
    loading ||= Promise.all([api.get('admin/products/options'), api.get('admin/categories/options')])
      .then(([opts, cats]) => { options.value = opts; currency.value = opts.currency || currency.value; setCurrency(currency.value.symbol); categories.value = cats.data; loaded.value = true })
      .finally(() => { loading = null })
    return loading
  }

  return { options, currency, categories, loaded, load, reload: () => load(true) }
})

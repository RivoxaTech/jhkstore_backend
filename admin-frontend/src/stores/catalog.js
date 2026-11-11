import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'
import { setCurrency } from '@/utils/format.js'
import { useSiteStore } from '@/stores/site.js'

/**
 * Dropdown data of the product screens (tags, statuses, content tabs, categories tree) plus the currency and
 * Loaded once per session and shared by the product screens and the
 * navigation; `reload()` after tags or settings change.
 */
export const useCatalogStore = defineStore('catalog', () => {
  const site = useSiteStore()
  const options = ref({ tags: [], statuses: [], content_tabs: [] })
  const currency = ref({ code: '', symbol: '$', decimals: 2 })
  const categories = ref([]) // [{ id, name, sub_categories: [{ id, name }] }]
  const loaded = ref(false)
  // a store without sub-categories never shows the sub-category field (products and categories forms, product filters)
  const hasSubCategories = computed(() => categories.value.some(c => (c.sub_categories || []).length > 0))
  let loading = null

  // Until the options arrived, the public store settings give the same answer.

  function load(force = false) {
    if (loaded.value && !force) return Promise.resolve()
    loading ||= Promise.all([api.get('admin/products/options'), api.get('admin/categories/options')])
      .then(([opts, cats]) => {
        options.value = opts
        currency.value = opts.currency || currency.value
        setCurrency(currency.value)
        categories.value = cats.data
        loaded.value = true
      })
      .finally(() => { loading = null })
    return loading
  }

  /** Only the categories again (one light call): the product screens read it on every visit so a category made a moment ago is there. */
  const refreshCategories = () => api.get('admin/categories/options').then((cats) => { categories.value = cats.data })

  return { options, currency, categories, loaded, hasSubCategories, load, reload: () => load(true), refreshCategories }
})

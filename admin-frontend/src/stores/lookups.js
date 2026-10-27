import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client.js'

/**
 * Dropdown data (registration types, vendor types, countries). It rarely changes, so it is loaded once per
 * session and shared by every page instead of being requested again on each visit.
 */
export const useLookupsStore = defineStore('lookups', () => {
  const data = ref({ registration_types: [], vendor_types: [], countries: [] })
  let loading = null

  function load() {
    if (data.value.countries.length) return Promise.resolve(data.value)
    loading ||= api.get('admin/companies/options')
      .then((res) => { data.value = res; return res })
      .finally(() => { loading = null })
    return loading
  }

  return { data, load }
})

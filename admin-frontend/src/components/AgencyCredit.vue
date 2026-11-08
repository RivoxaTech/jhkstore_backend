<script setup>
import { computed, ref, watch } from 'vue'
import { useSiteStore } from '@/stores/site.js'

// "Developed by [logo]": the agency credit of Settings → Footer & Social (name, link, logo), shown at the bottom of the sidebar and on the login page.
// No agency name = nothing is shown. The logo falls back to the name when the picture cannot be loaded.
const site = useSiteStore()
const agency = computed(() => site.agency)
const broken = ref(false)
watch(() => agency.value.logo_url, () => { broken.value = false })
</script>

<template>
  <component
    :is="agency.url ? 'a' : 'span'" v-if="agency.name" class="agency" :href="agency.url || undefined" :target="agency.url ? '_blank' : undefined"
    :rel="agency.url ? 'noopener noreferrer' : undefined" :title="`Developed by ${agency.name}`" data-testid="agency-credit"
  >
    <span class="agency__label">Developed by</span>
    <img v-if="agency.logo_url && !broken" :src="agency.logo_url" :alt="agency.name" class="agency__logo" @error="broken = true" />
    <strong v-else class="agency__name">{{ agency.name }}</strong>
  </component>
</template>

<style scoped>
.agency { display: inline-flex; align-items: center; gap: 8px; font-size: 11px; color: var(--text-3); line-height: 1; text-decoration: none; max-width: 100%; }
a.agency:hover .agency__label { color: var(--text-2); }
.agency__label { white-space: nowrap; }
/* the logo is white on black: it stays a small black badge on any background */
.agency__logo { height: 20px; width: auto; border-radius: 4px; background: #000; flex: none; }
.agency__name { color: var(--text-2); font-weight: 600; }
</style>

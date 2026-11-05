<script setup>
import { computed, ref, watch } from 'vue'
import { useSiteStore } from '@/stores/site.js'
import StoreMark from '@/components/StoreMark.vue'

// The store's logo in the sidebar header and on the login page. Without a logo (or when the picture cannot be loaded) the generic mark and the
// store name as text are shown. On the collapsed sidebar the favicon (a small square picture) is used when there is one, else the logo, kept small.
const props = defineProps({
  onDark: Boolean,       // the login page's coloured panel
  collapsed: Boolean,
  large: Boolean,        // the login page: a bigger logo
})
const site = useSiteStore()
const broken = ref(false)
const src = computed(() => (props.collapsed && site.faviconUrl ? site.faviconUrl : site.logoUrl))
watch(src, () => { broken.value = false })
</script>

<template>
  <div class="brand" :class="{ 'is-large': large, 'on-dark': onDark, 'has-logo': src && !broken }" data-testid="brand">
    <template v-if="src && !broken">
      <img class="brand__logo" :src="src" :alt="site.name" data-testid="brand-logo" @error="broken = true" />
      <span class="sr-only">{{ site.name }} Admin</span>
    </template>
    <template v-else>
      <StoreMark :on-dark="onDark" />
      <div class="store-text" data-testid="brand-name" :style="onDark ? { color: '#fff' } : null">{{ site.name }}<small :style="onDark ? { color: 'rgba(255, 255, 255, .7)' } : null">Admin</small></div>
    </template>
  </div>
</template>

<style scoped>
.brand { display: flex; align-items: center; gap: 9px; min-width: 0; position: relative; }
.brand__logo { height: 30px; width: auto; max-width: 150px; object-fit: contain; }
.is-large .brand__logo { height: 52px; max-width: 220px; }
.on-dark.has-logo { background: #fff; padding: 8px 12px; border-radius: 12px; align-self: flex-start; }
/* collapsed sidebar: a small square, the picture centred inside it */
:global(.is-collapsed) .brand__logo { height: 32px; width: 32px; max-width: 32px; }
</style>

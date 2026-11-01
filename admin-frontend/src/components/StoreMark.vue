<script setup>
import { ref, watch } from 'vue'
import { Store } from '@lucide/vue'
import { useSiteStore } from '@/stores/site.js'

// The store logo (from the public settings) or, without one, a generic shop icon.
defineProps({ onDark: Boolean }) // on the dark login panel the mark gets a translucent / white background
const site = useSiteStore()
const broken = ref(false)
watch(() => site.logoUrl, () => { broken.value = false })
</script>

<template>
  <div class="brand-mark" :class="{ 'brand-mark--logo': site.logoUrl && !broken, 'on-dark': onDark }">
    <img v-if="site.logoUrl && !broken" :src="site.logoUrl" :alt="site.name" @error="broken = true" />
    <Store v-else :size="20" />
  </div>
</template>

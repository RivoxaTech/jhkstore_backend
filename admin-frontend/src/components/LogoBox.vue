<script setup>
import { computed, ref, watch } from 'vue'
import { initials } from '@/utils/format.js'

const props = defineProps({ src: { type: String, default: null }, name: { type: String, default: '' }, large: Boolean, small: Boolean })
const broken = ref(false)
watch(() => props.src, () => { broken.value = false })
const showImage = computed(() => props.src && !broken.value)
</script>

<template>
  <span class="logo-box" :class="{ 'logo-box--lg': large, 'logo-box--sm': small }">
    <img v-if="showImage" :src="src" :alt="name" loading="lazy" @error="broken = true" />
    <template v-else>{{ initials(name) }}</template>
  </span>
</template>

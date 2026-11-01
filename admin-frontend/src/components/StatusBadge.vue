<script setup>
import { computed } from 'vue'

const props = defineProps({ status: { type: String, required: true } })
const label = computed(() => {
  const s = props.status.replace(/_/g, ' ').replace(/\bcod\b/gi, 'COD')
  return s.charAt(0).toUpperCase() + s.slice(1)
})
</script>

<template>
  <span class="badge" :class="`badge--${status}`">{{ label }}</span>
</template>

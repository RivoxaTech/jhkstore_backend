<script setup>
defineProps({ rows: { type: Number, default: 6 } })
</script>

<template>
  <div aria-busy="true" aria-label="Loading">
    <div v-for="n in rows" :key="n" class="skeleton sk-row" :style="{ width: `${92 - (n % 3) * 8}%` }" />
  </div>
</template>

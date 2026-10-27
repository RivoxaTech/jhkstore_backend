<script setup>
defineProps({ title: { type: String, required: true }, text: { type: String, default: '' } })
</script>

<template>
  <div class="empty">
    <slot name="icon" />
    <h3>{{ title }}</h3>
    <p v-if="text">{{ text }}</p>
    <div style="margin-top: 16px"><slot /></div>
  </div>
</template>

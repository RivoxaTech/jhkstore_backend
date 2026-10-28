<script setup>
import { PER_PAGE_OPTIONS } from '@/utils/perPage.js'

// "Rows [25]" - how many rows a table shows per page. Sits at the top right of a table.
defineProps({ modelValue: { type: Number, required: true } })
const emit = defineEmits(['update:modelValue'])
</script>

<template>
  <label class="rows-per-page" title="Rows per page">
    <span>Rows</span>
    <select class="select" :value="modelValue" aria-label="Rows per page" @change="emit('update:modelValue', Number($event.target.value))">
      <option v-for="n in PER_PAGE_OPTIONS" :key="n" :value="n">{{ n }}</option>
    </select>
  </label>
</template>

<script setup>
import { ChevronLeft, ChevronRight } from '@lucide/vue'

defineProps({ meta: { type: Object, default: null } })
const emit = defineEmits(['page'])
</script>

<template>
  <div v-if="meta && meta.total > 0" class="pager">
    <span>Showing {{ meta.from }}-{{ meta.to }} of {{ meta.total }}</span>
    <div v-if="meta.last_page > 1" class="pager__btns">
      <button class="btn btn-outline btn-sm" type="button" :disabled="meta.current_page <= 1" @click="emit('page', meta.current_page - 1)"><ChevronLeft :size="16" /> Prev</button>
      <span style="align-self: center; padding: 0 8px">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
      <button class="btn btn-outline btn-sm" type="button" :disabled="meta.current_page >= meta.last_page" @click="emit('page', meta.current_page + 1)">Next <ChevronRight :size="16" /></button>
    </div>
  </div>
</template>

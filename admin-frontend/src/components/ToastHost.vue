<script setup>
import { AlertCircle, CheckCircle2, Info, X } from '@lucide/vue'
import { useUiStore } from '@/stores/ui.js'

const ui = useUiStore()
const icons = { success: CheckCircle2, error: AlertCircle, info: Info }
</script>

<template>
  <div class="toasts" aria-live="polite">
    <div v-for="t in ui.toasts" :key="t.id" class="toast" :class="`toast--${t.type}`" role="status">
      <component :is="icons[t.type] || Info" :size="20" />
      <span style="flex: 1">{{ t.message }}</span>
      <button type="button" aria-label="Dismiss" @click="ui.dismiss(t.id)"><X :size="16" /></button>
    </div>
  </div>
</template>

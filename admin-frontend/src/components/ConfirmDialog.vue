<script setup>
import AppModal from '@/components/AppModal.vue'

defineProps({
  title: { type: String, required: true },
  message: { type: String, default: '' },
  confirmText: { type: String, default: 'Confirm' },
  tone: { type: String, default: 'primary' }, // primary | danger | success
  busy: { type: Boolean, default: false },
})
const emit = defineEmits(['confirm', 'close'])
</script>

<template>
  <AppModal :title="title" @close="emit('close')">
    <p v-if="message" style="color: var(--text-2)">{{ message }}</p>
    <slot />
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn" :class="`btn-${tone}`" type="button" :disabled="busy" @click="emit('confirm')">
        <span v-if="busy" class="spinner" /> {{ confirmText }}
      </button>
    </template>
  </AppModal>
</template>

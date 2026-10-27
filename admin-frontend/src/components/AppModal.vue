<script setup>
import { onBeforeUnmount, onMounted } from 'vue'
import { X } from '@lucide/vue'

const props = defineProps({
  title: { type: String, required: true },
  large: { type: Boolean, default: false },
  persistent: { type: Boolean, default: false }, // true = clicking the backdrop does not close it
})
const emit = defineEmits(['close'])

const onKey = (e) => { if (e.key === 'Escape' && !props.persistent) emit('close') }
onMounted(() => { document.addEventListener('keydown', onKey); document.body.style.overflow = 'hidden' })
onBeforeUnmount(() => { document.removeEventListener('keydown', onKey); document.body.style.overflow = '' })
</script>

<template>
  <div class="modal-wrap" @mousedown.self="!persistent && emit('close')">
    <div class="modal" :class="{ 'modal--lg': large }" role="dialog" aria-modal="true" :aria-label="title">
      <div class="modal__head">
        <h3>{{ title }}</h3>
        <button class="icon-btn" type="button" aria-label="Close" style="width: 34px; height: 34px" @click="emit('close')"><X :size="18" /></button>
      </div>
      <div class="modal__body"><slot /></div>
      <div v-if="$slots.footer" class="modal__foot"><slot name="footer" /></div>
    </div>
  </div>
</template>

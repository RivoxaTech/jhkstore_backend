<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { Check, Copy } from '@lucide/vue'
import { useUiStore } from '@/stores/ui.js'
import { copyText } from '@/utils/clipboard.js'

// A small copy icon next to a phone number, an e-mail, a name or an address: one click puts the text on the clipboard (a tick shows for a moment).
const props = defineProps({ text: { type: String, default: '' }, label: { type: String, default: 'Copy' } })
const ui = useUiStore()
const done = ref(false)
let timer

async function copy() {
  if (!(await copyText(props.text))) return ui.toast('Could not copy.', 'error')
  done.value = true
  clearTimeout(timer)
  timer = setTimeout(() => (done.value = false), 1400)
}
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <button v-if="text" type="button" class="copy-btn" :class="{ 'is-done': done }" :title="done ? 'Copied' : label" :aria-label="`${label}: ${text}`" data-testid="copy" @click.stop="copy">
    <Check v-if="done" :size="13" /><Copy v-else :size="13" />
  </button>
</template>

<style scoped>
.copy-btn { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; color: var(--text-3); vertical-align: middle; flex: none; }
.copy-btn:hover { background: var(--primary-soft); color: var(--primary); }
.copy-btn.is-done { color: var(--success); }
</style>

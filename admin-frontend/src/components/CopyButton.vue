<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { Check, Copy } from '@lucide/vue'
import { useUiStore } from '@/stores/ui.js'

// A small copy icon next to a phone number or an e-mail: one click puts the text on the clipboard (a tick shows for a moment).
const props = defineProps({ text: { type: String, default: '' }, label: { type: String, default: 'Copy' } })
const ui = useUiStore()
const done = ref(false)
let timer

async function copy() {
  const value = String(props.text || '')
  if (!value) return
  let ok = false
  try {
    await navigator.clipboard.writeText(value)
    ok = true
  } catch {
    // http pages and old browsers have no clipboard API: select a hidden field and copy
    const field = document.createElement('textarea')
    field.value = value
    field.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0'
    document.body.appendChild(field)
    field.select()
    try { ok = document.execCommand('copy') } catch { ok = false }
    field.remove()
  }
  if (!ok) return ui.toast('Could not copy.', 'error')
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

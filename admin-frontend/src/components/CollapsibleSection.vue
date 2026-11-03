<script setup>
import { ref, watch } from 'vue'
import { ChevronDown, ChevronRight } from '@lucide/vue'

// A card of the product form that opens and closes. All sections are open when a product is created; when one is edited, each section
// comes back the way this browser left it (remembered per section). A section with a validation error opens by itself.
const props = defineProps({
  id: { type: String, required: true },
  title: { type: String, required: true },
  hint: { type: String, default: '' },
  alwaysOpen: { type: Boolean, default: false },     // true on create
  hasError: { type: Boolean, default: false },
})

const KEY = `admin_pf_${props.id}`
const remembered = () => { try { return localStorage.getItem(KEY) } catch { return null } }
const open = ref(props.alwaysOpen ? true : remembered() !== '0')

function toggle() {
  open.value = !open.value
  if (!props.alwaysOpen) { try { localStorage.setItem(KEY, open.value ? '1' : '0') } catch { /* private mode: not remembered */ } }
}
watch(() => props.hasError, (bad) => { if (bad) open.value = true })
</script>

<template>
  <section :id="id" class="card pf-card" :data-section="id" :data-open="open">
    <button class="card__head pf-toggle" type="button" :aria-expanded="open" :aria-controls="`${id}-body`" @click="toggle">
      <h2><ChevronDown v-if="open" :size="16" /><ChevronRight v-else :size="16" /> {{ title }}<span v-if="hasError" class="pf-bad" title="Needs attention">!</span></h2>
      <span v-if="hint" class="muted">{{ hint }}</span>
      <span class="pf-head-extra" @click.stop><slot name="head" /></span>
    </button>
    <div v-show="open" :id="`${id}-body`" class="card__body"><slot /></div>
  </section>
</template>

<style scoped>
.pf-toggle { width: 100%; text-align: left; cursor: pointer; background: transparent; border-bottom: 1px solid var(--border-light); }
.pf-toggle h2 { display: flex; align-items: center; gap: 6px; }
.pf-bad { background: var(--danger-soft); color: var(--danger); border-radius: 10px; font-size: 11px; padding: 0 7px; margin-left: 6px; }
.pf-head-extra { margin-left: auto; }
</style>

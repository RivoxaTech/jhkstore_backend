<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue'
import { Check, ChevronDown } from '@lucide/vue'

// The status of an order as a pill that opens a small menu (instead of the browser's own drop-down): the current status first, then every status the order may be
// set to now, each with a coloured dot. A choice that is not possible yet is greyed with the reason. The menu is drawn on the page (not inside the table), so a
// table that scrolls never cuts it, and it opens upwards when there is no room below.
defineOptions({ inheritAttrs: false })   // data-testid and the like go to the button, the root is only a wrapper
const props = defineProps({
  status: { type: String, required: true },
  label: { type: String, default: '' },
  options: { type: Array, default: () => [] },   // [{ value, label, hint?, disabled? }]
  disabled: { type: Boolean, default: false },
  ariaLabel: { type: String, default: 'Status' },
  size: { type: String, default: 'sm' },          // sm = the pill of a table row, md = the button of a page
})
const emit = defineEmits(['pick'])

const open = ref(false)
const btn = ref(null)
const pop = ref(null)
const pos = ref({ left: 0, top: 0, minWidth: 0, up: false })
const text = computed(() => props.label || props.status.charAt(0).toUpperCase() + props.status.slice(1))

function place() {
  const r = btn.value.getBoundingClientRect()
  const h = pop.value?.offsetHeight || 220
  const up = r.bottom + h + 12 > window.innerHeight && r.top > h + 12
  pos.value = { left: Math.min(r.left, window.innerWidth - 220), top: up ? r.top - h - 6 : r.bottom + 6, minWidth: Math.max(r.width, 190), up }
}
async function toggle() {
  if (props.disabled) return
  if (open.value) return close()
  open.value = true
  await nextTick()
  place()
  document.addEventListener('mousedown', outside, true)
  document.addEventListener('keydown', keys, true)
  window.addEventListener('scroll', close, true)
  window.addEventListener('resize', close)
}
function close() {
  if (!open.value) return
  open.value = false
  document.removeEventListener('mousedown', outside, true)
  document.removeEventListener('keydown', keys, true)
  window.removeEventListener('scroll', close, true)
  window.removeEventListener('resize', close)
}
function outside(e) { if (!pop.value?.contains(e.target) && !btn.value?.contains(e.target)) close() }
function keys(e) {
  if (e.key === 'Escape') { close(); btn.value?.focus() }
}
function choose(o) {
  if (o.disabled) return
  close()
  if (o.value !== props.status) emit('pick', o.value)
}
onBeforeUnmount(close)
</script>

<template>
  <span class="smenu-root">
  <button
    v-bind="$attrs" ref="btn" type="button" class="smenu" :class="[`smenu--${size}`, `s-${status}`, { 'is-open': open, 'is-static': !options.length }]" :disabled="disabled || !options.length" :aria-label="ariaLabel" aria-haspopup="listbox"
    :aria-expanded="open" @click.stop="toggle"
  >
    <span class="smenu__dot" aria-hidden="true" /><span class="smenu__text">{{ text }}</span><ChevronDown v-if="options.length" :size="14" class="smenu__chev" aria-hidden="true" />
  </button>
  <Teleport to="body">
    <div v-if="open" ref="pop" class="smenu__pop" role="listbox" :style="{ left: `${pos.left}px`, top: `${pos.top}px`, minWidth: `${pos.minWidth}px` }" data-testid="status-menu">
      <button
        v-for="o in options" :key="o.value" type="button" role="option" class="smenu__opt" :class="[`s-${o.value}`, { 'is-disabled': o.disabled, 'is-danger': o.value === 'cancelled' || o.value === 'rejected' }]"
        :aria-selected="o.value === status" :aria-disabled="o.disabled || undefined" :data-status-option="o.value" @click.stop="choose(o)"
      >
        <span class="smenu__dot" aria-hidden="true" />
        <span class="smenu__optText"><span>{{ o.label }}</span><small v-if="o.hint">{{ o.hint }}</small></span>
        <Check v-if="o.value === status" :size="14" aria-hidden="true" />
      </button>
    </div>
  </Teleport>
  </span>
</template>

<style>
.smenu-root { display: inline-flex; max-width: 100%; }
.smenu { display: inline-flex; align-items: center; gap: 7px; max-width: 100%; border: 0; border-radius: 999px; font: inherit; font-weight: 600; cursor: pointer; white-space: nowrap; transition: box-shadow 0.12s, filter 0.12s; }
.smenu--sm { height: 28px; padding: 0 10px 0 9px; font-size: 12.5px; }
.smenu--md { height: 38px; padding: 0 14px 0 12px; font-size: 14px; }
.smenu:hover:not(:disabled) { filter: brightness(0.97); }
.smenu:focus-visible, .smenu.is-open { outline: none; box-shadow: 0 0 0 3px var(--primary-soft, rgba(37, 99, 235, 0.2)); }
.smenu:disabled { cursor: default; opacity: 0.85; }
.smenu.is-static { padding-right: 11px; }
.smenu__dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex: none; }
.smenu__chev { flex: none; opacity: 0.75; transition: transform 0.15s; }
.smenu.is-open .smenu__chev { transform: rotate(180deg); }
.smenu.s-pending { background: var(--warning-soft); color: var(--warning); }
.smenu.s-confirmed { background: var(--primary-soft); color: var(--primary); }
.smenu.s-packed { background: #efe9fd; color: #6d3fd1; }
.smenu.s-shipped { background: #e0f2fe; color: #0369a1; }
.smenu.s-delivered { background: var(--success-soft); color: var(--success); }
.smenu.s-cancelled, .smenu.s-rejected { background: var(--danger-soft); color: var(--danger); }
.smenu__pop { position: fixed; z-index: 300; background: #fff; border: 1px solid var(--border, #e5e7eb); border-radius: 14px; box-shadow: 0 16px 38px -12px rgba(15, 23, 42, 0.3); padding: 6px; display: grid; gap: 2px; }
.smenu__opt { display: flex; align-items: center; gap: 10px; width: 100%; border: 0; background: transparent; padding: 8px 10px; border-radius: 9px; font: inherit; font-size: 13.5px; text-align: left; cursor: pointer; color: var(--text, #111827); }
.smenu__opt:hover:not(.is-disabled) { background: var(--bg, #f3f4f6); }
.smenu__opt .smenu__dot { width: 9px; height: 9px; }
.smenu__opt.s-pending .smenu__dot { color: var(--warning); }
.smenu__opt.s-confirmed .smenu__dot { color: var(--primary); }
.smenu__opt.s-packed .smenu__dot { color: #6d3fd1; }
.smenu__opt.s-shipped .smenu__dot { color: #0369a1; }
.smenu__opt.s-delivered .smenu__dot { color: var(--success); }
.smenu__opt.s-cancelled .smenu__dot, .smenu__opt.s-rejected .smenu__dot { color: var(--danger); }
.smenu__opt.is-danger { color: var(--danger); }
.smenu__optText { flex: 1; min-width: 0; display: grid; }
.smenu__optText small { color: var(--text-2, #6b7280); font-size: 11.5px; }
.smenu__opt.is-disabled { opacity: 0.55; cursor: not-allowed; }
.smenu__opt[aria-selected="true"] { font-weight: 700; }
</style>

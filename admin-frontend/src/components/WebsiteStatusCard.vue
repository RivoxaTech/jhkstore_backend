<script setup>
import { computed, onMounted, ref } from 'vue'
import { Globe, RefreshCw } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { timeAgo } from '@/utils/format.js'

// "Website rebuild": the static storefront is rebuilt by the hosting after a change. This card shows what is going on (changes waiting, when the hosting was
// last asked, how that went) and has the "Rebuild website now" button. The hook address and secret are advanced settings in the fields below the card.
const ui = useUiStore()
const status = ref(null)
const error = ref('')
const working = ref(false)

async function load() {
  error.value = ''
  try { status.value = await api.get('admin/website/status') } catch (e) { error.value = e.message }
}
onMounted(load)
defineExpose({ load })

async function rebuild() {
  working.value = true
  try {
    const res = await api.post('admin/website/rebuild')
    status.value = res
    if (res.result?.ok) ui.toast('The website is being rebuilt. New products show in 1 to 3 minutes.')
    else ui.toast(`The hosting did not accept the request: ${res.result?.error || 'unknown error'}`, 'error')
  } catch (e) {
    ui.toast(e.message, 'error')
    await load()
  } finally {
    working.value = false
  }
}

const last = computed(() => status.value?.last_result)
const rows = computed(() => {
  const s = status.value
  if (!s) return []
  return [
    { id: 'hook', label: 'Rebuild hook', tone: s.hook_configured ? 'ok' : 'warn', text: s.hook_configured ? 'Set' : 'Not set', help: s.hook_configured ? '' : 'Ask your developer to paste the deploy hook of the hosting here (Settings > Website).' },
    { id: 'auto', label: 'Automatic rebuild', tone: s.enabled && s.hook_configured ? 'ok' : 'off', text: s.enabled ? `On, ${s.debounce_seconds} seconds after the last change` : 'Off', help: s.enabled ? '' : 'Changes do not rebuild the website by themselves: use the button.' },
    { id: 'pending', label: 'Changes waiting', tone: s.pending_changes ? 'warn' : 'ok', text: String(s.pending_changes), help: s.waiting ? 'A rebuild is scheduled.' : '' },
    { id: 'requested', label: 'Last rebuild requested', tone: s.last_requested_at ? 'ok' : 'off', text: s.last_requested_at ? timeAgo(s.last_requested_at) : 'Never', help: '' },
    { id: 'result', label: 'Last result', tone: !last.value ? 'off' : last.value.ok ? 'ok' : 'bad', text: !last.value ? '-' : last.value.ok ? 'OK' : 'Failed', help: last.value && !last.value.ok ? last.value.error || '' : '' },
  ]
})
</script>

<template>
  <section class="card wsc" data-section="website-status" data-testid="website-status">
    <div class="card__head">
      <h2><Globe :size="15" /> Website rebuild</h2>
      <button class="btn btn-outline btn-sm" type="button" title="Check again" aria-label="Refresh the status" @click="load"><RefreshCw :size="13" /> Refresh</button>
    </div>
    <div class="card__body">
      <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>
      <div v-if="status?.warning" class="alert alert--error" role="alert" data-testid="website-warning">
        The last rebuild failed{{ last?.error ? `: ${last.error}` : '' }}. The website still shows the previous version. Try again, or ask your developer to check the hook.
      </div>
      <ul class="wsc__rows">
        <li v-for="r in rows" :key="r.id" :data-row="r.id" :data-tone="r.tone">
          <i class="wsc__dot" :class="`is-${r.tone}`" aria-hidden="true" />
          <span class="wsc__label">{{ r.label }}</span>
          <strong class="wsc__text">{{ r.text }}</strong>
          <small v-if="r.help" class="wsc__help">{{ r.help }}</small>
        </li>
      </ul>
      <div class="wsc__actions">
        <button class="btn btn-primary btn-sm" type="button" data-testid="rebuild-now" :disabled="working || !status?.hook_configured" @click="rebuild">
          <span v-if="working" class="spinner" /><Globe v-else :size="14" /> Rebuild website now
        </button>
        <p class="hint">New products, categories and pictures show on the website after the rebuild (1 to 3 minutes). Prices, stock and the cart are always live: they do not wait for it.</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.wsc__rows { list-style: none; display: grid; gap: 2px; }
.wsc__rows li { display: grid; grid-template-columns: 10px minmax(120px, 190px) 1fr; gap: 2px 10px; align-items: baseline; padding: 4px 0; border-bottom: 1px solid var(--border-light); }
.wsc__rows li:last-child { border-bottom: 0; }
.wsc__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); align-self: center; }
.wsc__dot.is-ok { background: var(--success); }
.wsc__dot.is-bad { background: var(--danger); }
.wsc__dot.is-warn { background: var(--warning); }
.wsc__label { color: var(--text-2); }
.wsc__text { font-weight: 500; }
.wsc__help { grid-column: 3; color: var(--text-3); font-size: 11.5px; line-height: 1.35; }
.wsc__actions { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-light); display: grid; gap: 6px; justify-items: start; }
.wsc__actions .hint { font-size: 11.5px; color: var(--text-3); line-height: 1.35; }
.card__head h2 { display: flex; align-items: center; gap: 6px; }
@media (max-width: 560px) {
  .wsc__rows li { grid-template-columns: 10px 1fr; }
  .wsc__text, .wsc__help { grid-column: 2; }
}
</style>

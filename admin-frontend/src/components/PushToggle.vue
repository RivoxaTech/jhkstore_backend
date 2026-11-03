<script setup>
import { computed, onMounted, ref } from 'vue'
import { BellOff, BellRing } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'

// The bell in the header: turns push notifications on or off for THIS browser. The permission question of the browser appears only when this
// button is pressed. Push needs HTTPS (or localhost), a browser that supports it, the VAPID keys in .env and the switch in Settings > Notifications;
// otherwise the button says why it cannot work and the polled badges stay the fallback.
const ui = useUiStore()
const status = ref(null)
const subscribed = ref(false)
const busy = ref(false)

const supported = typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
const SCOPE = import.meta.env.DEV ? '/' : '/admin/'

const state = computed(() => {
  if (!supported) return { ok: false, why: 'This browser (or this address, which needs https) cannot show push notifications. The badges still update every 30 seconds.' }
  if (!status.value) return { ok: false, why: 'Checking push notifications...' }
  if (!status.value.configured) return { ok: false, why: 'Push is not set up on the server yet (ask your developer: php artisan shop:vapid).' }
  if (!status.value.enabled) return { ok: false, why: 'Push notifications are switched off in Settings > Notifications.' }
  if (Notification.permission === 'denied') return { ok: false, why: 'Notifications are blocked for this site in the browser settings.' }
  return { ok: true, why: subscribed.value ? 'Notifications are on for this device. Click to turn them off.' : 'Get a notification on this device for new orders and requests.' }
})

async function registration() {
  return navigator.serviceWorker.register('/admin-sw.js', { scope: SCOPE }).then(() => navigator.serviceWorker.ready)
}

async function refresh() {
  try {
    const reg = supported ? await navigator.serviceWorker.getRegistration(SCOPE) : null
    const current = reg ? await reg.pushManager.getSubscription() : null
    status.value = await api.get('admin/push/status', current ? { endpoint: current.endpoint } : {})
    subscribed.value = Boolean(current) && status.value.subscribed === true
  } catch { /* the bell is optional */ }
}
onMounted(refresh)

const keyBytes = (base64) => {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0))
}

async function toggle() {
  if (busy.value) return
  if (!state.value.ok) { ui.toast(state.value.why, 'info', 6000); return }
  busy.value = true
  try {
    const reg = await registration()
    const existing = await reg.pushManager.getSubscription()
    if (subscribed.value && existing) {
      await api.post('admin/push/unsubscribe', { endpoint: existing.endpoint })
      await existing.unsubscribe()
      subscribed.value = false
      ui.toast('Push notifications are off on this device.', 'info')
      return
    }
    if ((await Notification.requestPermission()) !== 'granted') { ui.toast('Notifications were not allowed.', 'info'); return }
    const sub = existing || await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(status.value.public_key) })
    await api.post('admin/push/subscribe', sub.toJSON())
    subscribed.value = true
    ui.toast('Push notifications are on for this device.')
  } catch (e) {
    ui.toast(e.message || 'Push notifications could not be turned on.', 'error')
  } finally {
    busy.value = false
    refresh()
  }
}
</script>

<template>
  <button class="icon-btn" :class="{ 'is-on': subscribed }" type="button" data-testid="push-toggle" :title="state.why" :aria-label="state.why" :aria-pressed="subscribed" :disabled="busy" @click="toggle">
    <BellRing v-if="subscribed" :size="17" /><BellOff v-else :size="17" />
  </button>
</template>

<style scoped>
.is-on { color: var(--success); border-color: var(--success-soft); background: var(--success-soft); }
</style>

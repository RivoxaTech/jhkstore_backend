<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { BellRing, RefreshCw } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { timeAgo } from '@/utils/format.js'

// "Notification status": everything that has to be true for a push notification to reach this device, one line each, and a button that sends a real
// test notification through the real queue. Used in Settings > Notifications and in the panel header next to the bell.
// Browser side (this device): support, permission, a saved subscription. Server side: VAPID keys, the Settings switch, the queue worker, the last push.
defineProps({ compact: Boolean, bare: Boolean })   // compact = stacked rows; bare = no card border (inside the header popover)
const auth = useAuthStore()

const server = ref(null)       // normalised answer of push/status or notifications/status
const error = ref('')
const device = ref({ supported: false, permission: 'unsupported', subscribed: false, endpoint: '' })
const test = ref({ state: 'idle', text: '' })   // idle | working | ok | error
let pollTimer = null

const SCOPE = import.meta.env.DEV ? '/' : '/admin/'
const canPush = computed(() => auth.can('orders.view') || auth.can('wholesale.view'))

async function readDevice() {
  const supported = typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
  if (!supported) {
    device.value = { supported: false, permission: 'unsupported', subscribed: false, endpoint: '' }
    return
  }
  let sub = null
  try {
    const reg = await navigator.serviceWorker.getRegistration(SCOPE)
    sub = reg ? await reg.pushManager.getSubscription() : null
  } catch { /* shown as "not subscribed" */ }
  device.value = { supported: true, permission: Notification.permission, subscribed: Boolean(sub), endpoint: sub?.endpoint || '' }
}

async function load() {
  error.value = ''
  await readDevice()
  try {
    if (canPush.value) {
      const s = await api.get('admin/push/status', device.value.endpoint ? { endpoint: device.value.endpoint } : {})
      server.value = { configured: s.configured, enabled: s.enabled, queue: s.queue, last: s.last, savedOnServer: s.subscribed === true, devices: s.devices }
    } else if (auth.can('settings.notifications')) {
      const s = await api.get('admin/notifications/status')
      server.value = { configured: s.push.configured, enabled: s.push.enabled, queue: s.queue.state, last: s.push.last, savedOnServer: null, devices: s.push.devices }
    }
  } catch (e) { error.value = e.message }
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(pollTimer))

// ---- the rows: ok = green, bad = red, warn = amber, off = grey
const rows = computed(() => {
  const d = device.value
  const s = server.value
  const list = [
    { id: 'browser', label: 'Browser support', tone: d.supported ? 'ok' : 'bad', text: d.supported ? 'Yes' : 'No', help: d.supported ? '' : 'This browser, or this address (it needs https or localhost), cannot show push notifications.' },
    { id: 'permission', label: 'Permission', tone: d.permission === 'granted' ? 'ok' : d.permission === 'denied' ? 'bad' : 'warn', text: { granted: 'Granted', denied: 'Blocked', default: 'Not asked yet', unsupported: '-' }[d.permission], help: d.permission === 'denied' ? 'Allow notifications for this site in the browser settings (the lock icon next to the address).' : d.permission === 'default' ? 'Press the bell to allow notifications.' : '' },
    { id: 'device', label: 'This device subscribed', tone: d.subscribed && s?.savedOnServer !== false ? 'ok' : 'warn', text: d.subscribed ? (s?.savedOnServer === false ? 'In the browser, not saved on the server' : 'Yes') : 'No', help: d.subscribed ? '' : 'Press the bell to subscribe this device.' },
  ]
  if (s) {
    list.push(
      { id: 'vapid', label: 'Push keys (VAPID)', tone: s.configured ? 'ok' : 'bad', text: s.configured ? 'Configured' : 'Missing', help: s.configured ? '' : 'Ask your developer: php artisan shop:vapid, then put the keys in .env.' },
      { id: 'switch', label: 'Push switched on in Settings', tone: s.enabled ? 'ok' : 'warn', text: s.enabled ? 'On' : 'Off', help: s.enabled ? '' : 'Orders will not notify until you switch it on.' },
      queueRow(s.queue),
      { id: 'last', label: 'Last push sent', tone: !s.last ? 'off' : s.last.error || s.last.sent === 0 ? 'bad' : 'ok', text: lastText(s.last), help: s.last?.error || '' },
    )
  }
  return list
})

function queueRow(state) {
  const map = {
    running: ['ok', 'Running', ''],
    sync: ['ok', 'Not needed (jobs run at once)', ''],
    stopped: ['bad', 'Not running', 'A job has been waiting for over 2 minutes. Start the queue worker: php artisan queue:work.'],
    idle: ['warn', 'No activity seen yet', 'Nothing is waiting, so it cannot be told. Send a test notification to find out.'],
  }
  const [tone, text, help] = map[state] || ['off', '-', '']
  return { id: 'queue', label: 'Queue worker', tone, text, help }
}

function lastText(last) {
  if (!last) return 'Nothing sent yet'
  const when = timeAgo(last.at)
  if (last.error) return `${when}: failed (${last.sent} of ${last.devices} sent)`
  return `${when}: sent to ${last.sent} of ${last.devices} device${last.devices === 1 ? '' : 's'}`
}

// ---- test notification: queued like a real one, polled until a worker has handled it
async function sendTest() {
  clearTimeout(pollTimer)
  test.value = { state: 'working', text: 'Sending through the queue...' }
  let id
  try {
    id = (await api.post('admin/push/test', device.value.endpoint ? { endpoint: device.value.endpoint } : {})).id
  } catch (e) {
    test.value = { state: 'error', text: e.message }
    return
  }
  const started = Date.now()
  const poll = async () => {
    try {
      const r = await api.get(`admin/push/test/${id}`)
      if (r.status === 'sent') { test.value = { state: 'ok', text: 'Sent. A notification should appear now. If you see nothing, check Focus Assist / Do Not Disturb and the browser notification settings.' }; return load() }
      if (['failed', 'partial', 'no_device'].includes(r.status)) { test.value = { state: 'error', text: r.error || 'The push service did not accept the message.' }; return load() }
      if (Date.now() - started > 25000) { test.value = { state: 'error', text: 'Still waiting after 25 seconds: no queue worker took the job. Start it with php artisan queue:work.' }; return load() }
      test.value = { state: 'working', text: r.queue === 'stopped' ? 'Waiting for the queue worker (none seems to be running)...' : 'Waiting for the queue worker...' }
    } catch (e) {
      test.value = { state: 'error', text: e.message }
      return
    }
    pollTimer = setTimeout(poll, 1000)
  }
  pollTimer = setTimeout(poll, 700)
}
</script>

<template>
  <section class="card nsc" :class="{ 'nsc--compact': compact, 'nsc--bare': bare }" data-section="notification-status" data-testid="notification-status">
    <div class="card__head">
      <h2><BellRing :size="15" /> Notification status</h2>
      <button class="btn btn-outline btn-sm" type="button" title="Check again" aria-label="Refresh the status" @click="load"><RefreshCw :size="13" /> Refresh</button>
    </div>
    <div class="card__body">
      <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>
      <ul class="nsc__rows">
        <li v-for="r in rows" :key="r.id" :data-row="r.id" :data-tone="r.tone">
          <i class="nsc__dot" :class="`is-${r.tone}`" aria-hidden="true" />
          <span class="nsc__label">{{ r.label }}</span>
          <strong class="nsc__text">{{ r.text }}</strong>
          <small v-if="r.help" class="nsc__help">{{ r.help }}</small>
        </li>
      </ul>
      <div class="nsc__test">
        <button class="btn btn-primary btn-sm" type="button" data-testid="send-test-push" :disabled="test.state === 'working' || !canPush" :title="canPush ? '' : 'This account does not receive order or wholesale notifications.'" @click="sendTest">
          <span v-if="test.state === 'working'" class="spinner" /><BellRing v-else :size="14" /> Send test notification
        </button>
        <p v-if="!canPush" class="hint">This account does not get order or wholesale notifications, so it has no device to test. (An admin account does.)</p>
        <p v-if="test.text" class="nsc__result" :class="`is-${test.state}`" data-testid="test-push-result" role="status">{{ test.text }}</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.nsc__rows { list-style: none; display: grid; gap: 2px; }
.nsc__rows li { display: grid; grid-template-columns: 10px minmax(120px, 190px) 1fr; gap: 2px 10px; align-items: baseline; padding: 4px 0; border-bottom: 1px solid var(--border-light); }
.nsc__rows li:last-child { border-bottom: 0; }
.nsc__dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-3); align-self: center; }
.nsc__dot.is-ok { background: var(--success); }
.nsc__dot.is-bad { background: var(--danger); }
.nsc__dot.is-warn { background: var(--warning); }
.nsc__label { color: var(--text-2); }
.nsc__text { font-weight: 500; }
.nsc__help { grid-column: 3; color: var(--text-3); font-size: 11.5px; line-height: 1.35; }
.nsc__test { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--border-light); display: grid; gap: 6px; justify-items: start; }
.nsc__result { font-size: 12px; padding: 6px 10px; border-radius: 8px; background: var(--muted-soft); }
.nsc__result.is-ok { background: var(--success-soft); color: #166534; }
.nsc__result.is-error { background: var(--danger-soft); color: #9f1239; }
.nsc__result.is-working { background: var(--primary-soft); color: var(--primary-dark); }
.card__head h2 { display: flex; align-items: center; gap: 6px; }
.nsc--bare { border: 0; box-shadow: none; }
.nsc--compact .nsc__rows li { grid-template-columns: 10px 1fr; }
.nsc--compact .nsc__text { grid-column: 2; }
.nsc--compact .nsc__help { grid-column: 2; }
</style>

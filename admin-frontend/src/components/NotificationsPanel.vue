<script setup>
import { onMounted, ref } from 'vue'
import { Mail, RefreshCw, Send } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'

// The part of Settings > Notifications that is not a setting: how the mail queue is doing (counts only: a failed job's content is never shown),
// whether browser push is set up, and a "send test e-mail" button that sends at once and reports a wrong mail setup.
const ui = useUiStore()
const status = ref(null)
const error = ref('')
const to = ref('')
const sending = ref(false)
const result = ref(null)   // { ok, text }

async function load() {
  error.value = ''
  try { status.value = await api.get('admin/notifications/status') } catch (e) { error.value = e.message }
}
onMounted(load)

async function sendTest() {
  sending.value = true
  result.value = null
  try {
    const res = await api.post('admin/notifications/test-email', to.value.trim() ? { to: to.value.trim() } : {})
    result.value = { ok: true, text: res.message }
    ui.toast(res.message)
  } catch (e) {
    result.value = { ok: false, text: e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="card" data-section="notifications-status">
    <div class="card__head">
      <h2>Mail and push status</h2>
      <button class="btn btn-outline btn-sm" type="button" @click="load"><RefreshCw :size="14" /> Refresh</button>
    </div>
    <div class="card__body">
      <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>
      <dl v-if="status" class="np-grid" data-testid="mail-status">
        <div><dt>Mail server</dt><dd>{{ status.mailer }}<small v-if="status.mailer === 'log'"> (writes to the log: nothing is really sent)</small></dd></div>
        <div><dt>Sender address</dt><dd>{{ status.from_address }}</dd></div>
        <div><dt>Queue</dt><dd>{{ status.queue_connection }}<small v-if="status.queue_connection === 'sync'"> (e-mails are sent while the order is placed)</small></dd></div>
        <div><dt>Waiting in the queue</dt><dd data-testid="pending-jobs">{{ status.pending_jobs }}</dd></div>
        <div>
          <dt>Failed e-mails or notifications</dt>
          <dd data-testid="failed-jobs" :style="status.failed_jobs ? { color: 'var(--danger)' } : null">{{ status.failed_jobs }}<small v-if="status.failed_jobs"> (ask your developer: php artisan queue:failed)</small></dd>
        </div>
        <div><dt>Browser push</dt><dd>{{ !status.push.configured ? 'Not set up on the server' : status.push.enabled ? `On, ${status.push.devices} device(s)` : 'Switched off' }}</dd></div>
      </dl>
      <p v-if="status && status.pending_jobs > 0 && status.queue_connection === 'database'" class="hint" style="margin-top: 8px">
        Jobs wait in the queue until the queue worker (or the cron job) runs. If this number keeps growing, the worker is not running: see docs/NEW_CLIENT.md.
      </p>

      <div class="np-test">
        <label for="test-email"><Mail :size="14" /> Send a test e-mail</label>
        <div class="np-test__row">
          <input id="test-email" v-model="to" class="input" type="email" placeholder="Leave empty to use the owner address" autocomplete="off" />
          <button class="btn btn-primary" type="button" data-testid="send-test-email" :disabled="sending" @click="sendTest"><span v-if="sending" class="spinner" /><Send v-else :size="15" /> Send test</button>
        </div>
        <p v-if="result" :class="result.ok ? 'hint' : 'err'" data-testid="test-email-result" role="status">{{ result.text }}</p>
        <p v-else class="hint">Saved settings are used: save your changes first, then send the test.</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.np-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 16px; }
.np-grid dt { font-size: 11.5px; color: var(--text-3); }
.np-grid dd { font-weight: 500; }
.np-grid small { font-weight: 400; color: var(--text-3); }
.np-test { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-light); }
.np-test label { display: flex; gap: 6px; align-items: center; font-weight: 500; margin-bottom: 6px; }
.np-test__row { display: flex; gap: 8px; max-width: 520px; }
</style>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Mail, RefreshCw, Send } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import { useAuthStore } from '@/stores/auth.js'
import AppModal from '@/components/AppModal.vue'
import NotificationStatusCard from '@/components/NotificationStatusCard.vue'
import SettingField from '@/components/SettingField.vue'

// Settings > Notifications, one compact page: a table of events (who is told: the customer by e-mail, the owner by e-mail, the admins by push), the
// sender card (technical), a test e-mail, a status line for the queue and the failed jobs, and the push "Notification status" card.
// The switches are ordinary settings in `form`: the Settings page saves them with its "Save changes" bar.
const props = defineProps({
  form: { type: Object, required: true },
  fields: { type: Object, required: true },     // key -> field description of every setting the user may see
  events: { type: Array, required: true },      // [{ key, label, customer, owner, push }] (a null channel does not exist for that event)
  errors: { type: Object, default: () => ({}) },
})
const ui = useUiStore()
const auth = useAuthStore()

const SENDER_KEYS = ['mail_from_name', 'mail_reply_to', 'notify_admin_email', 'storefront_track_url']
const sender = computed(() => SENDER_KEYS.map((k) => props.fields[k]).filter(Boolean))
const has = (key) => key && key in props.form
const channelOn = computed(() => ({ customer: Boolean(props.form.mail_enabled), owner: Boolean(props.form.mail_enabled), push: Boolean(props.form.push_enabled) }))
const advanced = computed(() => auth.can('settings.advanced'))

const status = ref(null)
const error = ref('')
const to = ref('')
const sending = ref(false)
const result = ref(null)   // { ok, text }
const failedOpen = ref(false)
const failed = ref(null)

async function load() {
  error.value = ''
  try { status.value = await api.get('admin/notifications/status') } catch (e) { error.value = e.message }
}
onMounted(load)

const WORKER = {
  running: ['ok', 'Queue worker running'],
  sync: ['ok', 'Jobs run at once (no queue worker needed)'],
  stopped: ['bad', 'Queue worker NOT running: e-mails and push messages are waiting'],
  idle: ['warn', 'Queue worker: no activity seen yet (send a test to find out)'],
}
const worker = computed(() => WORKER[status.value?.queue?.state] || ['warn', 'Queue worker: unknown'])

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

async function showFailed() {
  failedOpen.value = true
  failed.value = null
  try { failed.value = await api.get('admin/notifications/failed-jobs') } catch (e) { failed.value = { error: e.message, items: [] } }
}
</script>

<template>
  <div class="np">
    <section class="card np-events" data-section="notification-events">
      <div class="card__head">
        <div>
          <h2>Who is told about what</h2>
          <p class="muted">Switch a message on or off for each event. E-mails need a working mail server and a queue worker.</p>
        </div>
        <div class="np-master">
          <label v-if="has('mail_enabled')" class="np-master__item" for="s-mail_enabled">Send e-mails <span class="switch"><input id="s-mail_enabled" v-model="form.mail_enabled" type="checkbox" data-testid="mail-enabled" /><span /></span></label>
          <label v-if="has('push_enabled')" class="np-master__item" for="s-push_enabled">Admin push <span class="switch"><input id="s-push_enabled" v-model="form.push_enabled" type="checkbox" data-testid="push-enabled" /><span /></span></label>
        </div>
      </div>
      <div class="table-wrap">
        <table class="table table--dense np-table" data-testid="events-table">
          <thead>
            <tr><th>Event</th><th class="c">Customer e-mail</th><th class="c">Owner e-mail alert</th><th class="c">Admin push</th></tr>
          </thead>
          <tbody>
            <tr v-for="e in events" :key="e.key" :data-event="e.key">
              <td><strong>{{ e.label }}</strong></td>
              <td v-for="ch in ['customer', 'owner', 'push']" :key="ch" class="c" :class="{ 'is-off': !channelOn[ch] }">
                <label v-if="has(e[ch])" class="switch" :title="channelOn[ch] ? '' : 'This channel is switched off above'"><input v-model="form[e[ch]]" type="checkbox" :data-switch="e[ch]" :aria-label="`${e.label}: ${ch === 'customer' ? 'customer e-mail' : ch === 'owner' ? 'owner e-mail alert' : 'admin push'}`" /><span /></label>
                <span v-else class="muted" title="Not sent for this event">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="hint np-note">The owner alert goes to the owner address below. Push goes to the browsers of admins who may see orders; the developer account never gets order notifications.</p>
    </section>

    <div class="np-row">
      <section v-if="sender.length" class="card" data-section="notification-sender">
        <div class="card__head"><h2>Sender and owner address <span class="dev-tag">Developer</span></h2></div>
        <div class="card__body np-stack">
          <SettingField v-for="f in sender" :key="f.key" :field="f" :form="form" :errors="errors" />
        </div>
      </section>

      <section class="card" data-section="notifications-status">
        <div class="card__head">
          <h2>Test and queue</h2>
          <button class="btn btn-outline btn-sm" type="button" aria-label="Refresh the queue status" @click="load"><RefreshCw :size="13" /> Refresh</button>
        </div>
        <div class="card__body np-stack">
          <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>
          <div v-if="status" class="np-line" data-testid="mail-status">
            <span class="np-pill" :class="`is-${worker[0]}`" data-testid="queue-state">{{ worker[1] }}</span>
            <span class="np-pill" :class="status.failed_jobs ? 'is-bad' : 'is-ok'" data-testid="failed-jobs">
              {{ status.failed_jobs }} failed job{{ status.failed_jobs === 1 ? '' : 's' }}
              <button v-if="status.failed_jobs && advanced" class="np-link" type="button" data-testid="view-failed" @click="showFailed">view</button>
              <template v-else-if="status.failed_jobs"> (ask your developer)</template>
            </span>
          </div>
          <dl v-if="status && advanced" class="np-tech" data-testid="queue-tech">
            <div><dt>Mailer</dt><dd>{{ status.mailer }}<small v-if="status.mailer === 'log'"> (writes to the log: nothing is sent)</small></dd></div>
            <div><dt>Sender address</dt><dd>{{ status.from_address }}</dd></div>
            <div><dt>Queue</dt><dd>{{ status.queue_connection }}</dd></div>
            <div><dt>Waiting</dt><dd data-testid="pending-jobs">{{ status.pending_jobs }}</dd></div>
          </dl>
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

      <NotificationStatusCard compact />
    </div>

    <AppModal v-if="failedOpen" title="Failed jobs" large @close="failedOpen = false">
      <p v-if="!failed" class="muted">Loading...</p>
      <p v-else-if="failed.error" class="err">{{ failed.error }}</p>
      <template v-else>
        <p class="muted" style="margin-bottom: 8px">The {{ failed.items.length }} most recent of {{ failed.total }}. Only the kind of job and when it failed are shown, never its content. Details: <code>php artisan queue:failed</code> and <code>storage/logs</code>.</p>
        <table class="table table--dense" style="min-width: 0">
          <thead><tr><th>Job</th><th>Queue</th><th>Error type</th><th>When</th></tr></thead>
          <tbody><tr v-for="j in failed.items" :key="j.id"><td>{{ j.job }}</td><td>{{ j.queue }}</td><td class="truncate" :title="j.exception">{{ j.exception || '-' }}</td><td class="nowrap">{{ j.failed_at }}</td></tr></tbody>
        </table>
      </template>
    </AppModal>
  </div>
</template>

<style scoped>
.np { display: grid; gap: 10px; grid-template-columns: minmax(0, 1fr); }
.np-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap: 10px; align-items: start; }
.np-stack { display: grid; gap: 10px; }
.np-events .card__head p { margin-top: 1px; }
.np-master { display: flex; gap: 16px; flex-wrap: wrap; }
.np-master__item { display: flex; align-items: center; gap: 8px; font-weight: 500; margin: 0; }
.np-table { min-width: 520px; }
.np-table td.is-off { opacity: .45; }
.np-table td { height: 38px; }
.np-note { padding: 6px 14px 10px; font-size: 11.5px; color: var(--text-3); }
.np-line { display: flex; gap: 8px; flex-wrap: wrap; }
.np-pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 500; padding: 2px 10px; border-radius: 12px; background: var(--muted-soft); color: var(--text-2); }
.np-pill.is-ok { background: var(--success-soft); color: #166534; }
.np-pill.is-bad { background: var(--danger-soft); color: #9f1239; }
.np-pill.is-warn { background: var(--warning-soft); color: #92400e; }
.np-link { color: var(--primary); text-decoration: underline; font-size: 12px; }
.np-tech { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 6px 14px; }
.np-tech dt { font-size: 11px; color: var(--text-3); }
.np-tech dd { font-weight: 500; overflow-wrap: anywhere; }
.np-tech small { font-weight: 400; color: var(--text-3); }
.np-test label { display: flex; gap: 6px; align-items: center; font-weight: 500; margin-bottom: 5px; }
.np-test__row { display: flex; gap: 8px; }
.np-test .hint { font-size: 11.5px; color: var(--text-3); margin-top: 4px; }
.np-test .err { font-size: 11.5px; color: var(--danger); margin-top: 4px; }
.dev-tag { font-size: 9.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #6d3fd1; background: #efe9fd; border-radius: 4px; padding: 0 5px; line-height: 15px; vertical-align: middle; }
</style>

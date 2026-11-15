<script setup>
import { ref } from 'vue'
import { Mail, Send, Wand2 } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'

// Under the mail account fields of Settings > E-mail: a few common mail services that fill in the server, port and encryption (the person still types
// the user name, the password and the sender address), and a test e-mail that goes through the SAVED account, so a mistake shows up here and not on a customer's order.
const props = defineProps({ form: { type: Object, required: true }, dirty: Boolean })
const ui = useUiStore()

const PRESETS = [
  { name: 'Gmail', host: 'smtp.gmail.com', port: 587, enc: 'tls', note: 'Gmail needs an app password: Google account, Security, 2-step verification, App passwords. The normal password does not work.' },
  { name: 'Outlook', host: 'smtp-mail.outlook.com', port: 587, enc: 'tls', note: 'Use the full address as the user name. A Microsoft 365 account may need SMTP sending switched on by its administrator.' },
  { name: 'Brevo', host: 'smtp-relay.brevo.com', port: 587, enc: 'tls', note: 'The user name and the password are the SMTP login and key from Brevo, SMTP & API. The sender address must be verified in Brevo.' },
  { name: 'Mailgun', host: 'smtp.mailgun.org', port: 587, enc: 'tls', note: 'Use the SMTP credentials of your Mailgun domain.' },
  { name: 'My hosting (cPanel)', host: 'mail.yourdomain.com', port: 465, enc: 'ssl', note: 'Replace yourdomain.com. Create the address in cPanel, Email Accounts, and use its full address and password.' },
]
const note = ref('')
function use(p) {
  props.form.mail_host = p.host
  props.form.mail_port = p.port
  props.form.mail_encryption = p.enc
  note.value = p.note
}

const to = ref('')
const sending = ref(false)
const result = ref(null)
async function sendTest() {
  sending.value = true
  result.value = null
  try {
    const res = await api.post('admin/notifications/test-email', to.value.trim() ? { to: to.value.trim() } : {})
    result.value = { ok: true, text: res.message }
    ui.toast(res.message)
  } catch (e) {
    result.value = { ok: false, text: e.status === 422 ? Object.values(e.errors || {}).flat()[0] || e.message : e.message }
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <section class="card set-card set-card--wide mail-setup" data-section="mail-setup">
    <div class="card__head"><h2><Wand2 :size="14" /> Quick setup and test</h2></div>
    <div class="card__body mail-setup__body">
      <div>
        <div class="mail-setup__label">Fill in the server for a common service</div>
        <div class="mail-setup__chips" role="group" aria-label="Common mail services">
          <button v-for="p in PRESETS" :key="p.name" type="button" class="btn btn-outline btn-sm" :data-preset="p.name" @click="use(p)">{{ p.name }}</button>
        </div>
        <p v-if="note" class="hint" data-testid="mail-preset-note">{{ note }}</p>
        <p v-else class="hint">It fills in the mail server, the port and the encryption. You type the user name, the password and the sender address.</p>
      </div>
      <div class="mail-setup__test">
        <label for="mail-test-to" class="mail-setup__label"><Mail :size="14" /> Send a test e-mail</label>
        <div class="mail-setup__row">
          <input id="mail-test-to" v-model="to" class="input" type="email" placeholder="Leave empty to use the owner address" autocomplete="off" />
          <button class="btn btn-primary" type="button" data-testid="send-test-email" :disabled="sending || dirty" @click="sendTest"><span v-if="sending" class="spinner" /><Send v-else :size="15" /> Send test</button>
        </div>
        <p v-if="dirty" class="hint">You have unsaved changes: save them first, the test uses the saved account.</p>
        <p v-else-if="result" :class="result.ok ? 'hint' : 'err'" data-testid="test-email-result" role="status">{{ result.text }}</p>
        <p v-else class="hint">The test is sent at once with the saved account, so a wrong password or port shows here as an error.</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.mail-setup__body { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }
.mail-setup__label { display: flex; align-items: center; gap: 6px; font-weight: 500; font-size: 12.5px; margin-bottom: 6px; }
.mail-setup__chips { display: flex; flex-wrap: wrap; gap: 6px; }
.mail-setup__row { display: flex; gap: 8px; }
.mail-setup .hint, .mail-setup .err { font-size: 11.5px; margin-top: 6px; }
.mail-setup .hint { color: var(--text-3); }
.mail-setup .err { color: var(--danger); }
@media (max-width: 900px) { .mail-setup__body { grid-template-columns: minmax(0, 1fr); } .mail-setup__row { flex-wrap: wrap; } .mail-setup__row .input { flex: 1 1 100%; } }
</style>

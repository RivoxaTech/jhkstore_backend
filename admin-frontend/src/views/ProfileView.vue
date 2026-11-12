<script setup>
import { computed, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { ShieldCheck } from '@lucide/vue'
import { api } from '@/api/client.js'
import { useAuthStore } from '@/stores/auth.js'
import { useUiStore } from '@/stores/ui.js'
import PasswordInput from '@/components/PasswordInput.vue'

// The signed-in person's own page: who they are, and the change of their password (the current one is asked for).
const auth = useAuthStore()
const ui = useUiStore()
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref({})
const message = ref('')
const busy = ref(false)
const role = computed(() => ({ developer: 'Developer', admin: 'Admin' })[auth.role] || auth.role)
const err = (k) => errors.value[k]?.[0] || ''

async function save() {
  errors.value = {}
  message.value = ''
  if (!form.current_password) errors.value.current_password = ['Enter your current password.']
  if (form.password.length < 8) errors.value.password = ['The new password needs at least 8 characters.']
  else if (form.password !== form.password_confirmation) errors.value.password_confirmation = ['The two new passwords are not the same.']
  if (Object.keys(errors.value).length) return
  busy.value = true
  try {
    await api.put('admin/auth/password', { ...form })
    form.current_password = form.password = form.password_confirmation = ''
    message.value = 'Your password was changed. Use it the next time you sign in.'
    ui.toast('Password changed.')
  } catch (e) {
    if (e.status === 422) errors.value = e.errors
    else if (e.status === 429) errors.value = { current_password: ['Too many tries. Wait a minute and try again.'] }
    else errors.value = { current_password: [e.message] }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Profile</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Profile</span></div>
    </div>
  </div>

  <div class="profile-grid">
    <section class="card">
      <div class="card__body profile-me">
        <span class="avatar avatar--xl">{{ auth.initials }}</span>
        <div>
          <h2>{{ auth.admin?.name || auth.admin?.username }}</h2>
          <p class="muted">@{{ auth.admin?.username }} · {{ role }}</p>
          <p v-if="auth.admin?.email" class="muted">{{ auth.admin.email }}</p>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2><ShieldCheck :size="15" /> Change password</h2></div>
      <form class="card__body profile-form" novalidate data-testid="password-form" @submit.prevent="save">
        <div v-if="message" class="alert alert--success" role="status" data-testid="password-done">{{ message }}</div>
        <div class="field">
          <label for="pw-current">Current password</label>
          <PasswordInput id="pw-current" v-model="form.current_password" autocomplete="current-password" :invalid="!!err('current_password')" />
          <p v-if="err('current_password')" class="err">{{ err('current_password') }}</p>
        </div>
        <div class="field">
          <label for="pw-new">New password</label>
          <PasswordInput id="pw-new" v-model="form.password" autocomplete="new-password" :invalid="!!err('password')" />
          <p v-if="err('password')" class="err">{{ err('password') }}</p>
          <p v-else class="muted hint">At least 8 characters (12 on a live server), not your username.</p>
        </div>
        <div class="field">
          <label for="pw-again">New password again</label>
          <PasswordInput id="pw-again" v-model="form.password_confirmation" autocomplete="new-password" :invalid="!!err('password_confirmation')" />
          <p v-if="err('password_confirmation')" class="err">{{ err('password_confirmation') }}</p>
        </div>
        <div><button class="btn btn-primary" type="submit" :disabled="busy" data-testid="password-save"><span v-if="busy" class="spinner" /> Change password</button></div>
      </form>
    </section>
  </div>
</template>

<style scoped>
.profile-grid { display: grid; gap: 12px; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); align-items: start; max-width: 980px; }
.profile-me { display: flex; align-items: center; gap: 16px; }
.profile-me h2 { font-size: 17px; }
.avatar--xl { width: 64px; height: 64px; font-size: 22px; }
.profile-form { display: grid; gap: 14px; max-width: 420px; }
.hint { font-size: 12px; margin-top: 4px; }
@media (max-width: 900px) { .profile-grid { grid-template-columns: minmax(0, 1fr); } }
</style>

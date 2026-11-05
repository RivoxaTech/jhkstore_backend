<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CheckCircle2, Eye, EyeOff, ShieldCheck } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import BrandLogo from '@/components/BrandLogo.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const username = ref('')
const password = ref('')
const showPassword = ref(false)
const busy = ref(false)
const error = ref('')

async function submit() {
  error.value = ''
  if (!username.value.trim() || !password.value) {
    error.value = 'Enter your username and password.'
    return
  }
  busy.value = true
  try {
    await auth.login(username.value.trim(), password.value)
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') && !route.query.redirect.startsWith('//')
      ? route.query.redirect
      : '/'
    router.replace(redirect)
  } catch (e) {
    error.value = e.firstError?.('login') || (e.status === 429 ? 'Too many attempts. Please wait a minute and try again.' : e.message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="login">
    <section class="login__art" aria-hidden="true">
      <div class="sidebar__head" style="border: 0; padding: 0; position: relative; height: auto">
        <BrandLogo on-dark large />
      </div>
      <div>
        <h2>Manage your whole store in one place.</h2>
        <p>Keep your catalogue organised, follow your orders and tune the store settings.</p>
      </div>
      <ul class="login__points">
        <li><CheckCircle2 :size="18" /> Manage products, categories and stock</li>
        <li><CheckCircle2 :size="18" /> Process orders from placed to delivered</li>
        <li><ShieldCheck :size="18" /> Secure cookie-based sign in</li>
      </ul>
    </section>

    <section class="login__form-side">
      <form class="login__card" novalidate @submit.prevent="submit">
        <div class="login__brand-small"><BrandLogo /></div>
        <h1>Welcome back</h1>
        <p class="sub">Sign in to manage your store</p>

        <div v-if="error" class="alert alert--error" role="alert">{{ error }}</div>

        <div class="field" style="margin-bottom: 16px">
          <label for="username">Username</label>
          <input id="username" v-model="username" class="input" :class="{ 'has-error': error }" autocomplete="username" autofocus placeholder="admin" />
        </div>
        <div class="field" style="margin-bottom: 22px">
          <label for="password">Password</label>
          <div class="pw">
            <input id="password" v-model="password" class="input" :class="{ 'has-error': error }" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" placeholder="Your password" style="padding-right: 44px" />
            <button type="button" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
              <EyeOff v-if="showPassword" :size="18" /><Eye v-else :size="18" />
            </button>
          </div>
        </div>
        <button class="btn btn-primary btn-block" type="submit" :disabled="busy" style="height: 46px">
          <span v-if="busy" class="spinner" /> {{ busy ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>
    </section>
  </div>
</template>

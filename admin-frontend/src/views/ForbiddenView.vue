<script setup>
import { RouterLink } from 'vue-router'
import { ShieldOff } from '@lucide/vue'
import { useAuthStore } from '@/stores/auth.js'
import EmptyState from '@/components/EmptyState.vue'

const auth = useAuthStore()
</script>

<template>
  <EmptyState title="You do not have access to this page" text="Your account does not include it. Ask the developer if you need it.">
    <template #icon><ShieldOff :size="42" /></template>
    <RouterLink v-if="auth.can('dashboard.view')" class="btn btn-primary" :to="{ name: 'dashboard' }">Back to dashboard</RouterLink>
    <RouterLink v-else-if="auth.can('orders.view')" class="btn btn-primary" :to="{ name: 'orders' }">Go to orders</RouterLink>
    <RouterLink v-else-if="auth.can('settings.*')" class="btn btn-primary" :to="{ name: 'settings' }">Go to settings</RouterLink>
  </EmptyState>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import { SearchX } from '@lucide/vue'
import EmptyState from '@/components/EmptyState.vue'
</script>

<template>
  <div style="min-height: 100vh; display: grid; place-items: center; background: var(--bg)">
    <EmptyState title="Page not found" text="The page you are looking for does not exist.">
      <template #icon><SearchX :size="46" /></template>
      <RouterLink class="btn btn-primary" :to="{ name: 'dashboard' }">Back to dashboard</RouterLink>
    </EmptyState>
  </div>
</template>

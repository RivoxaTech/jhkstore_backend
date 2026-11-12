<script setup>
import { ref } from 'vue'
import { Eye, EyeOff } from '@lucide/vue'

// A password box with the eye button that shows / hides what was typed (the sign-in page and the profile page).
defineProps({ modelValue: { type: String, default: '' }, id: { type: String, required: true }, autocomplete: { type: String, default: 'current-password' }, placeholder: { type: String, default: '' }, invalid: Boolean })
defineEmits(['update:modelValue'])
const shown = ref(false)
</script>

<template>
  <div class="pw">
    <input :id="id" class="input" :class="{ 'has-error': invalid }" :type="shown ? 'text' : 'password'" :value="modelValue" :autocomplete="autocomplete" :placeholder="placeholder" @input="$emit('update:modelValue', $event.target.value)" />
    <button type="button" :aria-label="shown ? 'Hide password' : 'Show password'" :aria-pressed="shown" :data-testid="`${id}-eye`" @click="shown = !shown">
      <EyeOff v-if="shown" :size="18" /><Eye v-else :size="18" />
    </button>
  </div>
</template>

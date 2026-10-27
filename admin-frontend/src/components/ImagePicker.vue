<script setup>
import { computed, ref } from 'vue'
import LogoBox from '@/components/LogoBox.vue'

// One optional picture: shows the current one (or a freshly chosen file), with upload / remove buttons.
// Emits `change` with { file, remove } - the parent sends `file` and, when `remove` is true, the remove_* flag.
const props = defineProps({
  label: { type: String, required: true },
  name: { type: String, default: '' },        // used for the initials placeholder
  current: { type: String, default: '' },     // url of the saved picture
  hint: { type: String, default: '' },
  maxMb: { type: Number, default: 4 },
  error: { type: String, default: '' },
})
const emit = defineEmits(['change'])

const preview = ref('')
const file = ref(null)
const removed = ref(false)
const localError = ref('')

const shown = computed(() => preview.value || (removed.value ? '' : props.current))
const hasPicture = computed(() => Boolean(shown.value))

function pick(e) {
  const chosen = e.target.files[0]
  e.target.value = ''
  if (!chosen) return
  if (chosen.size > props.maxMb * 1024 * 1024) { localError.value = `The picture must be ${props.maxMb} MB or smaller.`; return }
  localError.value = ''
  file.value = chosen
  preview.value = URL.createObjectURL(chosen)
  removed.value = false
  emit('change', { file: chosen, remove: false })
}

function drop() {
  file.value = null
  preview.value = ''
  removed.value = true
  localError.value = ''
  emit('change', { file: null, remove: Boolean(props.current) })
}
</script>

<template>
  <div class="field">
    <label>{{ label }}</label>
    <div style="display: flex; gap: 12px; align-items: center">
      <LogoBox :name="name" :src="shown" large />
      <div style="min-width: 0">
        <div style="display: flex; gap: 6px; flex-wrap: wrap">
          <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0">
            {{ hasPicture ? 'Change' : 'Upload' }}
            <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden @change="pick" />
          </label>
          <button v-if="hasPicture" class="btn btn-soft-danger btn-sm" type="button" @click="drop">Remove</button>
        </div>
        <p class="hint">{{ hint || `Optional. PNG, JPG or WEBP, up to ${maxMb} MB.` }}</p>
      </div>
    </div>
    <p v-if="localError || error" class="err">{{ localError || error }}</p>
  </div>
</template>

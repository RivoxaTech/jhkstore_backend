<script setup>
import { computed, ref } from 'vue'
import { api } from '@/api/client.js'
import LogoBox from '@/components/LogoBox.vue'

// One picture that is uploaded the moment it is chosen (logo, favicon, share image): shows the current picture with
// Upload / Change / Remove buttons. `upload` is the description the Settings API gives for the field:
// { slot, field, extensions[], max_kb }. Emits `updated` with the fresh settings payload.
const props = defineProps({
  label: { type: String, required: true },
  url: { type: String, default: '' },
  name: { type: String, default: '' },          // initials shown while there is no picture
  help: { type: String, default: '' },
  upload: { type: Object, required: true },
})
const emit = defineEmits(['updated', 'error', 'done'])

const busy = ref(false)
const error = ref('')

const accept = computed(() => props.upload.extensions.map(e => `.${e}`).join(','))
const maxLabel = computed(() => (props.upload.max_kb >= 1024 ? `${props.upload.max_kb / 1024} MB` : `${props.upload.max_kb} KB`))
const endpoint = computed(() => `admin/settings/${props.upload.slot}`)

async function pick(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  error.value = ''
  const ext = (file.name.split('.').pop() || '').toLowerCase()
  if (!props.upload.extensions.includes(ext)) { error.value = `Use a ${props.upload.extensions.join(', ').toUpperCase()} file.`; return }
  if (file.size > props.upload.max_kb * 1024) { error.value = `The file must be ${maxLabel.value} or smaller.`; return }
  const fd = new FormData()
  fd.append(props.upload.field, file)
  await run(() => api.postForm(endpoint.value, fd), `${props.label} updated.`)
}

const remove = () => run(() => api.del(endpoint.value), `${props.label} removed.`)

async function run(call, message) {
  busy.value = true
  error.value = ''
  try {
    emit('updated', await call(), message)
  } catch (e) {
    error.value = e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="field full">
    <label>{{ label }}</label>
    <div style="display: flex; gap: 14px; align-items: center">
      <LogoBox :name="name" :src="url" large />
      <div style="min-width: 0">
        <div style="display: flex; gap: 6px; flex-wrap: wrap">
          <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0" :aria-disabled="busy">
            <span v-if="busy" class="spinner" /> {{ url ? 'Change' : 'Upload' }}
            <input type="file" :accept="accept" hidden :disabled="busy" :aria-label="`${url ? 'Change' : 'Upload'} ${label}`" @change="pick" />
          </label>
          <button v-if="url" class="btn btn-soft-danger btn-sm" type="button" :disabled="busy" @click="remove">Remove</button>
        </div>
        <p class="hint">{{ help }}</p>
      </div>
    </div>
    <p v-if="error" class="err" role="alert">{{ error }}</p>
  </div>
</template>

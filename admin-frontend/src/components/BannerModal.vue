<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'

// Create / edit a banner. The pictures are sent with the form (multipart); on edit only the fields that changed matter to the API.
const props = defineProps({
  item: { type: Object, default: null },                 // null = create
  placements: { type: Array, required: true },           // [{ key, label }]
  defaultPlacement: { type: String, default: '' },
})
const emit = defineEmits(['close', 'saved'])

const TYPES = ['image/png', 'image/jpeg', 'image/webp']
const DESKTOP_MB = 5
const MOBILE_MB = 3

const isEdit = computed(() => Boolean(props.item))
// the API answers in the shop's time zone; a datetime-local input wants "YYYY-MM-DDTHH:mm"
const local = (iso) => (iso ? iso.slice(0, 16) : '')

const form = reactive({
  placement: props.item?.placement ?? props.defaultPlacement ?? props.placements[0]?.key ?? '',
  title: props.item?.title ?? '',
  subtitle: props.item?.subtitle ?? '',
  button_text: props.item?.button_text ?? '',
  link_url: props.item?.link_url ?? '',
  starts_at: local(props.item?.starts_at),
  ends_at: local(props.item?.ends_at),
  is_active: props.item ? props.item.is_active : true,
})
const desktop = reactive({ file: null, preview: '' })
const mobile = reactive({ file: null, preview: '', remove: false })
const errors = ref({})
const message = ref('')
const busy = ref(false)
const first = ref(null)

onMounted(() => first.value?.focus())
onBeforeUnmount(() => { URL.revokeObjectURL(desktop.preview); URL.revokeObjectURL(mobile.preview) })

const err = (k) => errors.value[k]?.[0] || ''
const clear = (...keys) => { const next = { ...errors.value }; keys.forEach(k => delete next[k]); errors.value = next }

const desktopShown = computed(() => desktop.preview || props.item?.desktop_thumb_url || props.item?.desktop_image_url || '')
const mobileShown = computed(() => mobile.preview || (mobile.remove ? '' : props.item?.mobile_thumb_url || props.item?.mobile_image_url || ''))

function check(file, mb, field) {
  if (!TYPES.includes(file.type)) { errors.value = { ...errors.value, [field]: ['Use a PNG, JPG or WEBP picture.'] }; return false }
  if (file.size > mb * 1024 * 1024) { errors.value = { ...errors.value, [field]: [`The picture must be ${mb} MB or smaller.`] }; return false }
  clear(field)
  return true
}

function pickDesktop(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file || !check(file, DESKTOP_MB, 'desktop_image')) return
  URL.revokeObjectURL(desktop.preview)
  desktop.file = file
  desktop.preview = URL.createObjectURL(file)
}

function pickMobile(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file || !check(file, MOBILE_MB, 'mobile_image')) return
  URL.revokeObjectURL(mobile.preview)
  mobile.file = file
  mobile.preview = URL.createObjectURL(file)
  mobile.remove = false
}

function dropMobile() {
  URL.revokeObjectURL(mobile.preview)
  mobile.file = null
  mobile.preview = ''
  mobile.remove = Boolean(props.item?.mobile_image_url)
}

async function save() {
  errors.value = {}
  message.value = ''
  const found = {}
  if (!form.placement) found.placement = ['Choose where the banner is shown.']
  if (!isEdit.value && !desktop.file) found.desktop_image = ['Choose the banner picture.']
  if (form.starts_at && form.ends_at && form.ends_at < form.starts_at) found.ends_at = ['The end date must be after the start date.']
  if (Object.keys(found).length) { errors.value = Object.fromEntries(Object.entries(found).map(([k, v]) => [k, v])); return }

  const fd = new FormData()
  fd.append('placement', form.placement)
  for (const k of ['title', 'subtitle', 'button_text', 'link_url']) fd.append(k, form[k].trim())
  fd.append('starts_at', form.starts_at)
  fd.append('ends_at', form.ends_at)
  fd.append('is_active', form.is_active ? '1' : '0')
  if (desktop.file) fd.append('desktop_image', desktop.file)
  if (mobile.file) fd.append('mobile_image', mobile.file)
  else if (mobile.remove) fd.append('remove_mobile_image', '1')

  busy.value = true
  try {
    let res
    if (isEdit.value) {
      fd.append('_method', 'PUT')
      res = await api.postForm(`admin/banners/${props.item.id}`, fd)
    } else {
      res = await api.postForm('admin/banners', fd)
    }
    emit('saved', res.data, isEdit.value)
  } catch (e) {
    if (e.status === 422) errors.value = e.errors
    else message.value = e.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AppModal :title="isEdit ? 'Edit banner' : 'Add banner'" persistent large @close="emit('close')">
    <form id="banner-form" novalidate @submit.prevent="save">
      <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
      <div class="form-grid">
        <div class="field full">
          <label for="bplace">Where is it shown? <span class="req">*</span></label>
          <select id="bplace" ref="first" v-model="form.placement" class="select" :class="{ 'has-error': err('placement') }" @change="clear('placement')">
            <option v-for="p in placements" :key="p.key" :value="p.key">{{ p.label }}</option>
          </select>
          <p v-if="err('placement')" class="err">{{ err('placement') }}</p>
          <p v-else class="hint">A spot can hold one banner or several. The store shows a single banner or a slider.</p>
        </div>

        <div class="field full">
          <label>Picture <span class="req">*</span></label>
          <div class="banner-pic" :class="{ 'has-error': err('desktop_image') }">
            <img v-if="desktopShown" :src="desktopShown" alt="Banner picture preview" />
            <span v-else class="muted">No picture yet</span>
          </div>
          <div style="margin-top: 6px">
            <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0">
              {{ desktopShown ? 'Change picture' : 'Choose picture' }}
              <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden aria-label="Banner picture" @change="pickDesktop" />
            </label>
          </div>
          <p v-if="err('desktop_image')" class="err">{{ err('desktop_image') }}</p>
          <p v-else class="hint">Wide picture for computers (for example 1600 × 600). PNG, JPG or WEBP, up to {{ DESKTOP_MB }} MB, at least 300 px wide.</p>
        </div>

        <div class="field full">
          <label>Mobile picture <span class="muted">(optional)</span></label>
          <div class="banner-pic banner-pic--mobile" :class="{ 'has-error': err('mobile_image') }">
            <img v-if="mobileShown" :src="mobileShown" alt="Mobile picture preview" />
            <span v-else class="muted">Not set: phones use the picture above</span>
          </div>
          <div style="margin-top: 6px; display: flex; gap: 6px">
            <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0">
              {{ mobileShown ? 'Change mobile picture' : 'Choose mobile picture' }}
              <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden aria-label="Mobile picture" @change="pickMobile" />
            </label>
            <button v-if="mobileShown" class="btn btn-soft-danger btn-sm" type="button" @click="dropMobile">Remove</button>
          </div>
          <p v-if="err('mobile_image')" class="err">{{ err('mobile_image') }}</p>
          <p v-else class="hint">A taller or squarer picture for phones. Up to {{ MOBILE_MB }} MB.</p>
        </div>

        <div class="field full">
          <label for="btitle">Title</label>
          <input id="btitle" v-model="form.title" class="input" maxlength="160" :class="{ 'has-error': err('title') }" @input="clear('title')" />
          <p v-if="err('title')" class="err">{{ err('title') }}</p>
        </div>
        <div class="field full">
          <label for="bsub">Subtitle</label>
          <input id="bsub" v-model="form.subtitle" class="input" maxlength="255" :class="{ 'has-error': err('subtitle') }" @input="clear('subtitle')" />
          <p v-if="err('subtitle')" class="err">{{ err('subtitle') }}</p>
        </div>
        <div class="field">
          <label for="bbtn">Button text</label>
          <input id="bbtn" v-model="form.button_text" class="input" maxlength="60" placeholder="e.g. Shop now" :class="{ 'has-error': err('button_text') }" @input="clear('button_text')" />
          <p v-if="err('button_text')" class="err">{{ err('button_text') }}</p>
        </div>
        <div class="field">
          <label for="blink">Link</label>
          <input id="blink" v-model="form.link_url" class="input" maxlength="500" placeholder="https://… or /category/phones" :class="{ 'has-error': err('link_url') }" @input="clear('link_url')" />
          <p v-if="err('link_url')" class="err">{{ err('link_url') }}</p>
        </div>
        <div class="field">
          <label for="bstart">Show from</label>
          <input id="bstart" v-model="form.starts_at" class="input" type="datetime-local" :class="{ 'has-error': err('starts_at') }" @input="clear('starts_at', 'ends_at')" />
          <p v-if="err('starts_at')" class="err">{{ err('starts_at') }}</p>
          <p v-else class="hint">Empty = right away.</p>
        </div>
        <div class="field">
          <label for="bend">Show until</label>
          <input id="bend" v-model="form.ends_at" class="input" type="datetime-local" :class="{ 'has-error': err('ends_at') }" @input="clear('ends_at')" />
          <p v-if="err('ends_at')" class="err">{{ err('ends_at') }}</p>
          <p v-else class="hint">Empty = no end. Times are in the store's time zone.</p>
        </div>
        <div class="field full">
          <div style="display: flex; align-items: center; gap: 10px">
            <label class="switch" aria-label="Active"><input v-model="form.is_active" type="checkbox" /><span /></label>
            <div>
              <strong style="font-weight: 500">Active</strong>
              <p class="hint" style="margin: 0">When off, the banner is hidden from customers.</p>
            </div>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="banner-form" :disabled="busy">
        <span v-if="busy" class="spinner" /> {{ isEdit ? 'Save banner' : 'Add banner' }}
      </button>
    </template>
  </AppModal>
</template>

<style scoped>
.banner-pic { border: 1px dashed var(--border); border-radius: 10px; background: var(--muted-soft); min-height: 90px; display: grid; place-items: center; overflow: hidden; }
.banner-pic.has-error { border-color: var(--danger); }
.banner-pic img { display: block; width: 100%; max-height: 190px; object-fit: contain; }
.banner-pic--mobile { max-width: 220px; min-height: 70px; }
.banner-pic--mobile img { max-height: 190px; }
</style>

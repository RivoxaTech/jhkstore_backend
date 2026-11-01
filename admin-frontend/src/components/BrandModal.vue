<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'
import ImagePicker from '@/components/ImagePicker.vue'

// Create / edit a brand. Brands are standalone: a name, a description, an optional logo and banner.
const props = defineProps({
  brand: { type: Object, default: null }, // null = create
})
const emit = defineEmits(['close', 'saved'])

const isEdit = computed(() => Boolean(props.brand))
const form = reactive({
  name: props.brand?.name ?? '',
  description: props.brand?.description ?? '',
  sort_order: props.brand ? props.brand.sort_order : '',
  is_active: props.brand ? props.brand.is_active : true,
})
const files = reactive({ logo: null, banner: null, remove_logo: false, remove_banner: false })
const errors = ref({})
const message = ref('')
const busy = ref(false)
const nameInput = ref(null)

onMounted(() => nameInput.value?.focus())
const err = (k) => errors.value[k]?.[0] || ''

const setLogo = ({ file, remove }) => { files.logo = file; files.remove_logo = remove }
const setBanner = ({ file, remove }) => { files.banner = file; files.remove_banner = remove }

async function save() {
  errors.value = {}
  message.value = ''
  if (!form.name.trim()) { errors.value = { name: ['Brand name is required.'] }; return }

  const fd = new FormData()
  fd.append('name', form.name.trim())
  fd.append('description', form.description.trim())
  if (form.sort_order !== '' && form.sort_order !== null) fd.append('sort_order', form.sort_order)
  fd.append('is_active', form.is_active ? '1' : '0')
  if (files.logo) fd.append('logo', files.logo)
  else if (files.remove_logo) fd.append('remove_logo', '1')
  if (files.banner) fd.append('banner', files.banner)
  else if (files.remove_banner) fd.append('remove_banner', '1')

  busy.value = true
  try {
    let res
    if (isEdit.value) {
      fd.append('_method', 'PUT')
      res = await api.postForm(`admin/brands/${props.brand.id}`, fd)
    } else {
      res = await api.postForm('admin/brands', fd)
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
  <AppModal :title="isEdit ? 'Edit brand' : 'Add brand'" persistent @close="emit('close')">
    <form id="brand-form" novalidate @submit.prevent="save">
      <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
      <div class="form-grid">
        <div class="field full">
          <label for="bname">Brand name <span class="req">*</span></label>
          <input id="bname" ref="nameInput" v-model="form.name" class="input" :class="{ 'has-error': err('name') }" maxlength="160" @input="errors = { ...errors, name: undefined }" />
          <p v-if="err('name')" class="err">{{ err('name') }}</p>
        </div>
        <div class="field full">
          <label for="bdesc">Description</label>
          <textarea id="bdesc" v-model="form.description" class="textarea" rows="2" :class="{ 'has-error': err('description') }" />
          <p v-if="err('description')" class="err">{{ err('description') }}</p>
        </div>
        <ImagePicker label="Logo" :name="form.name" :current="brand?.logo_thumb_url || brand?.logo_url || ''" hint="Square picture. Optional. PNG, JPG or WEBP." :error="err('logo')" @change="setLogo" />
        <ImagePicker label="Banner" :name="form.name" :current="brand?.banner_url || ''" :max-mb="8" hint="Wide picture for the brand page. Optional." :error="err('banner')" @change="setBanner" />
        <div class="field">
          <label for="bsort">Sort order</label>
          <input id="bsort" v-model="form.sort_order" class="input" type="number" min="0" max="65535" placeholder="Last" :class="{ 'has-error': err('sort_order') }" />
          <p v-if="err('sort_order')" class="err">{{ err('sort_order') }}</p>
          <p v-else class="hint">Smaller numbers come first.</p>
        </div>
        <div class="field" style="display: flex; align-items: center; gap: 10px">
          <label class="switch" aria-label="Brand is active"><input v-model="form.is_active" type="checkbox" /><span /></label>
          <div>
            <strong style="font-weight: 500">Active</strong>
            <p class="hint" style="margin: 0">Customers can see this brand when it is on.</p>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="brand-form" :disabled="busy">
        <span v-if="busy" class="spinner" /> {{ isEdit ? 'Save brand' : 'Add brand' }}
      </button>
    </template>
  </AppModal>
</template>

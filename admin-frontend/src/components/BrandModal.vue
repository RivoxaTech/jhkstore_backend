<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'
import LogoBox from '@/components/LogoBox.vue'

const props = defineProps({
  companyId: { type: [Number, String], required: true },
  companyName: { type: String, default: '' },
  companyLogo: { type: String, default: null }, // the default logo
  companyApproved: { type: Boolean, default: false },
  brand: { type: Object, default: null }, // null = create
  countries: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'saved'])

const isEdit = computed(() => Boolean(props.brand))
const form = reactive({
  name: props.brand?.name ?? '',
  origin_country_id: props.brand?.origin_country?.id ?? '',
  description: props.brand?.description ?? '',
  is_active: props.brand ? props.brand.is_active : props.companyApproved,
})
const logo = ref(null)
const removeLogo = ref(false)
const ownLogoUrl = ref(props.brand?.has_own_logo ? (props.brand.logo_thumb_url || props.brand.logo_url) : '')
const chosenPreview = ref('')
const errors = ref({})
const message = ref('')
const busy = ref(false)
const nameInput = ref(null)

onMounted(() => nameInput.value?.focus())
const err = (k) => errors.value[k]?.[0] || ''

// What the brand will show: a newly chosen logo, its own logo, or (default) the company logo.
const shownLogo = computed(() => chosenPreview.value || (removeLogo.value ? '' : ownLogoUrl.value) || props.companyLogo || '')
const usingOwn = computed(() => Boolean(chosenPreview.value || (!removeLogo.value && ownLogoUrl.value)))

function pickLogo(e) {
  const file = e.target.files[0]
  e.target.value = ''
  if (!file) return
  if (file.size > 4 * 1024 * 1024) { errors.value = { ...errors.value, logo: ['The logo must be 4 MB or smaller.'] }; return }
  logo.value = file
  chosenPreview.value = URL.createObjectURL(file)
  removeLogo.value = false
  errors.value = { ...errors.value, logo: undefined }
}

function dropOwnLogo() {
  logo.value = null
  chosenPreview.value = ''
  removeLogo.value = true
}

async function save() {
  errors.value = {}
  message.value = ''
  if (!form.name.trim()) { errors.value = { name: ['Brand name is required.'] }; return }

  const fd = new FormData()
  fd.append('name', form.name.trim())
  fd.append('description', form.description.trim())
  if (form.origin_country_id) fd.append('origin_country_id', form.origin_country_id)
  fd.append('is_active', form.is_active ? '1' : '0')
  if (logo.value) fd.append('logo', logo.value)
  if (removeLogo.value && !logo.value) fd.append('remove_logo', '1')

  busy.value = true
  try {
    let res
    if (isEdit.value) {
      fd.append('_method', 'PUT')
      res = await api.postForm(`admin/brands/${props.brand.id}`, fd)
    } else {
      res = await api.postForm(`admin/companies/${props.companyId}/brands`, fd)
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
          <input id="bname" ref="nameInput" v-model="form.name" class="input" :class="{ 'has-error': err('name') }" maxlength="160" />
          <p v-if="err('name')" class="err">{{ err('name') }}</p>
        </div>
        <div class="field full">
          <label for="bcountry">Country of origin</label>
          <select id="bcountry" v-model="form.origin_country_id" class="select">
            <option value="">Not specified</option>
            <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="field full">
          <label for="bdesc">Description</label>
          <textarea id="bdesc" v-model="form.description" class="textarea" rows="2" />
        </div>
        <div class="field full">
          <label>Brand logo</label>
          <div style="display: flex; gap: 12px; align-items: center">
            <LogoBox :name="form.name || companyName" :src="shownLogo" large />
            <div>
              <p style="margin-bottom: 6px">
                <span v-if="usingOwn" class="badge badge--plain">Own logo</span>
                <span v-else class="badge badge--plain">Using the company logo</span>
              </p>
              <div style="display: flex; gap: 6px; flex-wrap: wrap">
                <label class="btn btn-outline btn-sm" style="cursor: pointer; margin: 0">
                  {{ usingOwn ? 'Change' : 'Upload own logo' }}
                  <input type="file" accept=".png,.jpg,.jpeg,.webp" hidden @change="pickLogo" />
                </label>
                <button v-if="usingOwn" class="btn btn-soft-danger btn-sm" type="button" @click="dropOwnLogo">Remove own logo</button>
              </div>
              <p class="hint">Optional. Without its own logo the brand shows the company logo. PNG, JPG or WEBP, up to 4 MB.</p>
            </div>
          </div>
          <p v-if="err('logo')" class="err">{{ err('logo') }}</p>
        </div>
        <div class="field full" style="display: flex; align-items: center; gap: 10px">
          <label class="switch" aria-label="Brand is live">
            <input v-model="form.is_active" type="checkbox" :disabled="!companyApproved" />
            <span />
          </label>
          <div>
            <strong style="font-weight: 500">Show on the website</strong>
            <p class="hint" style="margin: 0">{{ companyApproved ? 'Customers can see this brand.' : 'Available after the company is approved.' }}</p>
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

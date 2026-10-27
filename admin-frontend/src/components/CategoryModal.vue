<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'
import ImagePicker from '@/components/ImagePicker.vue'

// Create / edit a category, or (when `parent` is given) a sub-category of that category, or a sub-sub-category of that sub-category.
const props = defineProps({
  item: { type: Object, default: null },    // null = create
  parent: { type: Object, default: null },  // a category or a sub-category (it has `category_id`)
})
const emit = defineEmits(['close', 'saved'])

const isSub = computed(() => Boolean(props.parent))
const isEdit = computed(() => Boolean(props.item))
const parentIsSub = computed(() => Boolean(props.parent?.category_id))
const noun = computed(() => (!isSub.value ? 'category' : parentIsSub.value ? 'sub-sub-category' : 'sub-category'))

const form = reactive({
  name: props.item?.name ?? '',
  description: props.item?.description ?? '',
  sort_order: props.item ? props.item.sort_order : '',
  is_active: props.item ? props.item.is_active : true,
  is_top: props.item ? Boolean(props.item.is_top) : false,
})
const files = reactive({ icon: null, image: null, remove_icon: false, remove_image: false })
const errors = ref({})
const message = ref('')
const busy = ref(false)
const nameInput = ref(null)

onMounted(() => nameInput.value?.focus())
const err = (k) => errors.value[k]?.[0] || ''

const setIcon = ({ file, remove }) => { files.icon = file; files.remove_icon = remove }
const setImage = ({ file, remove }) => { files.image = file; files.remove_image = remove }

async function save() {
  errors.value = {}
  message.value = ''
  if (!form.name.trim()) { errors.value = { name: [`The ${noun.value} name is required.`] }; return }

  const fd = new FormData()
  fd.append('name', form.name.trim())
  fd.append('description', form.description.trim())
  if (form.sort_order !== '' && form.sort_order !== null) fd.append('sort_order', form.sort_order)
  fd.append('is_active', form.is_active ? '1' : '0')
  if (!isSub.value) fd.append('is_top', form.is_top ? '1' : '0')
  if (files.icon) fd.append('icon', files.icon)
  else if (files.remove_icon) fd.append('remove_icon', '1')
  if (files.image) fd.append('image', files.image)
  else if (files.remove_image) fd.append('remove_image', '1')

  const base = isSub.value ? 'admin/sub-categories' : 'admin/categories'
  busy.value = true
  try {
    let res
    if (isEdit.value) {
      fd.append('_method', 'PUT')
      res = await api.postForm(`${base}/${props.item.id}`, fd)
    } else {
      res = await api.postForm(!isSub.value ? base : parentIsSub.value ? `admin/sub-categories/${props.parent.id}/children` : `admin/categories/${props.parent.id}/sub-categories`, fd)
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
  <AppModal :title="`${isEdit ? 'Edit' : 'Add'} ${noun}`" persistent large @close="emit('close')">
    <form id="category-form" novalidate @submit.prevent="save">
      <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
      <div class="form-grid">
        <div v-if="isSub" class="field full">
          <label>{{ parentIsSub ? 'Sub-category' : 'Category' }}</label>
          <div class="parent-chip">{{ parent.name }}</div>
        </div>
        <div class="field full">
          <label for="cname">Name <span class="req">*</span></label>
          <input id="cname" ref="nameInput" v-model="form.name" class="input" @input="errors = { ...errors, name: undefined }" :class="{ 'has-error': err('name') }" maxlength="160" />
          <p v-if="err('name')" class="err">{{ err('name') }}</p>
        </div>
        <div class="field full">
          <label for="cdesc">Description</label>
          <textarea id="cdesc" v-model="form.description" class="textarea" rows="2" :class="{ 'has-error': err('description') }" />
          <p v-if="err('description')" class="err">{{ err('description') }}</p>
        </div>
        <ImagePicker label="Icon" :name="form.name" :current="item?.icon_thumb_url || item?.icon_url || ''" hint="Small square picture. Optional." :error="err('icon')" @change="setIcon" />
        <ImagePicker label="Image" :name="form.name" :current="item?.image_url || ''" :max-mb="8" hint="Banner / card picture. Optional." :error="err('image')" @change="setImage" />
        <div class="field">
          <label for="csort">Sort order</label>
          <input id="csort" v-model="form.sort_order" class="input" type="number" min="0" max="65535" placeholder="Last" :class="{ 'has-error': err('sort_order') }" />
          <p v-if="err('sort_order')" class="err">{{ err('sort_order') }}</p>
          <p v-else class="hint">Smaller numbers come first.</p>
        </div>
        <div class="field" style="display: grid; gap: 8px; align-content: start">
          <div style="display: flex; align-items: center; gap: 10px">
            <label class="switch" aria-label="Active"><input v-model="form.is_active" type="checkbox" /><span /></label>
            <div>
              <strong style="font-weight: 500">Active</strong>
              <p class="hint" style="margin: 0">{{ isSub && !parentIsSub ? 'When off, its sub-sub-categories are hidden too.' : isSub ? 'Hidden from customers when off.' : 'When off, its sub-categories are hidden too.' }}</p>
            </div>
          </div>
          <div v-if="!isSub" style="display: flex; align-items: center; gap: 10px">
            <label class="switch" aria-label="Top category"><input v-model="form.is_top" type="checkbox" data-field="is_top" @change="errors = { ...errors, is_top: undefined }" /><span /></label>
            <div>
              <strong style="font-weight: 500">Top category</strong>
              <p class="hint" style="margin: 0">Show on the home screen (max 12).</p>
              <p v-if="err('is_top')" class="err">{{ err('is_top') }}</p>
            </div>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="category-form" :disabled="busy">
        <span v-if="busy" class="spinner" /> {{ isEdit ? `Save ${noun}` : `Add ${noun}` }}
      </button>
    </template>
  </AppModal>
</template>

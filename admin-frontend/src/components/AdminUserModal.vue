<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client.js'
import AppModal from '@/components/AppModal.vue'

// Create / edit an admin user. The username is fixed after creation; a password is only sent when typed.
const props = defineProps({
  item: { type: Object, default: null },   // null = create
  roles: { type: Array, required: true },  // [{ key, label, description }]
})
const emit = defineEmits(['close', 'saved'])

const isEdit = computed(() => Boolean(props.item))
const isSelf = computed(() => Boolean(props.item?.is_self))

const form = reactive({
  username: props.item?.username ?? '',
  name: props.item?.name ?? '',
  email: props.item?.email ?? '',
  role: props.item?.role ?? 'staff',
  password: '',
  is_active: props.item ? props.item.is_active : true,
})
const errors = ref({})
const message = ref('')
const busy = ref(false)
const first = ref(null)
const showPassword = ref(false)

onMounted(() => first.value?.focus())
const err = (k) => errors.value[k]?.[0] || ''
const clear = (...keys) => { const next = { ...errors.value }; keys.forEach(k => delete next[k]); errors.value = next }
const roleHelp = computed(() => props.roles.find(r => r.key === form.role)?.description || '')

async function save() {
  errors.value = {}
  message.value = ''
  const found = {}
  if (!isEdit.value && !form.username.trim()) found.username = ['Enter a username.']
  if (!form.name.trim()) found.name = ['Enter the name.']
  if (!isEdit.value && !form.password) found.password = ['Enter a password (at least 10 characters, with letters and numbers).']
  if (Object.keys(found).length) { errors.value = found; return }

  const body = { name: form.name.trim(), email: form.email.trim() || null, role: form.role, is_active: form.is_active }
  if (form.password) body.password = form.password
  if (!isEdit.value) body.username = form.username.trim()

  busy.value = true
  try {
    const res = isEdit.value ? await api.put(`admin/users/${props.item.id}`, body) : await api.post('admin/users', body)
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
  <AppModal :title="isEdit ? `Edit ${item.username}` : 'Add admin user'" persistent @close="emit('close')">
    <form id="user-form" novalidate autocomplete="off" @submit.prevent="save">
      <div v-if="message" class="alert alert--error" role="alert">{{ message }}</div>
      <div class="form-grid">
        <div class="field">
          <label for="uusername">Username <span v-if="!isEdit" class="req">*</span></label>
          <input id="uusername" ref="first" v-model="form.username" class="input" :disabled="isEdit" maxlength="40" autocomplete="off" :class="{ 'has-error': err('username') }" @input="clear('username')" />
          <p v-if="err('username')" class="err">{{ err('username') }}</p>
          <p v-else-if="!isEdit" class="hint">Letters, numbers, dots, dashes. Cannot be changed later.</p>
        </div>
        <div class="field">
          <label for="uname">Name <span class="req">*</span></label>
          <input id="uname" v-model="form.name" class="input" maxlength="120" :class="{ 'has-error': err('name') }" @input="clear('name')" />
          <p v-if="err('name')" class="err">{{ err('name') }}</p>
        </div>
        <div class="field full">
          <label for="uemail">E-mail</label>
          <input id="uemail" v-model="form.email" class="input" type="email" maxlength="190" autocomplete="off" :class="{ 'has-error': err('email') }" @input="clear('email')" />
          <p v-if="err('email')" class="err">{{ err('email') }}</p>
        </div>
        <div class="field full">
          <label for="urole">Role <span class="req">*</span></label>
          <select id="urole" v-model="form.role" class="select" :disabled="isSelf" :class="{ 'has-error': err('role') }" @change="clear('role')">
            <option v-for="r in roles" :key="r.key" :value="r.key">{{ r.label }}</option>
          </select>
          <p v-if="err('role')" class="err">{{ err('role') }}</p>
          <p v-else class="hint">{{ isSelf ? 'You cannot change your own role.' : roleHelp }}</p>
        </div>
        <div class="field full">
          <label for="upass">{{ isEdit ? 'New password' : 'Password' }} <span v-if="!isEdit" class="req">*</span></label>
          <div style="display: flex; gap: 6px">
            <input id="upass" v-model="form.password" class="input" :type="showPassword ? 'text' : 'password'" maxlength="128" autocomplete="new-password" :placeholder="isEdit ? 'Leave empty to keep the current one' : ''" :class="{ 'has-error': err('password') }" @input="clear('password')" />
            <button class="btn btn-outline" type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Hide' : 'Show' }}</button>
          </div>
          <p v-if="err('password')" class="err">{{ err('password') }}</p>
          <p v-else class="hint">At least 10 characters with letters and numbers.<template v-if="isEdit"> A new password signs the user out of API tokens.</template></p>
        </div>
        <div class="field full">
          <div style="display: flex; align-items: center; gap: 10px">
            <label class="switch" aria-label="Active"><input v-model="form.is_active" type="checkbox" :disabled="isSelf" /><span /></label>
            <div>
              <strong style="font-weight: 500">Active</strong>
              <p class="hint" style="margin: 0">{{ isSelf ? 'You cannot deactivate yourself.' : 'An inactive user cannot sign in.' }}</p>
              <p v-if="err('is_active')" class="err">{{ err('is_active') }}</p>
            </div>
          </div>
        </div>
      </div>
    </form>
    <template #footer>
      <button class="btn btn-outline" type="button" :disabled="busy" @click="emit('close')">Cancel</button>
      <button class="btn btn-primary" type="submit" form="user-form" :disabled="busy">
        <span v-if="busy" class="spinner" /> {{ isEdit ? 'Save user' : 'Add user' }}
      </button>
    </template>
  </AppModal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Lock, Save } from '@lucide/vue'
import { api, ApiError } from '@/api/client.js'
import { useUiStore } from '@/stores/ui.js'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Developer only. One switch per permission for the client's `admin` account, and the developer's own customers-list access.
// The server enforces everything (the developer can never get order permissions); this screen just edits the lists.
const ui = useUiStore()

const roles = ref([])            // [{ role, label, groups: [{ key, label, permissions: [{ key, label, enabled, locked }] }] }]
const original = ref({})         // role -> sorted keys that were on when loaded
const on = ref({})               // role -> Set of enabled keys
const loading = ref(true)
const savingRole = ref('')
const error = ref('')

function fill(payload) {
  roles.value = payload.roles
  const enabled = {}
  const saved = {}
  for (const r of payload.roles) {
    const keys = r.groups.flatMap(g => g.permissions).filter(p => p.enabled).map(p => p.key)
    enabled[r.role] = new Set(keys)
    saved[r.role] = [...keys].sort().join(',')
  }
  on.value = enabled
  original.value = saved
}

async function load() {
  loading.value = true
  error.value = ''
  try { fill(await api.get('admin/access-control')) } catch (e) { error.value = e.message } finally { loading.value = false }
}
onMounted(load)

const dirty = (role) => [...on.value[role]].sort().join(',') !== original.value[role]

function toggle(role, key) {
  const set = new Set(on.value[role])
  set.has(key) ? set.delete(key) : set.add(key)
  on.value = { ...on.value, [role]: set }
}

/** Switch a whole section on or off (only the permissions that may be changed). */
function toggleGroup(role, group, value) {
  const set = new Set(on.value[role])
  for (const p of group.permissions) if (!p.locked) value ? set.add(p.key) : set.delete(p.key)
  on.value = { ...on.value, [role]: set }
}
const groupOn = (role, group) => group.permissions.filter(p => !p.locked).every(p => on.value[role].has(p.key))
const groupEditable = (group) => group.permissions.some(p => !p.locked)

async function save(role) {
  savingRole.value = role
  try {
    fill(await api.put(`admin/access-control/${role}`, { permissions: [...on.value[role]] }))
    ui.toast(`Access saved for the ${role}.`)
  } catch (e) {
    ui.toast(e instanceof ApiError ? (Object.values(e.errors).flat()[0] || e.message) : e.message, 'error')
  } finally {
    savingRole.value = ''
  }
}

const admin = computed(() => roles.value.find(r => r.role === 'admin'))
const developer = computed(() => roles.value.find(r => r.role === 'developer'))
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Access Control</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Access Control</span></div>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">{{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="8" />

  <template v-else-if="!error">
    <section v-if="admin" class="card" style="margin-bottom: 16px" data-role="admin">
      <div class="card__head">
        <h2>What the client admin may see</h2>
        <button class="btn btn-primary" type="button" :disabled="!dirty('admin') || savingRole === 'admin'" @click="save('admin')"><span v-if="savingRole === 'admin'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </div>
      <div class="card__body">
        <p class="muted" style="margin-top: 0">Switch a screen, a Settings tab or a button off and it disappears for the admin; the server refuses it as well. Changes are recorded in the activity log.</p>
        <div v-for="g in admin.groups" :key="g.key" class="access-group">
          <div class="access-group__head">
            <strong>{{ g.label }}</strong>
            <label v-if="g.permissions.length > 1" class="muted" style="display: flex; gap: 8px; align-items: center">All
              <span class="switch"><input type="checkbox" :checked="groupOn('admin', g)" :aria-label="`${g.label}: all`" @change="toggleGroup('admin', g, $event.target.checked)" /><span /></span>
            </label>
          </div>
          <div v-for="p in g.permissions" :key="p.key" class="access-row">
            <label :for="`perm-admin-${p.key}`">{{ p.label }}<small class="muted"> · {{ p.key }}</small></label>
            <span class="switch"><input :id="`perm-admin-${p.key}`" type="checkbox" :checked="on.admin.has(p.key)" :disabled="p.locked" :data-permission="p.key" @change="toggle('admin', p.key)" /><span /></span>
          </div>
        </div>
      </div>
    </section>

    <section v-if="developer" class="card" data-role="developer">
      <div class="card__head">
        <h2>Your own access</h2>
        <button class="btn btn-primary" type="button" :disabled="!dirty('developer') || savingRole === 'developer'" @click="save('developer')"><span v-if="savingRole === 'developer'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </div>
      <div class="card__body">
        <p class="muted" style="margin-top: 0">You always have everything except orders: there is no orders screen, order number, total or customer detail for the developer. The customers list holds personal data, so it is off until you switch it on.</p>
        <div v-for="g in developer.groups.filter(groupEditable)" :key="g.key" class="access-group">
          <div class="access-group__head"><strong>{{ g.label }}</strong></div>
          <div v-for="p in g.permissions" :key="p.key" class="access-row">
            <label :for="`perm-developer-${p.key}`">{{ p.label }}<small class="muted"> · {{ p.key }}</small></label>
            <span class="switch"><input :id="`perm-developer-${p.key}`" type="checkbox" :checked="on.developer.has(p.key)" :disabled="p.locked" :data-permission="p.key" @change="toggle('developer', p.key)" /><span /></span>
          </div>
        </div>
        <p class="muted" style="margin-bottom: 0; display: flex; gap: 6px; align-items: center"><Lock :size="14" /> Orders are never available to the developer role.</p>
      </div>
    </section>
  </template>
</template>

<style scoped>
.access-group { border-top: 1px solid var(--border); padding: 10px 0 4px; }
.access-group:first-of-type { border-top: 0; }
.access-group__head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.access-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 0; }
.access-row label { margin: 0; font-weight: 400; }
</style>

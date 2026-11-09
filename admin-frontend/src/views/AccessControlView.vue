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
    <!-- one list per role: a small heading per section, thin lines between sections and rows, the switch always on the right -->
    <section v-if="admin" class="card ac" data-role="admin">
      <header class="ac__head">
        <div>
          <h2>What the client admin may see</h2>
          <p>A screen, a Settings tab or a button that is switched off disappears for the admin and the server refuses it too. Changes are recorded in the activity log.
            <span class="ac-tag ac-tag--client">Client</span> suits the client by default, <span class="ac-tag ac-tag--dev">Developer</span> is technical: switch it on only for a technical client.</p>
        </div>
        <button class="btn btn-primary" type="button" data-testid="save-admin" :disabled="!dirty('admin') || savingRole === 'admin'" @click="save('admin')"><span v-if="savingRole === 'admin'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </header>

      <div v-for="g in admin.groups" :key="g.key" class="ac__section" :data-group="g.key">
        <div class="ac__title">
          <h3>{{ g.label }}</h3>
          <label v-if="g.permissions.length > 1" class="ac__all">All
            <span class="switch"><input type="checkbox" :checked="groupOn('admin', g)" :aria-label="`${g.label}: all`" @change="toggleGroup('admin', g, $event.target.checked)" /><span /></span>
          </label>
        </div>
        <div v-for="p in g.permissions" :key="p.key" class="ac__row" :data-perm-row="p.key">
          <div class="ac__text">
            <label :for="`perm-admin-${p.key}`" class="ac__label">{{ p.label }}<span class="ac-tag" :class="p.tier === 'advanced' ? 'ac-tag--dev' : 'ac-tag--client'" :data-tier="p.tier">{{ p.tier === 'advanced' ? 'Developer' : 'Client' }}</span></label>
            <p v-if="p.covers?.length" class="ac__covers">{{ p.covers.join(' · ') }}</p>
            <details v-if="p.fields?.length" class="ac__fields">
              <summary>{{ p.fields.length }} setting{{ p.fields.length === 1 ? '' : 's' }} covered</summary>
              <ul>
                <li v-for="fl in p.fields" :key="fl.key"><span>{{ fl.label }}<small v-if="fl.tab"> · {{ fl.tab }}</small></span><span class="ac-tag ac-tag--mini" :class="fl.tier === 'advanced' ? 'ac-tag--dev' : 'ac-tag--client'">{{ fl.tier === 'advanced' ? 'Developer' : 'Client' }}</span></li>
              </ul>
            </details>
          </div>
          <span class="switch"><input :id="`perm-admin-${p.key}`" type="checkbox" :checked="on.admin.has(p.key)" :disabled="p.locked" :data-permission="p.key" :title="p.key" @change="toggle('admin', p.key)" /><span /></span>
        </div>
      </div>
    </section>

    <section v-if="developer" class="card ac" data-role="developer">
      <header class="ac__head">
        <div>
          <h2>Your own access</h2>
          <p>You always have everything except orders: there is no orders screen, order number, total or customer detail for the developer. Personal data (customers list, wholesale requests) is off until you switch it on.</p>
        </div>
        <button class="btn btn-primary" type="button" data-testid="save-developer" :disabled="!dirty('developer') || savingRole === 'developer'" @click="save('developer')"><span v-if="savingRole === 'developer'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </header>

      <div v-for="g in developer.groups.filter(groupEditable)" :key="g.key" class="ac__section">
        <div class="ac__title"><h3>{{ g.label }}</h3></div>
        <div v-for="p in g.permissions" :key="p.key" class="ac__row">
          <div class="ac__text">
            <label :for="`perm-developer-${p.key}`" class="ac__label">{{ p.label }}</label>
            <p v-if="p.covers?.length" class="ac__covers">{{ p.covers.join(' · ') }}</p>
          </div>
          <span class="switch"><input :id="`perm-developer-${p.key}`" type="checkbox" :checked="on.developer.has(p.key)" :disabled="p.locked" :data-permission="p.key" :title="p.key" @change="toggle('developer', p.key)" /><span /></span>
        </div>
      </div>
    </section>
  </template>
</template>

<style scoped>
.ac { max-width: 920px; margin-bottom: 18px; padding: 0 20px 6px; }
.ac__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; padding: 16px 0 14px; }
.ac__head h2 { font-size: 15px; font-weight: 600; }
.ac__head p { margin-top: 3px; max-width: 700px; font-size: 12.5px; line-height: 1.55; color: var(--text-2); }
.ac__section { border-top: 1px solid var(--border-light); padding: 12px 0 4px; }
.ac__title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px; }
.ac__title h3 { font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--text-3); }
.ac__all { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 11.5px; color: var(--text-3); }
.ac__row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 9px 0; }
.ac__row + .ac__row { border-top: 1px solid var(--border-light); }
.ac__text { min-width: 0; }
.ac__label { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13.5px; font-weight: 500; cursor: pointer; }
.ac__covers { margin-top: 2px; font-size: 12px; line-height: 1.5; color: var(--text-3); }
.ac__fields { margin-top: 3px; }
.ac__fields summary { font-size: 11.5px; color: var(--primary); cursor: pointer; width: fit-content; }
.ac__fields ul { list-style: none; margin-top: 5px; display: grid; gap: 3px; max-height: 220px; overflow-y: auto; padding: 8px 10px; background: var(--bg); border-radius: 8px; }
.ac__fields li { display: flex; justify-content: space-between; gap: 10px; font-size: 11.5px; align-items: center; color: var(--text-2); }
.ac__fields small { color: var(--text-3); }
.ac-tag { font-size: 10px; font-weight: 600; padding: 0 7px; border-radius: 10px; line-height: 16px; white-space: nowrap; display: inline-block; }
.ac-tag--client { background: var(--success-soft); color: #166534; }
.ac-tag--dev { background: #efe9fd; color: #6d3fd1; }
.ac-tag--mini { font-size: 9.5px; line-height: 14px; padding: 0 5px; flex: none; }
@media (max-width: 640px) { .ac { padding: 0 14px 6px; } .ac__head { flex-direction: column; } }
</style>

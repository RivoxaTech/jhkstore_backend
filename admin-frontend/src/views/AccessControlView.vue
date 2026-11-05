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
    <section v-if="admin" class="ac-role" data-role="admin">
      <div class="ac-role__head">
        <div>
          <h2>What the client admin may see</h2>
          <p class="muted">Switch a screen, a Settings tab or a button off and it disappears for the admin; the server refuses it as well. Changes are recorded in the activity log.
            <span class="ac-tag ac-tag--client">Client</span> permissions suit the client by default; <span class="ac-tag ac-tag--dev">Developer / advanced</span> ones are technical: switch them on only for a technical client.</p>
        </div>
        <button class="btn btn-primary" type="button" data-testid="save-admin" :disabled="!dirty('admin') || savingRole === 'admin'" @click="save('admin')"><span v-if="savingRole === 'admin'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </div>
      <div class="ac-grid">
        <section v-for="g in admin.groups" :key="g.key" class="card ac-group" :data-group="g.key">
          <div class="card__head">
            <h3>{{ g.label }}</h3>
            <label v-if="g.permissions.length > 1" class="muted ac-all">All
              <span class="switch"><input type="checkbox" :checked="groupOn('admin', g)" :aria-label="`${g.label}: all`" @change="toggleGroup('admin', g, $event.target.checked)" /><span /></span>
            </label>
          </div>
          <div v-for="p in g.permissions" :key="p.key" class="ac-perm" :data-perm-row="p.key">
            <div class="ac-perm__main">
              <label :for="`perm-admin-${p.key}`" class="ac-perm__label">{{ p.label }}</label>
              <div class="ac-perm__meta">
                <span class="ac-tag" :class="p.tier === 'advanced' ? 'ac-tag--dev' : 'ac-tag--client'" :data-tier="p.tier">{{ p.tier === 'advanced' ? 'Developer / advanced' : 'Client' }}</span>
                <code>{{ p.key }}</code>
              </div>
              <ul v-if="p.covers?.length" class="ac-covers"><li v-for="c in p.covers" :key="c">{{ c }}</li></ul>
              <details v-if="p.fields?.length" class="ac-fields">
                <summary>{{ p.fields.length }} setting{{ p.fields.length === 1 ? '' : 's' }} covered</summary>
                <ul>
                  <li v-for="fl in p.fields" :key="fl.key">
                    <span>{{ fl.label }}<small v-if="fl.tab" class="muted"> · {{ fl.tab }}</small></span>
                    <span class="ac-tag ac-tag--mini" :class="fl.tier === 'advanced' ? 'ac-tag--dev' : 'ac-tag--client'">{{ fl.tier === 'advanced' ? 'Developer' : 'Client' }}</span>
                  </li>
                </ul>
              </details>
            </div>
            <span class="switch"><input :id="`perm-admin-${p.key}`" type="checkbox" :checked="on.admin.has(p.key)" :disabled="p.locked" :data-permission="p.key" @change="toggle('admin', p.key)" /><span /></span>
          </div>
        </section>
      </div>
    </section>

    <section v-if="developer" class="ac-role" data-role="developer">
      <div class="ac-role__head">
        <div>
          <h2>Your own access</h2>
          <p class="muted">You always have everything except orders: there is no orders screen, order number, total or customer detail for the developer. Personal data (customers list, wholesale requests) is off until you switch it on. Orders are never available to the developer role.</p>
        </div>
        <button class="btn btn-primary" type="button" data-testid="save-developer" :disabled="!dirty('developer') || savingRole === 'developer'" @click="save('developer')"><span v-if="savingRole === 'developer'" class="spinner" /><Save v-else :size="16" /> Save</button>
      </div>
      <div class="ac-grid">
        <section v-for="g in developer.groups.filter(groupEditable)" :key="g.key" class="card ac-group">
          <div class="card__head"><h3>{{ g.label }}</h3></div>
          <div v-for="p in g.permissions" :key="p.key" class="ac-perm">
            <div class="ac-perm__main">
              <label :for="`perm-developer-${p.key}`" class="ac-perm__label">{{ p.label }}</label>
              <div class="ac-perm__meta"><span class="ac-tag ac-tag--client">Client</span><code>{{ p.key }}</code></div>
              <ul v-if="p.covers?.length" class="ac-covers"><li v-for="c in p.covers" :key="c">{{ c }}</li></ul>
            </div>
            <span class="switch"><input :id="`perm-developer-${p.key}`" type="checkbox" :checked="on.developer.has(p.key)" :disabled="p.locked" :data-permission="p.key" @change="toggle('developer', p.key)" /><span /></span>
          </div>
        </section>
      </div>
    </section>
  </template>
</template>

<style scoped>
.ac-role { margin-bottom: 18px; }
.ac-role__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; margin-bottom: 10px; }
.ac-role__head h2 { font-size: 15px; font-weight: 600; }
.ac-role__head p { max-width: 820px; margin-top: 2px; line-height: 1.5; }
.ac-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: start; }
@media (max-width: 1000px) { .ac-grid { grid-template-columns: minmax(0, 1fr); } }
.ac-group .card__head { padding: 8px 14px; }
.ac-group .card__head h3 { font-size: 13px; font-weight: 600; }
.ac-all { display: flex; gap: 8px; align-items: center; margin: 0; }
.ac-perm { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding: 8px 14px; border-bottom: 1px solid var(--border-light); }
.ac-perm:last-child { border-bottom: 0; }
.ac-perm__main { min-width: 0; }
.ac-perm__label { margin: 0; font-weight: 500; font-size: 13px; cursor: pointer; }
.ac-perm__meta { display: flex; gap: 8px; align-items: center; margin-top: 2px; flex-wrap: wrap; }
.ac-perm__meta code { font-size: 11px; color: var(--text-3); }
.ac-covers { list-style: none; margin-top: 3px; display: flex; flex-wrap: wrap; gap: 2px 10px; }
.ac-covers li { font-size: 11.5px; color: var(--text-2); }
.ac-covers li::before { content: '· '; color: var(--text-3); }
.ac-fields { margin-top: 4px; }
.ac-fields summary { font-size: 11.5px; color: var(--primary); cursor: pointer; width: fit-content; }
.ac-fields ul { list-style: none; margin-top: 4px; display: grid; gap: 2px; max-height: 220px; overflow-y: auto; padding: 6px 8px; background: #fafcff; border: 1px solid var(--border-light); border-radius: 8px; }
.ac-fields li { display: flex; justify-content: space-between; gap: 10px; font-size: 11.5px; align-items: center; }
.ac-tag { font-size: 10.5px; font-weight: 600; padding: 0 7px; border-radius: 10px; line-height: 17px; white-space: nowrap; display: inline-block; }
.ac-tag--client { background: var(--success-soft); color: #166534; }
.ac-tag--dev { background: #efe9fd; color: #6d3fd1; }
.ac-tag--mini { font-size: 9.5px; line-height: 14px; padding: 0 5px; flex: none; }
.ac-perm .switch { margin-top: 2px; }
</style>

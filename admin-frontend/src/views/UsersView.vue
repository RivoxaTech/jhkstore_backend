<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { Check, Pencil, Plus, Trash2, Users } from '@lucide/vue'
import { api } from '@/api/client.js'
import { formatDate } from '@/utils/format.js'
import { useUiStore } from '@/stores/ui.js'
import AdminUserModal from '@/components/AdminUserModal.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import EmptyState from '@/components/EmptyState.vue'
import TableSkeleton from '@/components/TableSkeleton.vue'

// Admin users and what each role may do (the roles are fixed: super admin, manager, staff).
const ui = useUiStore()

const users = ref([])
const roles = ref([])          // [{ key, label, description, permissions[] }]
const permissions = ref([])    // [{ key, label }]
const loading = ref(true)      // only until the first answer: later reloads keep the list on screen
const error = ref('')
const modal = ref(null)        // { item } while the modal is open (item null = create)
const toDelete = ref(null)
const busy = ref(false)

async function load() {
  error.value = ''
  try {
    const [list, meta] = await Promise.all([api.get('admin/users'), api.get('admin/roles')])
    users.value = list.data
    roles.value = meta.roles
    permissions.value = meta.permissions
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function onSaved(saved, wasEdit) {
  modal.value = null
  ui.toast(wasEdit ? 'User updated.' : 'User added.')
  await load()
}

async function confirmDelete() {
  busy.value = true
  try {
    await api.del(`admin/users/${toDelete.value.id}`)
    ui.toast('User deleted.')
    toDelete.value = null
    await load()
  } catch (e) {
    toDelete.value = null
    ui.toast(e.status === 422 ? Object.values(e.errors).flat()[0] || e.message : e.message, 'error', 7000)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page-head">
    <div>
      <h1>Users &amp; roles</h1>
      <div class="crumbs"><RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink> › <span>Users &amp; roles</span></div>
    </div>
    <div class="page-actions">
      <button class="btn btn-primary" type="button" :disabled="loading || !roles.length" @click="modal = { item: null }"><Plus :size="17" /> Add user</button>
    </div>
  </div>

  <div v-if="error" class="alert alert--error" role="alert">{{ error }} <button class="btn btn-outline btn-sm" type="button" style="margin-left: auto" @click="load">Retry</button></div>
  <TableSkeleton v-if="loading" :rows="5" />

  <template v-else-if="!error">
    <section class="card" style="margin-bottom: 12px">
      <div v-if="!users.length" class="card__body">
        <EmptyState title="No admin users" text="Add the first user.">
          <template #icon><Users :size="42" /></template>
        </EmptyState>
      </div>
      <div v-else class="table-wrap">
        <table class="table">
          <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last sign-in</th><th /></tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.id">
              <td>
                <div class="cell-main">
                  <div style="min-width: 0">
                    <strong class="truncate">{{ u.name }}<span v-if="u.is_self" class="muted"> (you)</span></strong>
                    <small>@{{ u.username }}<template v-if="u.email"> · {{ u.email }}</template></small>
                  </div>
                </div>
              </td>
              <td>{{ u.role_label || 'No role' }}</td>
              <td><span class="badge" :class="u.is_active ? 'badge--active' : 'badge--inactive'">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
              <td>{{ u.last_login_at ? formatDate(u.last_login_at, true) : 'Never' }}</td>
              <td>
                <div class="row-actions">
                  <button class="btn btn-outline btn-sm btn-icon" type="button" title="Edit" :aria-label="`Edit ${u.username}`" @click="modal = { item: u }"><Pencil :size="14" /></button>
                  <button class="btn btn-soft-danger btn-sm btn-icon" type="button" title="Delete" :disabled="u.is_self" :aria-label="`Delete ${u.username}`" @click="toDelete = u"><Trash2 :size="14" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card">
      <div class="card__head"><h2>What each role can do</h2></div>
      <div class="table-wrap">
        <table class="table" aria-label="Role permissions">
          <thead>
            <tr><th>Permission</th><th v-for="r in roles" :key="r.key" class="tog">{{ r.label }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="p in permissions" :key="p.key">
              <td>{{ p.label }}</td>
              <td v-for="r in roles" :key="r.key" class="tog">
                <Check v-if="r.permissions.includes(p.key)" :size="16" style="color: var(--success)" :aria-label="`${r.label} can`" />
                <span v-else class="muted" :aria-label="`${r.label} cannot`">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="card__body">
        <p v-for="r in roles" :key="r.key" class="hint" style="margin: 0 0 4px"><strong>{{ r.label }}:</strong> {{ r.description }}</p>
      </div>
    </section>
  </template>

  <AdminUserModal v-if="modal" :item="modal.item" :roles="roles" @close="modal = null" @saved="onSaved" />

  <ConfirmDialog
    v-if="toDelete" title="Delete user" confirm-text="Delete" tone="danger" :busy="busy"
    :message="`Delete ${toDelete.name} (@${toDelete.username})? They can no longer sign in.`"
    @confirm="confirmDelete" @close="toDelete = null"
  />
</template>

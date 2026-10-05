<script setup>
import { computed, onMounted, ref } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { accessControlService } from '../services/accessControlService.js'
import { activityLogService } from '../services/activityLogService.js'

const { can } = useStore()
const canAccess = computed(() => can('Access Control', 'view'))
const activeTab = ref('people')
const matrix = ref([])
const people = ref([])
const peopleMeta = ref({ currentPage: 1, lastPage: 1, total: 0 })
const auditRows = ref([])
const isLoading = ref(false)
const isSaving = ref(false)
const loadError = ref('')
const saveError = ref('')
const saveMessage = ref('')
const personSearch = ref('')
const personRoleFilter = ref('all')
const personStatusFilter = ref('active')
const selectedPerson = ref(null)
const selectedRole = ref('')
const roleReason = ref('')
const selectedProfile = ref('')
const dirtyKeys = ref(new Set())

function labelRole(role) {
  if (role === 'super_admin') return 'Super Admin'
  return String(role || '').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

const roleOptions = computed(() => [...new Set(matrix.value.map(row => row.role))]
  .sort((left, right) => labelRole(left).localeCompare(labelRole(right))))
const roleSummary = computed(() => roleOptions.value.map(role => {
  const rows = matrix.value.filter(row => row.role === role)
  return { role, modules: rows.length, approvals: rows.filter(row => row.approve).length, finance: rows.some(row => row.finance) }
}))
const selectedProfileSummary = computed(() => roleSummary.value.find(summary => summary.role === selectedProfile.value) || null)
const selectedPersonRole = computed(() => selectedPerson.value?.role || '')
const hasPermissionChanges = computed(() => dirtyKeys.value.size > 0)
const permissionTable = useTableControls(
  () => matrix.value.filter(row => row.role === selectedProfile.value),
  row => `${row.module} ${row.scope} ${row.view} ${row.create} ${row.approve} ${row.finance}`,
  20
)

function rowKey(row) { return `${row.role}|${row.module}` }
function isSystemRole(role) { return role === 'super_admin' }
function markPermissionDirty(row) {
  dirtyKeys.value = new Set([...dirtyKeys.value, rowKey(row)])
  saveError.value = ''
  saveMessage.value = ''
}
function togglePermission(row, key) {
  if (isSystemRole(row.role)) return
  if (key === 'view' && row.view) {
    row.view = false
    row.create = false
    row.approve = false
    row.finance = false
  } else {
    if (key !== 'view' && !row.view) row.view = true
    row[key] = !row[key]
  }
  markPermissionDirty(row)
}
function onScopeChange(row) { if (!isSystemRole(row.role)) markPermissionDirty(row) }
function choosePerson(person) {
  selectedPerson.value = person
  selectedRole.value = person.role
  roleReason.value = ''
  saveError.value = ''
  saveMessage.value = ''
}

async function loadMatrix() {
  matrix.value = await accessControlService.list()
  if (!roleOptions.value.includes(selectedProfile.value)) selectedProfile.value = roleOptions.value[0] || ''
  dirtyKeys.value = new Set()
}
async function loadPeople(page = 1) {
  const result = await accessControlService.people({
    search: personSearch.value,
    role: personRoleFilter.value === 'all' ? '' : personRoleFilter.value,
    status: personStatusFilter.value === 'all' ? '' : personStatusFilter.value
  }, page)
  people.value = result.data || []
  peopleMeta.value = result.meta || { currentPage: 1, lastPage: 1, total: 0 }
}
async function loadAudit() {
  const result = await activityLogService.list({ module: 'access_control', per_page: 25 })
  auditRows.value = result.logs || []
}
async function refreshWorkspace() {
  if (!canAccess.value) return
  isLoading.value = true
  loadError.value = ''
  try {
    await Promise.all([loadMatrix(), loadPeople(), loadAudit()])
  } catch (error) {
    loadError.value = error.message || 'Could not load access management data.'
  } finally {
    isLoading.value = false
  }
}
async function savePermissions() {
  if (!hasPermissionChanges.value || isSaving.value) return
  isSaving.value = true
  saveError.value = ''
  try {
    matrix.value = await accessControlService.save(matrix.value.filter(row => dirtyKeys.value.has(rowKey(row))))
    dirtyKeys.value = new Set()
    saveMessage.value = 'Role policy saved and recorded in the audit log.'
    await loadAudit()
  } catch (error) {
    saveError.value = error.message || 'Could not save the role policy.'
  } finally {
    isSaving.value = false
  }
}
async function savePersonRole() {
  if (!selectedPerson.value || selectedRole.value === selectedPersonRole.value || isSaving.value) return
  isSaving.value = true
  saveError.value = ''
  try {
    const updated = await accessControlService.assignRole(selectedPerson.value.id, selectedRole.value, roleReason.value.trim())
    people.value = people.value.map(person => person.id === updated.id ? updated : person)
    selectedPerson.value = updated
    roleReason.value = ''
    saveMessage.value = `${updated.name}'s role was updated. Their active sessions were ended.`
    await loadAudit()
  } catch (error) {
    saveError.value = error.message || 'Could not update this employee role.'
  } finally {
    isSaving.value = false
  }
}

onMounted(refreshWorkspace)
</script>

<template>
  <div v-if="canAccess" class="orbit-page space-y-5">
    <header class="orbit-card access-management-intro flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <div class="text-xs font-bold uppercase tracking-wide text-emerald-700">Access management</div>
        <div class="mt-1 text-sm text-slate-600">Manage who has access, the role policies they inherit, and every sensitive access change.</div>
      </div>
      <button type="button" class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold" :disabled="isLoading || isSaving" @click="refreshWorkspace">Refresh</button>
    </header>

    <div class="orbit-tab-list inline-flex max-w-full overflow-x-auto">
      <button type="button" class="orbit-tab px-4 py-2 text-sm font-bold" :class="activeTab === 'people' ? 'orbit-tab-active' : 'orbit-tab-idle'" @click="activeTab = 'people'">People access</button>
      <button type="button" class="orbit-tab px-4 py-2 text-sm font-bold" :class="activeTab === 'roles' ? 'orbit-tab-active' : 'orbit-tab-idle'" @click="activeTab = 'roles'">Role policies</button>
      <button type="button" class="orbit-tab px-4 py-2 text-sm font-bold" :class="activeTab === 'audit' ? 'orbit-tab-active' : 'orbit-tab-idle'" @click="activeTab = 'audit'">Access audit</button>
    </div>

    <div v-if="loadError || saveError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800">{{ loadError || saveError }}</div>
    <div v-if="saveMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ saveMessage }}</div>
    <section v-if="isLoading" class="orbit-card p-6 text-sm text-slate-600">Loading access management…</section>

    <template v-else-if="activeTab === 'people'">
      <section class="orbit-card p-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
          <label class="block flex-1"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Find a person</span><input v-model="personSearch" class="orbit-field mt-2 w-full px-3 py-2 text-sm" placeholder="Name, email, employee code, or role" @keyup.enter="loadPeople()" /></label>
          <label class="block"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Role</span><select v-model="personRoleFilter" class="orbit-field mt-2 px-3 py-2 text-sm"><option value="all">All roles</option><option v-for="role in roleOptions" :key="role" :value="role">{{ labelRole(role) }}</option></select></label>
          <label class="block"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Account</span><select v-model="personStatusFilter" class="orbit-field mt-2 px-3 py-2 text-sm"><option value="all">All statuses</option><option value="active">Active</option><option value="invited">Invited</option><option value="blocked">Blocked</option></select></label>
          <button type="button" class="orbit-button orbit-button-primary px-4 py-2 text-sm font-bold" @click="loadPeople()">Apply</button>
        </div>
      </section>

      <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <section class="orbit-table-card overflow-hidden">
          <div class="table-tools"><div class="table-count">{{ peopleMeta.total }} people</div><div class="text-xs text-slate-500">Roles are assigned once and inherited across the app.</div></div>
          <div class="table-scroll"><table class="orbit-data-table w-full text-sm"><thead><tr><th class="px-4 py-3 text-left">Person</th><th class="px-4 py-3 text-left">Department</th><th class="px-4 py-3 text-left">Role</th><th class="px-4 py-3 text-left">Account</th><th class="px-4 py-3"></th></tr></thead><tbody><tr v-for="person in people" :key="person.id"><td class="px-4 py-3"><div class="font-bold text-slate-900">{{ person.name }}</div><div class="text-xs text-slate-500">{{ person.email }}</div></td><td class="px-4 py-3 text-slate-700">{{ person.department || '—' }}</td><td class="px-4 py-3"><span class="company-hub-badge company-hub-badge-muted">{{ labelRole(person.role) }}</span></td><td class="px-4 py-3 text-slate-700">{{ person.status }}</td><td class="px-4 py-3 text-right"><button type="button" class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold" @click="choosePerson(person)">Manage</button></td></tr><tr v-if="!people.length"><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">No people match these filters.</td></tr></tbody></table></div>
          <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3"><span class="text-xs text-slate-500">Page {{ peopleMeta.currentPage }} of {{ peopleMeta.lastPage || 1 }}</span><div class="flex gap-2"><button class="orbit-button orbit-button-secondary px-3 py-1.5 text-xs font-bold" :disabled="peopleMeta.currentPage <= 1" @click="loadPeople(peopleMeta.currentPage - 1)">Previous</button><button class="orbit-button orbit-button-secondary px-3 py-1.5 text-xs font-bold" :disabled="peopleMeta.currentPage >= peopleMeta.lastPage" @click="loadPeople(peopleMeta.currentPage + 1)">Next</button></div></div>
        </section>
        <aside class="orbit-card p-5">
          <template v-if="selectedPerson"><div class="text-xs font-bold uppercase tracking-wide text-slate-500">Selected person</div><div class="mt-2 text-lg font-extrabold text-slate-900">{{ selectedPerson.name }}</div><div class="text-sm text-slate-600">{{ selectedPerson.email }}</div><div class="mt-5 space-y-4"><label class="block"><span class="text-xs font-bold uppercase tracking-wide text-slate-500">Role profile</span><select v-model="selectedRole" class="orbit-field mt-2 w-full px-3 py-2 text-sm font-semibold"><option v-for="role in roleOptions" :key="role" :value="role">{{ labelRole(role) }}</option></select></label><label class="block"><span class="text-xs font-bold uppercase tracking-wide text-slate-500">Reason (recommended)</span><textarea v-model="roleReason" class="orbit-field mt-2 w-full px-3 py-2 text-sm" rows="3" placeholder="Why is this access changing?"></textarea></label><p class="text-xs leading-relaxed text-slate-500">Saving ends this person’s active sessions so the new role is applied on their next sign-in.</p><button type="button" class="orbit-button orbit-button-primary w-full px-4 py-2.5 text-sm font-bold" :disabled="selectedRole === selectedPersonRole || isSaving" @click="savePersonRole">{{ isSaving ? 'Saving…' : 'Save role change' }}</button></div></template><template v-else><div class="text-sm font-bold text-slate-900">Select a person</div><p class="mt-2 text-sm leading-relaxed text-slate-600">Search the directory, then choose a person to review and change their role.</p></template>
        </aside>
      </div>
    </template>

    <template v-else-if="activeTab === 'roles'">
      <section class="orbit-card p-4"><div class="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(260px,0.7fr)_minmax(0,1fr)] lg:items-end"><label class="block"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">Role policy</span><select v-model="selectedProfile" class="orbit-field mt-2 w-full px-3 py-2 text-sm font-semibold"><option v-for="role in roleOptions" :key="role" :value="role">{{ labelRole(role) }}</option></select></label><div v-if="selectedProfileSummary" class="grid grid-cols-3 gap-3"><div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5"><div class="text-[10px] font-bold uppercase text-slate-500">Modules</div><div class="mt-1 text-lg font-extrabold text-slate-900">{{ selectedProfileSummary.modules }}</div></div><div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5"><div class="text-[10px] font-bold uppercase text-slate-500">Approvals</div><div class="mt-1 text-lg font-extrabold text-slate-900">{{ selectedProfileSummary.approvals }}</div></div><div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5"><div class="text-[10px] font-bold uppercase text-slate-500">Finance</div><div class="mt-1 text-sm font-extrabold" :class="selectedProfileSummary.finance ? 'text-emerald-700' : 'text-slate-500'">{{ selectedProfileSummary.finance ? 'Visible' : 'Not visible' }}</div></div></div></div><p class="mt-4 text-sm text-slate-600">This policy is inherited by every person with the selected role. The Super Admin policy is protected against lockout.</p></section>
      <section class="orbit-table-card overflow-hidden"><div class="table-tools"><input v-model="permissionTable.query.value" class="table-filter-input" :placeholder="`Filter ${labelRole(selectedProfile)} modules…`" /><div class="flex items-center gap-2"><span class="table-count">{{ permissionTable.filteredRows.value.length }} modules</span><button type="button" class="orbit-button orbit-button-primary px-3 py-2 text-xs font-bold" :disabled="!hasPermissionChanges || isSaving" @click="savePermissions">{{ isSaving ? 'Saving…' : `Save${hasPermissionChanges ? ` (${dirtyKeys.size})` : ''}` }}</button></div></div><div class="table-scroll"><table class="orbit-data-table w-full text-sm"><thead><tr><th class="px-4 py-3 text-left">Module</th><th class="px-4 py-3 text-left">Scope</th><th v-for="key in ['view', 'create', 'approve', 'finance']" :key="key" class="px-4 py-3 text-left capitalize">{{ key }}</th></tr></thead><tbody><tr v-for="row in permissionTable.visibleRows.value" :key="rowKey(row)"><td class="px-4 py-3"><div class="font-bold text-slate-900">{{ row.module }}</div><div v-if="isSystemRole(row.role)" class="mt-1 text-[10px] font-semibold uppercase text-slate-500">Protected system policy</div></td><td class="px-4 py-3"><select v-model="row.scope" class="orbit-field px-2 py-1.5 text-xs font-semibold" :disabled="isSystemRole(row.role) || isSaving" @change="onScopeChange(row)"><option value="all">All records</option><option value="owned">Owned only</option><option value="team">Team only</option><option value="self">Self only</option></select></td><td v-for="key in ['view', 'create', 'approve', 'finance']" :key="key" class="px-4 py-3"><button type="button" class="orbit-button min-w-20 px-3 py-2 text-xs font-bold" :class="row[key] ? 'orbit-button-primary' : 'orbit-button-secondary'" :disabled="isSystemRole(row.role) || isSaving" @click="togglePermission(row, key)">{{ row[key] ? 'Enabled' : 'Disabled' }}</button></td></tr></tbody></table></div></section>
    </template>

    <template v-else>
      <section class="orbit-table-card overflow-hidden"><div class="table-tools"><div><div class="text-sm font-bold text-slate-900">Access changes</div><div class="mt-1 text-xs text-slate-500">Server-recorded role and policy changes.</div></div><button type="button" class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold" @click="loadAudit">Refresh audit</button></div><div class="table-scroll"><table class="orbit-data-table w-full text-sm"><thead><tr><th class="px-4 py-3 text-left">When</th><th class="px-4 py-3 text-left">Actor</th><th class="px-4 py-3 text-left">Action</th><th class="px-4 py-3 text-left">Details</th></tr></thead><tbody><tr v-for="log in auditRows" :key="log.id"><td class="px-4 py-3 text-slate-600">{{ log.createdAt }}</td><td class="px-4 py-3 font-semibold text-slate-900">{{ log.actorName }}</td><td class="px-4 py-3 text-slate-700">{{ log.action }}</td><td class="px-4 py-3 text-slate-600">{{ log.details }}</td></tr><tr v-if="!auditRows.length"><td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No access changes have been recorded yet.</td></tr></tbody></table></div></section>
    </template>
  </div>
  <div v-else class="orbit-card p-6"><div class="text-lg font-bold text-slate-900">Access management is available to Super Admin only.</div></div>
</template>

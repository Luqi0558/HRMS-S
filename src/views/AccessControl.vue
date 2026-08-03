<script setup>
import { computed } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const { state, can, logActivity } = useStore()

const canAccess = computed(() => can('Access Control', 'view'))
const permissionTable = useTableControls(
  () => state.rolePermissions,
  row => `${row.role} ${row.module} ${row.scope} ${row.view} ${row.create} ${row.approve} ${row.finance}`,
  10
)

const roleSummary = computed(() => {
  const roles = ['employee', 'manager', 'hr', 'super_admin']
  return roles.map(role => {
    const rows = state.rolePermissions.filter(row => row.role === role)
    return {
      role,
      modules: rows.length,
      approvals: rows.filter(row => row.approve).length,
      finance: rows.some(row => row.finance)
    }
  })
})

function labelRole(role) {
  if (role === 'super_admin') return 'Super Admin'
  return role.replace('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

const scopeOptions = [
  { id: 'all', label: 'All records' },
  { id: 'owned', label: 'Owned only' },
  { id: 'team', label: 'Team only' },
  { id: 'self', label: 'Self only' }
]

function onScopeChange(row) {
  logActivity({
    module: 'access',
    action: 'scope updated',
    details: `${labelRole(row.role)} ${row.module} scope was set to "${row.scope}".`
  })
}

function togglePermission(row, key) {
  if (row.role === 'super_admin' && key === 'view') return
  row[key] = !row[key]
  logActivity({
    module: 'access',
    action: 'permission updated',
    details: `${labelRole(row.role)} ${row.module} ${key} permission was ${row[key] ? 'enabled' : 'disabled'}.`
  })
}
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div>
      <div class="text-sm text-slate-600 mt-1">These permissions drive the UI live. Scope controls which records a role may act on. Backend enforcement still required.</div>
    </div>

    <section class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div v-for="summary in roleSummary" :key="summary.role" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ labelRole(summary.role) }}</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ summary.modules }}</div>
        <div class="text-xs text-slate-600 mt-1">{{ summary.approvals }} approval areas</div>
        <div class="text-xs font-bold mt-2" :class="summary.finance ? 'text-emerald-700' : 'text-slate-500'">
          {{ summary.finance ? 'Finance visible' : 'No finance access' }}
        </div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="table-tools">
        <input v-model="permissionTable.query.value" type="search" placeholder="Filter permissions..." class="table-filter-input" />
        <div class="table-count">{{ permissionTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="permissionTable.loadMoreRows">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Role</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Module</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Scope</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">View</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Create</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Approve</th>
              <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Finance</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in permissionTable.visibleRows.value" :key="`${row.role}-${row.module}`" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td class="px-4 py-3 text-slate-900 font-bold">{{ labelRole(row.role) }}</td>
              <td class="px-4 py-3 text-slate-700">{{ row.module }}</td>
              <td class="px-4 py-3">
                <select
                  v-model="row.scope"
                  :disabled="row.role === 'super_admin'"
                  @change="onScopeChange(row)"
                  class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs font-semibold text-slate-700 disabled:bg-slate-50 disabled:text-slate-400"
                >
                  <option v-for="option in scopeOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                </select>
              </td>
              <td v-for="key in ['view', 'create', 'approve', 'finance']" :key="key" class="px-4 py-3">
                <button
                  @click="togglePermission(row, key)"
                  :class="row[key] ? 'bg-emerald-600 text-white' : 'bg-white text-slate-700 border border-slate-200'"
                  class="min-w-20 rounded-lg px-3 py-2 text-xs font-bold transition-colors"
                >
                  {{ row[key] ? 'Enabled' : 'Disabled' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Access control is available for Super Admin only</div>
  </div>
</template>

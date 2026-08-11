<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import { attendanceService } from '../services/attendanceService.js'
import { leaveService } from '../services/leaveService.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { employeeService } from '../services/employeeService.js'

const {
  state,
  setAttendance,
  setLeaveRequests,
  setMedicalClaims,
  setEmployees,
  formatCurrency,
  formatDuration,
  ticketSeconds,
  projectReceived,
  projectPending,
  projectCommissionEarned,
  can,
  scopedList,
  logActivity
} = useStore()

const reportType = ref(state.currentUser.role === 'manager' ? 'projects' : 'attendance')
const canAccess = computed(() => can('Reports', 'view'))
const employeeFilter = ref('all')
const statusFilter = ref('all')
const dateFrom = ref('')
const dateTo = ref('')

// Each report is offered only if the user can actually view the module behind
// it. Previously this branched on role strings and ended in a catch-all, so
// team_lead fell into the super-admin list and was offered a Finance report
// despite holding no finance permission.
const reportOptions = computed(() =>
  [
    { id: 'attendance', label: 'Attendance', module: 'Attendance', action: 'view' },
    { id: 'leaves', label: 'Leave Requests', module: 'Leave Request', action: 'approve' },
    { id: 'claims', label: 'Medical Claims', module: 'Medical Request', action: 'approve' },
    { id: 'employees', label: 'Employees', module: 'Employees', action: 'view' },
    { id: 'assets', label: 'Assets', module: 'Assets', action: 'view' },
    { id: 'policies', label: 'Policies', module: 'Company Hub', action: 'create' },
    { id: 'projects', label: 'Projects & Tickets', module: 'Projects', action: 'view' },
    { id: 'finance', label: 'Finance', module: 'Finance', action: 'finance' }
  ].filter(option => can(option.module, option.action))
)

watch(reportOptions, options => {
  if (!options.some(option => option.id === reportType.value)) {
    reportType.value = options[0]?.id || 'attendance'
  }
}, { immediate: true })

watch(reportType, () => {
  employeeFilter.value = 'all'
  statusFilter.value = 'all'
  dateFrom.value = ''
  dateTo.value = ''
})

// HR has no Projects row, so this correctly yields [] for HR.
const visibleProjects = computed(() => scopedList('Projects', state.projects))

const visibleEmployees = computed(() => {
  if (state.currentUser.role === 'manager') {
    const teamIds = new Set(visibleProjects.value.flatMap(project => project.teamMembers || []))
    teamIds.add(state.currentUser.id)
    return state.employees.filter(employee => teamIds.has(employee.id))
  }
  return state.employees
})

const attendanceRows = computed(() => state.attendance.map(row => ({
  employee: employeeName(row.employeeId),
  date: row.date,
  checkIn: row.checkIn || '-',
  checkOut: row.checkOut || '-',
  status: row.status || '-',
  device: row.device || '-'
})))

const leaveRows = computed(() => state.leaveRequests.map(row => ({
  employee: employeeName(row.employeeId),
  type: row.type,
  from: row.from,
  to: row.to,
  duration: row.duration || 'full',
  status: row.status || 'pending'
})))

const claimRows = computed(() => state.medicalClaims.map(row => ({
  employee: employeeName(row.employeeId),
  type: row.type,
  amount: row.amount,
  submittedAt: row.submittedAt,
  status: row.status
})))

const projectRows = computed(() => visibleProjects.value.map(project => {
  const tickets = state.tickets.filter(ticket => ticket.projectId === project.id)
  return {
    project: project.name,
    manager: employeeName(project.managerId),
    teamSize: project.teamMembers?.length || 0,
    tickets: tickets.length,
    completed: tickets.filter(ticket => ticket.status === 'done').length,
    logged: formatDuration(tickets.reduce((total, ticket) => total + ticketSeconds(ticket), 0))
  }
}))

const financeRows = computed(() => visibleProjects.value.flatMap(project =>
  (project.modules || []).map(module => ({
    project: module.name,
    category: project.name,
    manager: employeeName(project.managerId),
    budget: module.budget || 0,
    received: projectReceived(module),
    pending: projectPending(module),
    commission: projectCommissionEarned(module),
    status: module.commission?.status || 'pending'
  }))
))

const employeeRows = computed(() => visibleEmployees.value.map(employee => ({
  name: employee.name,
  email: employee.email,
  designation: employee.department,
  role: employee.role,
  status: employee.status
})))

const assetRows = computed(() => state.assets.map(asset => ({
  asset: asset.name,
  category: asset.category,
  serial: asset.serialNumber,
  assignedTo: employeeName(asset.assignedTo),
  condition: asset.condition,
  status: asset.status
})))

const policyRows = computed(() => state.policies.map(policy => ({
  policy: policy.title,
  category: policy.category,
  version: policy.version,
  updatedAt: policy.updatedAt,
  acknowledgment: policy.acknowledgmentRequired ? 'Required' : 'Optional'
})))

const activeRows = computed(() => {
  if (reportType.value === 'attendance') return attendanceRows.value
  if (reportType.value === 'leaves') return leaveRows.value
  if (reportType.value === 'claims') return claimRows.value
  if (reportType.value === 'projects') return projectRows.value
  if (reportType.value === 'finance') return financeRows.value
  if (reportType.value === 'assets') return assetRows.value
  if (reportType.value === 'policies') return policyRows.value
  return employeeRows.value
})

const filteredReportRows = computed(() => {
  return activeRows.value.filter(row => {
    const employeeName = row.employee || row.name || row.manager || ''
    const rowStatus = row.status || ''
    const rowDate = row.date || row.submittedAt || row.from || row.updatedAt || ''
    const matchesEmployee = employeeFilter.value === 'all' || employeeName === employeeFilter.value
    const matchesStatus = statusFilter.value === 'all' || rowStatus === statusFilter.value
    const matchesStart = !dateFrom.value || !rowDate || rowDate >= dateFrom.value
    const matchesEnd = !dateTo.value || !rowDate || rowDate <= dateTo.value
    return matchesEmployee && matchesStatus && matchesStart && matchesEnd
  })
})

const reportEmployees = computed(() =>
  Array.from(new Set(activeRows.value.map(row => row.employee || row.name || row.manager).filter(Boolean))).sort()
)

const reportStatuses = computed(() =>
  Array.from(new Set(activeRows.value.map(row => row.status).filter(Boolean))).sort()
)

const reportTable = useTableControls(
  filteredReportRows,
  row => Object.values(row).join(' '),
  10
)

const reportCards = computed(() => {
  if (state.currentUser.role === 'manager') {
    return [
      { label: 'Projects', value: projectRows.value.length },
      { label: 'Tickets', value: projectRows.value.reduce((total, row) => total + row.tickets, 0) },
      { label: 'Completed', value: projectRows.value.reduce((total, row) => total + row.completed, 0) }
    ]
  }
  return [
    { label: 'Attendance records', value: attendanceRows.value.length },
    { label: 'Leave requests', value: leaveRows.value.length },
    { label: 'Medical claims', value: claimRows.value.length },
    { label: 'Employees', value: employeeRows.value.length },
    { label: 'Assets', value: assetRows.value.length },
    { label: 'Policies', value: policyRows.value.length }
  ]
})

function employeeName(id) {
  return state.employees.find(employee => employee.id === id)?.name || '-'
}

function exportCsv() {
  const rows = reportTable.filteredRows.value
  if (!rows.length) return
  const headers = Object.keys(rows[0])
  const csv = [
    headers.join(','),
    ...rows.map(row => headers.map(header => `"${String(row[header] ?? '').replaceAll('"', '""')}"`).join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${reportType.value}-report.csv`
  link.click()
  URL.revokeObjectURL(url)
  logActivity({
    module: 'reports',
    action: 'report exported',
    details: `${state.currentUser.name} exported ${reportType.value} report.`
  })
}
/**
 * Reports renders attendance, leave and claims — and until now fetched none of
 * them. It read whatever another page happened to have loaded, or the seeded
 * mock data, so an export could silently be built from stale or fake rows.
 *
 * Each fetch is independent and non-fatal: the roles that reach this page hold
 * different permissions, and one 403 should not blank the rest of the report.
 */
onMounted(async () => {
  const [attendance, leave, claims, employees] = await Promise.allSettled([
    attendanceService.list(),
    leaveService.list(),
    medicalClaimService.list(),
    employeeService.list()
  ])

  if (attendance.status === 'fulfilled') setAttendance(attendance.value)
  if (leave.status === 'fulfilled') setLeaveRequests(leave.value)
  if (claims.status === 'fulfilled') setMedicalClaims(claims.value)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
})
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Generate operational reports and export them as CSV.</div>
      </div>
      <div class="flex gap-2">
        <select v-model="reportType" class="table-filter-input min-w-[220px]">
          <option v-for="option in reportOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
        </select>
        <button @click="exportCsv" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
          Export CSV
        </button>
      </div>
    </div>

    <section class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4">
      <div v-for="card in reportCards" :key="card.label" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ card.label }}</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ card.value }}</div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900 capitalize">{{ reportType }} report</div>
        <div class="text-xs text-slate-600 mt-1">{{ reportTable.filteredRows.value.length }} rows ready for export.</div>
      </div>
      <div class="table-tools">
        <input v-model="reportTable.query.value" type="search" placeholder="Search report..." class="table-filter-input" />
        <select v-model="employeeFilter" class="table-filter-input max-w-[220px]">
          <option value="all">All people</option>
          <option v-for="person in reportEmployees" :key="person" :value="person">{{ person }}</option>
        </select>
        <select v-model="statusFilter" class="table-filter-input max-w-[180px]">
          <option value="all">All statuses</option>
          <option v-for="status in reportStatuses" :key="status" :value="status">{{ status }}</option>
        </select>
        <input v-model="dateFrom" type="date" class="table-filter-input max-w-[160px]" />
        <input v-model="dateTo" type="date" class="table-filter-input max-w-[160px]" />
        <div class="table-count">{{ reportTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="reportTable.loadMoreRows">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-200 text-left bg-slate-50/80">
              <th v-for="header in Object.keys(activeRows[0] || {})" :key="header" class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">
                {{ header }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in reportTable.visibleRows.value" :key="index" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
              <td v-for="header in Object.keys(row)" :key="header" class="px-4 py-3 text-slate-700">
                {{ typeof row[header] === 'number' && ['budget', 'received', 'pending', 'commission', 'amount'].includes(header) ? formatCurrency(row[header]) : row[header] }}
              </td>
            </tr>
            <tr v-if="reportTable.filteredRows.value.length === 0">
              <td :colspan="Object.keys(activeRows[0] || {}).length || 1" class="px-4 py-8 text-center text-sm text-slate-500">No report data available.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Reports are not available for this role</div>
  </div>
</template>

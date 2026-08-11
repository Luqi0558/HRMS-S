<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import { employeeService } from '../services/employeeService.js'
import { leaveService } from '../services/leaveService.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { payrollService } from '../services/payrollService.js'

const route = useRoute()
const {
  state, can, logActivity, pushNotification,
  setEmployees, setLeaveRequests, setMedicalClaims, setSalarySlipRequests
} = useStore()
const loadError = ref('')

/**
 * This page had no loader at all — it rendered whatever another page had put in
 * the store, or the seeded mock roster. The detail modal reads leave, claims
 * and payslips too, so all four are fetched here.
 *
 * Independent and non-fatal: the modal degrades to an empty panel for a role
 * that cannot read payroll, rather than failing the whole page.
 */
onMounted(async () => {
  const [employees, leave, claims, payroll] = await Promise.allSettled([
    employeeService.list(),
    leaveService.list(),
    medicalClaimService.list(),
    payrollService.requests()
  ])

  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
  else loadError.value = employees.reason?.message || 'Unable to load employees.'

  if (leave.status === 'fulfilled') setLeaveRequests(leave.value)
  if (claims.status === 'fulfilled') setMedicalClaims(claims.value)
  if (payroll.status === 'fulfilled') setSalarySlipRequests(payroll.value)
})
const showForm = ref(false)
const showDetails = ref(false)
const selectedEmployee = ref(null)
const form = ref({ name: '', email: '', department: 'Junior Software Engineer' })
const sent = ref(false)
const inviteError = ref('')
const canInviteEmployees = () => can('Employees', 'create')
const employeeContextFilter = ref('all')
const employeeContextManagerId = ref(null)
const employeeRows = computed(() =>
  state.employees.filter(employee => {
    if (employeeContextFilter.value === 'onboarding') return employee.status === 'invited' || Boolean(employee.onboarding)
    if (employeeContextFilter.value === 'missing-docs') return missingDocuments(employee).length > 0
    if (employeeContextFilter.value === 'active') return employee.status === 'active'
    if (employeeContextFilter.value === 'team') return managerTeamMemberIds.value.has(employee.id)
    if (employeeContextFilter.value === 'blocked') return employee.status === 'blocked'
    if (employeeContextFilter.value === 'invited') return employee.status === 'invited'
    return true
  })
)
const managerTeamMemberIds = computed(() => {
  const managerId = Number(employeeContextManagerId.value || state.currentUser.id)
  const ids = new Set()
  state.projects
    .filter(project => project.managerId === managerId)
    .forEach(project => {
      ;(project.teamMembers || []).forEach(id => ids.add(id))
      ;(project.modules || []).forEach(module => (module.teamMembers || []).forEach(id => ids.add(id)))
    })
  return ids
})
const employeeTable = useTableControls(
  employeeRows,
  employee => `${employee.name} ${employee.email} ${employee.department} ${employee.role} ${employee.status}`
)

function applyRouteFilters(query = route.query) {
  employeeContextFilter.value = query.filter || 'all'
  employeeContextManagerId.value = query.managerId || null
  employeeTable.query.value = query.search || ''
}

watch(
  () => route.query,
  query => applyRouteFilters(query),
  { immediate: true }
)

// Email is the login identity, so it must be well-formed and unique — a
// duplicate would create two accounts that cannot be told apart at sign-in.
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

function findEmployeeByEmail(email) {
  const normalised = String(email || '').trim().toLowerCase()
  return state.employees.find(employee => (employee.email || '').trim().toLowerCase() === normalised)
}

function validateInvite() {
  const name = form.value.name.trim()
  const email = form.value.email.trim()
  if (!name) return 'Please enter the employee name.'
  if (!email) return 'Please enter an office email address.'
  if (!EMAIL_PATTERN.test(email)) return 'Enter a valid email address, e.g. name@company.com.'
  const existing = findEmployeeByEmail(email)
  if (existing) return `${existing.name} already uses this email address.`
  if (!form.value.department) return 'Please select a designation.'
  return ''
}

function openInviteForm() {
  inviteError.value = ''
  form.value = { name: '', email: '', department: 'Junior Software Engineer' }
  showForm.value = true
}

function invite() {
  if (!canInviteEmployees()) return
  inviteError.value = validateInvite()
  if (inviteError.value) return
  state.employees.push({
    // Derive from the highest existing id so deletions can't cause a collision.
    id: state.employees.reduce((max, employee) => Math.max(max, employee.id), 0) + 1,
    name: form.value.name.trim(),
    email: form.value.email.trim(),
    role: 'employee',
    department: form.value.department,
    status: 'invited'
  })
  logActivity({
    module: 'employees',
    action: 'employee invited',
    details: `${state.currentUser.name} invited ${form.value.name.trim()} (${form.value.email.trim()}).`
  })
  sent.value = true
  setTimeout(() => {
    showForm.value = false
    sent.value = false
    inviteError.value = ''
    form.value = { name: '', email: '', department: 'Junior Software Engineer' }
  }, 1400)
}

function openDetails(employee) {
  selectedEmployee.value = employee
  showDetails.value = true
}

function closeDetails() {
  showDetails.value = false
  selectedEmployee.value = null
}

function employeeClaims(employeeId) {
  return state.medicalClaims.filter(c => c.employeeId === employeeId)
}

function employeeLeaves(employeeId) {
  return state.leaveRequests.filter(l => l.employeeId === employeeId)
}

function employeeSalarySlips(employeeId) {
  return state.salarySlipRequests.filter(s => s.employeeId === employeeId)
}

function employeeTickets(employeeId) {
  return state.tickets.filter(ticket => ticket.assigneeId === employeeId)
}

function employeeProjects(employeeId) {
  const projectIds = new Set(employeeTickets(employeeId).map(ticket => ticket.projectId))
  state.projects.forEach(project => {
    if (project.managerId === employeeId || project.teamMembers?.includes(employeeId)) projectIds.add(project.id)
  })
  return state.projects.filter(project => projectIds.has(project.id))
}

function canViewOnboardingDetails() {
  return can('Employees', 'create')
}

function onboardingValue(section, key) {
  return selectedEmployee.value?.onboarding?.[section]?.[key] || '-'
}

function onboardingList(section, key) {
  return selectedEmployee.value?.onboarding?.[section]?.[key] || []
}

function documentRows(employee) {
  const documents = employee?.onboarding?.documents || {}
  return [
    { key: 'cv', label: 'CV / Resume', file: documents.cv },
    { key: 'cnicFront', label: 'CNIC front', file: documents.cnicFront },
    { key: 'cnicBack', label: 'CNIC back', file: documents.cnicBack },
    { key: 'education', label: 'Education documents', file: documents.education },
    { key: 'experience', label: 'Experience letter', file: documents.experience }
  ]
}

function missingDocuments(employee) {
  const personal = employee?.onboarding?.personal || {}
  const documents = employee?.onboarding?.documents || {}
  return [
    !personal.cnic && 'CNIC',
    !personal.bankAccount && 'Bank',
    !personal.emergencyContact && 'Emergency contact',
    !documents.cv && 'CV',
    !documents.contract && 'Contract'
  ].filter(Boolean)
}

function fileName(file, fallback) {
  return file?.name || fallback
}

function initials(name) {
  return name.split(' ').map(part => part[0]).join('').slice(0, 2).toUpperCase()
}

function roleLabel(role) {
  if (role === 'super_admin') return 'Super Admin'
  if (role === 'hr') return 'HR'
  return role
}

function statusLabel(status) {
  if (status === 'blocked') return 'Blocked'
  if (status === 'active') return 'Active'
  return 'Invitation sent'
}

function statusClass(status) {
  if (status === 'blocked') return 'bg-rose-100 text-rose-700'
  if (status === 'active') return 'bg-emerald-100 text-emerald-700'
  return 'bg-amber-100 text-amber-800'
}

function statusDotClass(status) {
  if (status === 'blocked') return 'bg-rose-500'
  if (status === 'active') return 'bg-emerald-500'
  return 'bg-amber-500'
}

function canManageProfileStatus(employee) {
  return can('Employees', 'create')
    && ['employee', 'manager'].includes(employee.role)
    && employee.id !== state.currentUser.id
}

// Blocking cuts off someone's access immediately, so it is confirmed first.
// Unblocking restores access and needs no prompt.
const pendingBlock = ref(null)

function askSetProfileBlocked(employee, blocked) {
  if (!canManageProfileStatus(employee)) return
  if (!blocked) {
    setProfileBlocked(employee, false)
    return
  }
  pendingBlock.value = employee
}

function confirmBlock() {
  if (pendingBlock.value) setProfileBlocked(pendingBlock.value, true)
  pendingBlock.value = null
}

function setProfileBlocked(employee, blocked) {
  if (!canManageProfileStatus(employee)) return
  employee.status = blocked ? 'blocked' : 'active'
  logActivity({
    module: 'employee',
    action: blocked ? 'profile blocked' : 'profile unblocked',
    details: `${employee.name}'s profile was ${blocked ? 'blocked' : 'unblocked'} by ${state.currentUser.name}.`,
    targetId: employee.id,
    targetType: 'employee'
  })
  pushNotification({
    title: blocked ? 'Profile blocked' : 'Profile restored',
    message: blocked ? 'Your profile access has been blocked by administration.' : 'Your profile access has been restored.',
    type: 'employee',
    userIds: [employee.id],
    route: { name: 'profile' }
  })
}

function downloadDocument(file, fallbackName) {
  if (!file) return
  const blob = file instanceof Blob ? file : new Blob([file.content || 'Demo document'])
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = fileName(file, fallbackName)
  link.click()
  URL.revokeObjectURL(url)
}
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <div class="text-sm text-slate-600 mt-1">Manage team members and invitations</div>
      </div>
      <button v-if="canInviteEmployees()" @click="openInviteForm()" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors shadow-sm">
        + Add employee
      </button>
    </div>

    <div class="employees-table-card bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="table-tools">
        <input v-model="employeeTable.query.value" type="search" placeholder="Filter employees..." class="table-filter-input" />
        <select v-model="employeeContextFilter" class="table-filter-input max-w-[210px]">
          <option value="all">All employees</option>
          <option value="active">Active profiles</option>
          <option value="team">Manager team</option>
          <option value="invited">Invited employees</option>
          <option value="onboarding">Onboarding queue</option>
          <option value="missing-docs">Missing documents</option>
          <option value="blocked">Blocked profiles</option>
        </select>
        <div class="table-count">{{ employeeTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="employeeTable.loadMoreRows">
      <table class="employees-table w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide">Employee</th>
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide">Office email</th>
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide">Department</th>
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide">Role</th>
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide">Status</th>
            <th class="px-5 py-4 font-semibold text-slate-500 text-xs uppercase tracking-wide text-right">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="e in employeeTable.visibleRows.value" :key="e.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
            <td class="px-5 py-4">
              <div class="flex items-center gap-3">
                <div class="h-10 w-10 rounded-2xl bg-slate-950 text-white flex items-center justify-center text-xs font-bold shadow-sm">
                  {{ initials(e.name) }}
                </div>
                <div class="min-w-0">
                  <div class="text-slate-900 font-semibold leading-tight">{{ e.name }}</div>
                  <div class="text-[11px] text-slate-500 mt-1">ID #{{ e.id.toString().padStart(3, '0') }}</div>
                </div>
              </div>
            </td>
            <td class="px-5 py-4 text-xs text-slate-600">{{ e.email }}</td>
            <td class="px-5 py-4">
              <span class="employee-department-pill inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">{{ e.department }}</span>
            </td>
            <td class="px-5 py-4">
              <span class="inline-flex rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-700 capitalize">{{ roleLabel(e.role) }}</span>
            </td>
            <td class="px-5 py-4">
              <span
                class="inline-flex items-center gap-1.5 text-[11px] px-3 py-1 rounded-full font-semibold"
                :class="statusClass(e.status)"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="statusDotClass(e.status)"></span>
                {{ statusLabel(e.status) }}
              </span>
            </td>
            <td class="px-5 py-4 text-right">
              <div class="flex items-center justify-end gap-2">
                <button
                  @click="openDetails(e)"
                  title="View details"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors shadow-sm"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
                <button
                  v-if="canManageProfileStatus(e)"
                  @click="askSetProfileBlocked(e, e.status !== 'blocked')"
                  class="inline-flex h-9 items-center justify-center rounded-xl border px-3 text-xs font-bold transition-colors shadow-sm"
                  :class="e.status === 'blocked' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'"
                >
                  {{ e.status === 'blocked' ? 'Unblock' : 'Block' }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <Teleport to="body">
    <div v-if="showDetails && selectedEmployee" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeDetails">
      <div class="bg-white rounded-xl p-6 w-full max-w-5xl shadow-2xl border border-slate-200 max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="font-semibold text-[15px] text-slate-900">{{ selectedEmployee.name }}</div>
            <div class="text-[12px] text-slate-500">{{ selectedEmployee.email }}</div>
          </div>
          <button @click="closeDetails" class="text-slate-500 hover:text-slate-800">✕</button>
        </div>

        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-slate-500">Department</span>
            <span class="font-medium text-slate-800">{{ selectedEmployee.department }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-500">Role</span>
            <span class="font-medium text-slate-800 capitalize">{{ selectedEmployee.role }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-500">Status</span>
            <span class="font-medium text-slate-800">{{ statusLabel(selectedEmployee.status) }}</span>
          </div>
          <div class="flex items-center justify-between">
            <span class="text-slate-500">Last activity</span>
            <span class="font-medium text-slate-800">Today · 08:55</span>
          </div>
        </div>

        <div v-if="selectedEmployee.status === 'blocked'" class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-700">
          This profile is blocked. The user cannot access their profile until HR or Super Admin unblocks it.
        </div>

        <div class="mt-4 grid grid-cols-2 lg:grid-cols-4 gap-3">
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-bold">Projects</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ employeeProjects(selectedEmployee.id).length }}</div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-bold">Tickets</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ employeeTickets(selectedEmployee.id).length }}</div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-bold">Leaves</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ employeeLeaves(selectedEmployee.id).length }}</div>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-bold">Claims</div>
            <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ employeeClaims(selectedEmployee.id).length }}</div>
          </div>
        </div>

        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Assigned projects</div>
            <div v-if="employeeProjects(selectedEmployee.id).length === 0" class="text-sm text-slate-500">No projects assigned.</div>
            <div v-for="project in employeeProjects(selectedEmployee.id)" :key="project.id" class="text-sm text-slate-700 flex items-center justify-between gap-3 py-1">
              <span class="font-medium">{{ project.name }}</span>
              <span>{{ state.tickets.filter(ticket => ticket.projectId === project.id && ticket.assigneeId === selectedEmployee.id).length }} tickets</span>
            </div>
          </div>
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Recent tickets</div>
            <div v-if="employeeTickets(selectedEmployee.id).length === 0" class="text-sm text-slate-500">No tickets assigned.</div>
            <div v-for="ticket in employeeTickets(selectedEmployee.id).slice(0, 5)" :key="ticket.id" class="text-sm text-slate-700 flex items-center justify-between gap-3 py-1">
              <span class="font-medium">{{ ticket.title }}</span>
              <span class="capitalize">{{ ticket.status }}</span>
            </div>
          </div>
        </div>

        <div v-if="canViewOnboardingDetails()" class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3">
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Onboarding details</div>
            <div v-if="!selectedEmployee.onboarding" class="text-sm text-slate-500">Employee has not completed onboarding yet.</div>
            <div v-else class="space-y-3">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                  <div class="text-slate-500 mb-1">Phone</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'phone') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">CNIC / National ID</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'cnic') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">Emergency contact</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'emergencyContact') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">Bank account / IBAN</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'bankAccount') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">Tax number</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'taxNumber') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">Designation</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('technical', 'designation') }}</div>
                </div>
                <div>
                  <div class="text-slate-500 mb-1">Experience</div>
                  <div class="font-medium text-slate-800">{{ onboardingValue('technical', 'experienceYears') }}</div>
                </div>
              </div>
              <div class="text-sm">
                <div class="text-slate-500 mb-1">Residential address</div>
                <div class="font-medium text-slate-800">{{ onboardingValue('personal', 'address') }}</div>
              </div>
              <div class="text-sm">
                <div class="text-slate-500 mb-1">Stacks</div>
                <div class="flex flex-wrap gap-1.5">
                  <span v-for="stack in onboardingList('technical', 'stacks')" :key="stack" class="rounded-full bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-700">{{ stack }}</span>
                  <span v-if="onboardingList('technical', 'stacks').length === 0" class="text-slate-500">-</span>
                </div>
              </div>
              <div class="text-sm">
                <div class="text-slate-500 mb-1">Core technologies</div>
                <div class="flex flex-wrap gap-1.5">
                  <span v-for="skill in onboardingList('technical', 'skills')" :key="skill" class="rounded-full bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">{{ skill }}</span>
                  <span v-if="onboardingList('technical', 'skills').length === 0" class="text-slate-500">-</span>
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-2 text-sm">
                <a :href="onboardingValue('technical', 'githubUrl')" target="_blank" class="text-emerald-700 hover:text-emerald-800 truncate">GitHub</a>
                <a :href="onboardingValue('technical', 'portfolioUrl')" target="_blank" class="text-emerald-700 hover:text-emerald-800 truncate">Portfolio</a>
                <a :href="onboardingValue('technical', 'linkedinUrl')" target="_blank" class="text-emerald-700 hover:text-emerald-800 truncate">LinkedIn</a>
              </div>
            </div>
          </div>

          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Documents</div>
            <div v-if="!selectedEmployee.onboarding" class="text-sm text-slate-500">No documents submitted yet.</div>
            <div v-else class="space-y-2">
              <div v-for="document in documentRows(selectedEmployee)" :key="document.key" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2">
                <div class="min-w-0">
                  <div class="text-sm font-medium text-slate-800">{{ document.label }}</div>
                  <div class="text-xs text-slate-500 truncate">{{ document.file ? fileName(document.file, `${document.key}.pdf`) : 'Not uploaded' }}</div>
                </div>
                <button
                  @click="downloadDocument(document.file, `${document.key}.pdf`)"
                  :disabled="!document.file"
                  class="text-xs font-medium rounded-lg px-3 py-1.5 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Download
                </button>
              </div>
              <div class="text-xs text-slate-500">Profile photo is intentionally not available for download.</div>
            </div>
          </div>
        </div>

        <div v-if="canViewOnboardingDetails()" class="mt-4 grid grid-cols-1 gap-3">
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Medical claims</div>
            <div v-if="employeeClaims(selectedEmployee.id).length === 0" class="text-sm text-slate-500">No claims</div>
            <div v-for="claim in employeeClaims(selectedEmployee.id)" :key="claim.id" class="text-sm text-slate-700 flex items-center justify-between gap-3 py-1">
              <span>{{ claim.type }}</span>
              <span class="font-medium capitalize">{{ claim.status }}</span>
            </div>
          </div>
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Leave requests</div>
            <div v-if="employeeLeaves(selectedEmployee.id).length === 0" class="text-sm text-slate-500">No leave requests</div>
            <div v-for="leave in employeeLeaves(selectedEmployee.id)" :key="leave.id" class="text-sm text-slate-700 flex items-center justify-between gap-3 py-1">
              <span>{{ leave.type }}</span>
              <span class="font-medium capitalize">{{ leave.status }}</span>
            </div>
          </div>
          <div class="rounded-xl border border-slate-200 p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Payroll slips</div>
            <div v-if="employeeSalarySlips(selectedEmployee.id).length === 0" class="text-sm text-slate-500">No payroll slip requests</div>
            <div v-for="slip in employeeSalarySlips(selectedEmployee.id)" :key="slip.id" class="text-sm text-slate-700 flex items-center justify-between gap-3 py-1">
              <span>{{ slip.month }}</span>
              <span class="font-medium capitalize">{{ slip.status }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
    </Teleport>

    <!-- Add employee / invite modal -->
    <Teleport to="body">
    <div v-if="showForm && canInviteEmployees()" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-sm border border-slate-200 shadow-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div v-if="!sent">
          <div class="font-semibold text-[15px] text-slate-900 mb-1">Add employee</div>
          <div class="text-xs text-slate-500 mb-4">An invitation link will be emailed to their office address.</div>
          <div class="flex flex-col gap-3">
            <div>
              <label class="block">
              <span class="text-[12px] text-slate-500 block mb-1.5">Full name</span>
              <input v-model="form.name" type="text" placeholder="Hina Malik" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-[12px] text-slate-500 block mb-1.5">Office email</span>
              <input v-model="form.email" type="email" placeholder="hina.malik@company.com" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-[12px] text-slate-500 block mb-1.5">Designation</span>
              <select v-model="form.department" class="w-full text-[13px] border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option v-for="designation in state.settings.designations" :key="designation">{{ designation }}</option>
              </select>
              </label>
            </div>
            <div v-if="inviteError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-[12px] font-semibold text-rose-700">
              {{ inviteError }}
            </div>
            <div class="flex gap-2 mt-1">
              <button @click="showForm = false" class="flex-1 text-[13px] border border-slate-200 rounded-lg py-2 hover:bg-slate-50 transition-colors text-slate-700">Cancel</button>
              <button @click="invite" class="flex-1 bg-emerald-600 text-white text-[13px] font-medium rounded-lg py-2 hover:bg-emerald-700 transition-colors">Send invite</button>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-4">
          <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto mb-3">
            <svg viewBox="0 0 24 24" class="w-5 h-5" fill="currentColor"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
          </div>
          <div class="text-[13px] text-slate-800">Invitation sent to {{ form.email }}</div>
        </div>
      </div>
    </div>
    </Teleport>

    <ConfirmDialog
      :open="!!pendingBlock"
      title="Block this profile?"
      :message="pendingBlock ? `${pendingBlock.name} will lose access immediately and cannot sign in until unblocked.` : ''"
      confirm-label="Block profile"
      @confirm="confirmBlock"
      @cancel="pendingBlock = null"
    />
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useStore } from '../store/index.js'
import { useTableControls } from '../composables/useTableControls.js'

const route = useRoute()
const { state, can, squadMemberIds, pushNotification, logActivity } = useStore()
// HR-stage approval right on Leave Request (HR + super_admin hold it).
const canReviewHR = computed(() => can('Leave Request', 'approve'))
// Stage one is the Team Lead's. Roles that also hold the HR-stage right
// (super_admin) act at that later stage instead, so they are excluded here.
const isManager = computed(() => can('Team Leaves', 'approve') && !can('Leave Request', 'approve'))
// Managers keep visibility of their team's leave, but no approval rights.
const isTeamObserver = computed(() => can('Team Leaves', 'view') && !can('Team Leaves', 'approve'))
// A lead may only approve leave for someone in a module they actually lead.
function canApproveAsLead(request) {
  if (!can('Team Leaves', 'approve')) return false
  return squadMemberIds().includes(request.employeeId)
}
// Anyone who may create a leave request for themselves.
const canApply = computed(() => can('Leave Request', 'create'))
// Sees the whole team's list rather than just their own requests.
const canSeeTeamLeave = computed(() => isManager.value || isTeamObserver.value || canReviewHR.value)
const leaveContextFilter = ref('all')
const leaveError = ref('')
const visibleLeaveRequests = computed(() =>
  // Leads, managers (observers), and HR see the team list; everyone else sees
  // only their own requests.
  (canSeeTeamLeave.value
    ? state.leaveRequests
    : state.leaveRequests.filter(l => l.employeeId === state.currentUser.id))
    .filter(request => {
      const status = leaveStatus(request)
      if (leaveContextFilter.value === 'pending-approval') return status.includes('pending') || status.includes('awaiting')
      if (leaveContextFilter.value === 'approved') return status === 'approved'
      if (leaveContextFilter.value === 'rejected') return status === 'rejected'
      if (leaveContextFilter.value === 'half-day') return request.duration === 'half'
      if (leaveContextFilter.value === 'mine') return request.employeeId === state.currentUser.id
      return true
    })
)
const reviewLeaveRequests = computed(() =>
  state.leaveRequests.filter(request => {
    const status = leaveStatus(request)
    if (leaveContextFilter.value === 'pending-approval') return status.includes('pending') || status.includes('awaiting')
    if (leaveContextFilter.value === 'approved') return status === 'approved'
    if (leaveContextFilter.value === 'rejected') return status === 'rejected'
    if (leaveContextFilter.value === 'half-day') return request.duration === 'half'
    if (leaveContextFilter.value === 'mine') return request.employeeId === state.currentUser.id
    return true
  })
)
const leaveSearchText = request =>
  `${employeeName(request.employeeId)} ${request.type} ${request.duration || ''} ${request.from} ${request.to} ${request.reason || ''} ${request.contact || ''} ${leaveStatus(request)} ${approvalStatus(request, 'managerApproval')} ${approvalStatus(request, 'hrApproval')}`
const leaveTable = useTableControls(visibleLeaveRequests, leaveSearchText)
const hrLeaveTable = useTableControls(reviewLeaveRequests, leaveSearchText)
const form = ref({
  type: '',
  duration: 'full',
  from: '',
  to: '',
  contact: '',
  reason: '',
  attachment: null
})
const showApplyModal = ref(false)
const selectedLeaveRequest = ref(null)
const reviewNotes = ref({})

function applyRouteFilters(query = route.query) {
  leaveContextFilter.value = query.filter || 'all'
  leaveTable.query.value = query.search || ''
  hrLeaveTable.query.value = query.search || ''
}

watch(
  () => route.query,
  query => applyRouteFilters(query),
  { immediate: true }
)

const leaveBalances = computed(() => {
  const policies = {
    'Annual leave': state.settings.leave.annualDays,
    'Sick leave': state.settings.leave.sickDays,
    'Casual leave': state.settings.leave.casualDays
  }
  return Object.entries(policies).map(([type, allowed]) => {
    const used = state.leaveRequests
      .filter(request => request.employeeId === state.currentUser.id && request.type === type && leaveStatus(request) === 'approved')
      .reduce((total, request) => total + leaveDays(request), 0)
    return {
      type,
      allowed,
      used,
      remaining: Math.max(0, Number(allowed || 0) - used)
    }
  })
})

function employeeName(id) {
  return state.employees.find(e => e.id === id)?.name ?? ''
}

function employeeRole(id) {
  return state.employees.find(e => e.id === id)?.role || 'employee'
}

function isOwnLeave(request) {
  return request.employeeId === state.currentUser.id
}

function approvalStatus(request, type) {
  return request[type]?.status || 'pending'
}

function approvalBy(request, type) {
  return request[type]?.byName || '-'
}

function leaveDays(request) {
  if (request.duration === 'half') return 0.5
  if (!request.from || !request.to) return 0
  const start = new Date(request.from)
  const end = new Date(request.to)
  const difference = end.getTime() - start.getTime()
  if (Number.isNaN(difference) || difference < 0) return 0
  return Math.floor(difference / 86400000) + 1
}

function openLeaveDetails(request) {
  selectedLeaveRequest.value = request
}

function closeLeaveDetails() {
  selectedLeaveRequest.value = null
}

function addLeaveTimeline(request, status, note = '') {
  if (!request.timeline) request.timeline = []
  request.timeline.unshift({
    status,
    note,
    by: state.currentUser.name,
    role: state.currentUser.role,
    at: new Date().toISOString()
  })
}

function leaveStatus(request) {
  if (request.status === 'rejected') return 'rejected'
  const role = employeeRole(request.employeeId)
  if (role === 'hr') {
    return approvalStatus(request, 'superAdminApproval') === 'approved' ? 'approved' : 'pending super admin'
  }
  if (role === 'manager') {
    return approvalStatus(request, 'hrApproval') === 'approved' ? 'approved' : 'pending HR'
  }
  // Stage one belongs to the Team Lead (stored in managerApproval), who knows
  // sprint state and task completeness. HR gives final sign-off.
  // settings.leave.twoStepApproval: when off, the lead's approval is enough.
  if (!state.settings.leave.twoStepApproval) {
    return approvalStatus(request, 'managerApproval') === 'approved' ? 'approved' : 'pending team lead'
  }
  if (approvalStatus(request, 'managerApproval') === 'approved' && approvalStatus(request, 'hrApproval') === 'approved') {
    return 'approved'
  }
  if (approvalStatus(request, 'managerApproval') === 'approved') {
    return 'awaiting HR'
  }
  return 'pending team lead'
}

// Stage-one approval, owned by the Team Lead. The record field is still called
// managerApproval so existing timelines and payload shapes stay intact.
function approveByManager(request) {
  if (isOwnLeave(request)) return
  if (employeeRole(request.employeeId) !== 'employee') return
  if (!canApproveAsLead(request)) return
  request.managerApproval = {
    status: 'approved',
    byId: state.currentUser.id,
    byName: state.currentUser.name,
    at: new Date().toISOString()
  }
  request.status = leaveStatus(request)
  addLeaveTimeline(request, 'team lead approved', reviewNotes.value[request.id] || '')
  logActivity({
    module: 'leave',
    action: 'team lead approved leave',
    details: `${state.currentUser.name} approved ${employeeName(request.employeeId)}'s ${request.type}.`,
    targetId: request.id,
    targetType: 'leaveRequest'
  })
  pushNotification({
    title: 'Leave approved by team lead',
    message: `${state.currentUser.name} approved your ${request.type}.`,
    type: 'leave',
    userIds: [request.employeeId],
    route: { name: 'leave-requests' }
  })
  pushNotification({
    title: 'Leave ready for HR review',
    message: `${employeeName(request.employeeId)}'s ${request.type} has manager approval.`,
    type: 'leave',
    roles: ['hr', 'super_admin'],
    route: { name: 'leave-requests' }
  })
  reviewNotes.value[request.id] = ''
}

function approveByHR(request) {
  if (isOwnLeave(request)) return
  if (employeeRole(request.employeeId) === 'hr') return
  if (employeeRole(request.employeeId) === 'employee' && approvalStatus(request, 'managerApproval') !== 'approved') return
  request.hrApproval = {
    status: 'approved',
    byId: state.currentUser.id,
    byName: state.currentUser.name,
    at: new Date().toISOString()
  }
  request.status = leaveStatus(request)
  addLeaveTimeline(request, 'hr approved', reviewNotes.value[request.id] || '')
  logActivity({
    module: 'leave',
    action: 'hr approved leave',
    details: `${state.currentUser.name} approved ${employeeName(request.employeeId)}'s ${request.type}.`,
    targetId: request.id,
    targetType: 'leaveRequest'
  })
  pushNotification({
    title: 'Leave approved by HR',
    message: `${state.currentUser.name} approved your ${request.type}.`,
    type: 'leave',
    userIds: [request.employeeId],
    route: { name: 'leave-requests' }
  })
  reviewNotes.value[request.id] = ''
}

function approveBySuperAdmin(request) {
  if (!can('Leave Escalation', 'approve')) return
  request.superAdminApproval = {
    status: 'approved',
    byId: state.currentUser.id,
    byName: state.currentUser.name,
    at: new Date().toISOString()
  }
  request.status = leaveStatus(request)
  addLeaveTimeline(request, 'super admin approved', reviewNotes.value[request.id] || '')
  logActivity({
    module: 'leave',
    action: 'super admin approved leave',
    details: `${state.currentUser.name} approved ${employeeName(request.employeeId)}'s ${request.type}.`,
    targetId: request.id,
    targetType: 'leaveRequest'
  })
  pushNotification({
    title: 'Leave approved by Super Admin',
    message: `Your ${request.type} has been approved by Super Admin.`,
    type: 'leave',
    userIds: [request.employeeId],
    route: { name: 'leave-requests' }
  })
  reviewNotes.value[request.id] = ''
}

function rejectLeave(request) {
  if (isOwnLeave(request)) return
  const note = reviewNotes.value[request.id] || 'No reason provided.'
  request.status = 'rejected'
  addLeaveTimeline(request, 'rejected', note)
  logActivity({
    module: 'leave',
    action: 'leave rejected',
    details: `${state.currentUser.name} rejected ${employeeName(request.employeeId)}'s ${request.type}. Reason: ${note}`,
    targetId: request.id,
    targetType: 'leaveRequest'
  })
  pushNotification({
    title: 'Leave request rejected',
    message: `Your ${request.type} was rejected. ${note}`,
    type: 'leave',
    userIds: [request.employeeId],
    route: { name: 'leave-requests' }
  })
  reviewNotes.value[request.id] = ''
}

function applyLeaveRequest() {
  leaveError.value = ''
  if (!form.value.type || !form.value.from || !form.value.to || !form.value.reason) {
    leaveError.value = 'Please fill in leave type, both dates, and a reason.'
    return
  }
  if (form.value.to < form.value.from) {
    leaveError.value = 'The end date cannot be before the start date.'
    return
  }
  if (form.value.duration === 'half' && form.value.from !== form.value.to) {
    leaveError.value = 'A half day must start and end on the same date.'
    return
  }
  const currentRole = state.currentUser.role
  state.leaveRequests.unshift({
    id: Date.now(),
    employeeId: state.currentUser.id,
    type: form.value.type,
    duration: form.value.duration,
    from: form.value.from,
    to: form.value.to,
    contact: form.value.contact,
    reason: form.value.reason,
    attachmentName: form.value.attachment?.name || '',
    managerApproval: { status: 'pending', byId: null, byName: '', at: null },
    hrApproval: { status: 'pending', byId: null, byName: '', at: null },
    superAdminApproval: { status: 'pending', byId: null, byName: '', at: null },
    timeline: [{
      status: 'submitted',
      note: form.value.reason,
      by: state.currentUser.name,
      role: state.currentUser.role,
      at: new Date().toISOString()
    }],
    status: currentRole === 'hr' ? 'pending super admin' : currentRole === 'manager' ? 'pending HR' : 'pending team lead'
  })
  logActivity({
    module: 'leave',
    action: 'leave requested',
    details: `${state.currentUser.name} requested ${form.value.type} from ${form.value.from} to ${form.value.to}.`
  })
  pushNotification({
    title: 'New leave request submitted',
    message: `${state.currentUser.name} requested ${form.value.duration === 'half' ? 'a half day' : 'leave'} for ${form.value.type}.`,
    type: 'leave',
    roles: currentRole === 'hr' ? ['super_admin'] : currentRole === 'manager' ? ['hr', 'super_admin'] : ['manager', 'hr', 'super_admin'],
    route: { name: 'leave-requests' }
  })
  leaveError.value = ''
  form.value = { type: '', duration: 'full', from: '', to: '', contact: '', reason: '', attachment: null }
  showApplyModal.value = false
}
</script>

<template>
  <div v-if="canApply" class="space-y-6">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">{{ canSeeTeamLeave ? 'View leave requests and apply for your own leave' : 'Track your applied leave requests' }}</div>
      </div>
      <button @click="showApplyModal = true" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-5 py-2 hover:bg-emerald-700 transition-colors shadow-sm">
        Apply leave
      </button>
    </div>

    <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div v-for="balance in leaveBalances" :key="balance.type" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ balance.type }}</div>
        <div class="flex items-end justify-between gap-3 mt-2">
          <div>
            <div class="text-2xl font-extrabold text-slate-900">{{ balance.remaining }}</div>
            <div class="text-xs text-slate-600 mt-1">remaining of {{ balance.allowed }}</div>
          </div>
          <div class="text-right text-xs font-bold text-slate-500">{{ balance.used }} used</div>
        </div>
        <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
          <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${Math.min(100, (balance.used / Math.max(1, balance.allowed)) * 100)}%` }"></div>
        </div>
      </div>
    </section>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200 text-sm font-semibold text-slate-900">{{ canSeeTeamLeave ? 'Leave requests' : 'My leave requests' }}</div>
      <div class="table-tools">
        <input v-model="leaveTable.query.value" type="search" placeholder="Filter leave requests..." class="table-filter-input" />
        <select v-model="leaveContextFilter" class="table-filter-input max-w-[210px]">
          <option value="all">All leave requests</option>
          <option value="pending-approval">Pending approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="half-day">Half day</option>
          <option value="mine">My requests</option>
        </select>
        <div class="table-count">{{ leaveTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="leaveTable.loadMoreRows">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th v-if="canSeeTeamLeave" class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Type</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Duration</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Days</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Reason</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Team lead approval</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">HR approval</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
            <th v-if="canSeeTeamLeave" class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in leaveTable.visibleRows.value" :key="request.id" class="border-b border-slate-200 last:border-0">
            <td v-if="canSeeTeamLeave" class="px-4 py-3 text-slate-900 font-medium">{{ employeeName(request.employeeId) }}</td>
            <td class="px-4 py-3 text-slate-900 font-medium">
              <div>{{ request.type }}</div>
              <div v-if="request.duration === 'half'" class="text-xs text-slate-500">Half day</div>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ request.from }} to {{ request.to }}</td>
            <td class="px-4 py-3 text-slate-700">{{ leaveDays(request) }}</td>
            <td class="px-4 py-3 text-slate-700 max-w-md truncate">{{ request.reason || '-' }}</td>
            <td class="px-4 py-3 text-slate-700">
              <div class="capitalize">{{ approvalStatus(request, 'managerApproval') }}</div>
              <div class="text-xs text-slate-500">{{ approvalBy(request, 'managerApproval') }}</div>
            </td>
            <td class="px-4 py-3 text-slate-700">
              <div class="capitalize">{{ approvalStatus(request, 'hrApproval') }}</div>
              <div class="text-xs text-slate-500">{{ approvalBy(request, 'hrApproval') }}</div>
            </td>
            <td class="px-4 py-3 capitalize text-slate-700">{{ leaveStatus(request) }}</td>
            <td v-if="canSeeTeamLeave" class="px-4 py-3">
              <div class="min-w-[260px] space-y-2">
              <input v-model="reviewNotes[request.id]" placeholder="Approval note / rejection reason" class="w-full text-xs border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              <div class="flex flex-wrap items-center gap-2">
                <button
                  @click="openLeaveDetails(request)"
                  title="View details"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
                <button
                  v-if="isManager"
                  @click="approveByManager(request)"
                  :disabled="isOwnLeave(request) || request.status === 'rejected' || employeeRole(request.employeeId) !== 'employee' || approvalStatus(request, 'managerApproval') === 'approved' || !canApproveAsLead(request)"
                  :title="!canApproveAsLead(request) ? 'Only this employee\'s team lead can approve' : ''"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Approve
                </button>
                <button
                  v-if="canReviewHR && employeeRole(request.employeeId) !== 'hr'"
                  @click="approveByHR(request)"
                  :disabled="isOwnLeave(request) || request.status === 'rejected' || (employeeRole(request.employeeId) === 'employee' && approvalStatus(request, 'managerApproval') !== 'approved') || approvalStatus(request, 'hrApproval') === 'approved'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Approve
                </button>
                <button
                  v-if="can('Leave Escalation', 'approve') && employeeRole(request.employeeId) === 'hr'"
                  @click="approveBySuperAdmin(request)"
                  :disabled="request.status === 'rejected' || approvalStatus(request, 'superAdminApproval') === 'approved'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Approve
                </button>
                <button
                  @click="rejectLeave(request)"
                  :disabled="isOwnLeave(request) || request.status === 'approved' || request.status === 'rejected'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-rose-600 text-white hover:bg-rose-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Reject
                </button>
              </div>
              </div>
            </td>
          </tr>
          <tr v-if="leaveTable.filteredRows.value.length === 0">
            <td :colspan="canSeeTeamLeave ? 9 : 7" class="px-4 py-8 text-center text-sm text-slate-500">No leave requests submitted yet.</td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="showApplyModal" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="showApplyModal = false">
        <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-2xl w-full max-w-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
          <div class="flex items-start justify-between gap-4 mb-5">
            <div>
              <div class="text-lg font-bold text-slate-900">Apply leave</div>
              <div class="text-sm text-slate-600 mt-1">Submit this request for manager and HR approval.</div>
            </div>
            <button @click="showApplyModal = false" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Leave type</span>
              <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="">Select leave type</option>
                <option>Annual leave</option>
                <option>Sick leave</option>
                <option>Casual leave</option>
                <option>Emergency leave</option>
                <option>Work from home</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Leave duration</span>
              <select v-model="form.duration" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option value="full">Full day</option>
                <!-- Hidden when half-day leave is switched off in Settings. -->
                <option v-if="state.settings.leave.halfDayEnabled" value="half">Half day</option>
              </select>
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Contact during leave</span>
              <input v-model="form.contact" type="text" placeholder="Phone or alternate contact" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">From date</span>
              <input v-model="form.from" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div>
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">To date</span>
              <input v-model="form.to" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Reason</span>
              <textarea v-model="form.reason" rows="4" placeholder="Explain why you need leave and mention any handover details." class="w-full resize-none rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"></textarea>
              </label>
            </div>
            <div class="md:col-span-2">
              <label class="block">
              <span class="text-sm font-medium text-slate-700 block mb-1.5">Supporting document</span>
              <input type="file" accept=".pdf,image/*" @change="form.attachment = $event.target.files[0]" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" />
              </label>
            </div>
          </div>

          <div v-if="leaveError" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ leaveError }}
          </div>

          <div class="flex items-center justify-end gap-2 mt-5">
            <button @click="showApplyModal = false" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">Cancel</button>
            <button @click="applyLeaveRequest" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-5 py-2 hover:bg-emerald-700 transition-colors">
              Apply
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>

  <div v-else-if="canReviewHR" class="space-y-6">
    <div>
      <div class="text-sm text-slate-600 mt-1">Review leave requests after manager approval</div>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl overflow-x-auto shadow-sm">
      <div class="table-tools">
        <input v-model="hrLeaveTable.query.value" type="search" placeholder="Filter leave requests..." class="table-filter-input" />
        <select v-model="leaveContextFilter" class="table-filter-input max-w-[210px]">
          <option value="all">All leave requests</option>
          <option value="pending-approval">Pending approval</option>
          <option value="approved">Approved</option>
          <option value="rejected">Rejected</option>
          <option value="half-day">Half day</option>
          <option value="mine">My requests</option>
        </select>
        <div class="table-count">{{ hrLeaveTable.filteredRows.value.length }} records</div>
      </div>
      <div class="table-scroll" @scroll="hrLeaveTable.loadMoreRows">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left bg-slate-50/80">
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Employee</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Type</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">From</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">To</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Days</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Reason</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Contact</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Team lead approval</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">HR approval</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Status</th>
            <th class="px-4 py-3 font-medium text-slate-500 text-xs uppercase tracking-wide">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="request in hrLeaveTable.visibleRows.value" :key="request.id" class="border-b border-slate-200 last:border-0 hover:bg-slate-50/60 transition-colors">
            <td class="px-4 py-3 text-slate-900 font-medium">{{ employeeName(request.employeeId) }}</td>
            <td class="px-4 py-3 text-slate-700">
              <div>{{ request.type }}</div>
              <div v-if="request.duration === 'half'" class="text-xs text-slate-500">Half day</div>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ request.from }}</td>
            <td class="px-4 py-3 text-slate-700">{{ request.to }}</td>
            <td class="px-4 py-3 text-slate-700">{{ leaveDays(request) }}</td>
            <td class="px-4 py-3 text-slate-700 max-w-xs truncate">{{ request.reason || '-' }}</td>
            <td class="px-4 py-3 text-slate-700">{{ request.contact || '-' }}</td>
            <td class="px-4 py-3 text-slate-700">
              <div class="capitalize">{{ approvalStatus(request, 'managerApproval') }}</div>
              <div class="text-xs text-slate-500">{{ approvalBy(request, 'managerApproval') }}</div>
            </td>
            <td class="px-4 py-3 text-slate-700">
              <div class="capitalize">{{ approvalStatus(request, 'hrApproval') }}</div>
              <div class="text-xs text-slate-500">{{ approvalBy(request, 'hrApproval') }}</div>
            </td>
            <td class="px-4 py-3 capitalize text-slate-700">{{ leaveStatus(request) }}</td>
            <td class="px-4 py-3">
              <div class="min-w-[260px] space-y-2">
              <input v-model="reviewNotes[request.id]" placeholder="Approval note / rejection reason" class="w-full text-xs border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-1 focus:ring-emerald-500" />
              <div class="flex flex-wrap items-center gap-2">
                <button
                  @click="openLeaveDetails(request)"
                  title="View details"
                  class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900 transition-colors"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z" />
                    <circle cx="12" cy="12" r="3" />
                  </svg>
                </button>
                <button
                  v-if="employeeRole(request.employeeId) !== 'hr'"
                  @click="approveByHR(request)"
                  :disabled="isOwnLeave(request) || request.status === 'rejected' || (employeeRole(request.employeeId) === 'employee' && approvalStatus(request, 'managerApproval') !== 'approved') || approvalStatus(request, 'hrApproval') === 'approved'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Approve
                </button>
                <button
                  v-if="can('Leave Escalation', 'approve') && employeeRole(request.employeeId) === 'hr'"
                  @click="approveBySuperAdmin(request)"
                  :disabled="request.status === 'rejected' || approvalStatus(request, 'superAdminApproval') === 'approved'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Approve
                </button>
                <button
                  @click="rejectLeave(request)"
                  :disabled="isOwnLeave(request) || request.status === 'approved' || request.status === 'rejected'"
                  class="text-sm font-medium rounded-lg px-3 py-2 bg-rose-600 text-white hover:bg-rose-700 disabled:bg-slate-300 disabled:cursor-not-allowed transition-colors"
                >
                  Reject
                </button>
              </div>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Leave requests are not available for this role</div>
  </div>

  <Teleport to="body">
    <div v-if="selectedLeaveRequest" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeLeaveDetails">
      <div class="bg-white border border-slate-200 rounded-xl p-6 shadow-2xl w-full max-w-2xl max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="flex items-start justify-between gap-4 mb-5">
          <div>
            <div class="text-lg font-bold text-slate-900">Leave request details</div>
            <div class="text-sm text-slate-600 mt-1">{{ employeeName(selectedLeaveRequest.employeeId) }} - {{ selectedLeaveRequest.type }}</div>
          </div>
          <button @click="closeLeaveDetails" class="text-slate-500 hover:text-slate-900 text-xl leading-none">x</button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Employee</div>
            <div class="mt-1 font-semibold text-slate-900">{{ employeeName(selectedLeaveRequest.employeeId) }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Leave type</div>
            <div class="mt-1 font-semibold text-slate-900">{{ selectedLeaveRequest.type }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Dates</div>
            <div class="mt-1 font-semibold text-slate-900">{{ selectedLeaveRequest.from }} to {{ selectedLeaveRequest.to }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Days</div>
            <div class="mt-1 font-semibold text-slate-900">{{ leaveDays(selectedLeaveRequest) }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Duration</div>
            <div class="mt-1 capitalize font-semibold text-slate-900">{{ selectedLeaveRequest.duration === 'half' ? 'Half day' : 'Full day' }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Team lead approval</div>
            <div class="mt-1 capitalize font-semibold text-slate-900">{{ approvalStatus(selectedLeaveRequest, 'managerApproval') }}</div>
            <div class="text-xs text-slate-500">{{ approvalBy(selectedLeaveRequest, 'managerApproval') }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">HR approval</div>
            <div class="mt-1 capitalize font-semibold text-slate-900">{{ approvalStatus(selectedLeaveRequest, 'hrApproval') }}</div>
            <div class="text-xs text-slate-500">{{ approvalBy(selectedLeaveRequest, 'hrApproval') }}</div>
          </div>
          <div v-if="employeeRole(selectedLeaveRequest.employeeId) === 'hr'" class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Super admin approval</div>
            <div class="mt-1 capitalize font-semibold text-slate-900">{{ approvalStatus(selectedLeaveRequest, 'superAdminApproval') }}</div>
            <div class="text-xs text-slate-500">{{ approvalBy(selectedLeaveRequest, 'superAdminApproval') }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Contact</div>
            <div class="mt-1 font-semibold text-slate-900">{{ selectedLeaveRequest.contact || '-' }}</div>
          </div>
          <div class="rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Status</div>
            <div class="mt-1 capitalize font-semibold text-slate-900">{{ leaveStatus(selectedLeaveRequest) }}</div>
          </div>
          <div class="md:col-span-2 rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Reason</div>
            <div class="mt-1 whitespace-pre-wrap text-slate-900">{{ selectedLeaveRequest.reason || '-' }}</div>
          </div>
          <div class="md:col-span-2 rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium">Attachment</div>
            <div class="mt-1 text-slate-900">{{ selectedLeaveRequest.attachmentName || 'No attachment uploaded' }}</div>
          </div>
          <div class="md:col-span-2 rounded-lg border border-slate-200 p-3">
            <div class="text-xs uppercase tracking-wide text-slate-500 font-medium mb-3">Approval timeline</div>
            <div v-if="!selectedLeaveRequest.timeline?.length" class="text-sm text-slate-500">No timeline entries yet.</div>
            <div v-for="entry in selectedLeaveRequest.timeline || []" :key="`${entry.status}-${entry.at}`" class="border-l-2 border-slate-200 pl-3 pb-3 last:pb-0">
              <div class="text-sm font-semibold text-slate-900 capitalize">{{ entry.status }}</div>
              <div class="text-xs text-slate-500">{{ entry.by }} / {{ new Date(entry.at).toLocaleString() }}</div>
              <div v-if="entry.note" class="text-sm text-slate-700 mt-1">{{ entry.note }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

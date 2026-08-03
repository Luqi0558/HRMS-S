<script setup>
import { computed, ref } from 'vue'
import PunchLedger from './PunchLedger.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'

// HR: people operations — approvals, onboarding, attendance exceptions, and
// upcoming birthdays/anniversaries. Company-wide scope, but no finance.
const {
  state,
  todayKey,
  formatDuration,
  formatCurrency,
  employeeName,
  formatDate,
  statusBadgeClass,
  goToRoute,
  timesheetTitle,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  daysUntilMonthDay,
  ledgerRowsFor,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera,
  logActivity,
  pushNotification
} = useDashboard()

const activeAttendanceException = ref('')
const attendanceExceptionSearch = ref('')
const attendanceExceptionDepartment = ref('')
const attendanceExportMessage = ref('')
const hrActionMessage = ref('')

// HR sees every employee in the punch ledger.
const ledgerRows = computed(() => ledgerRowsFor(null))

const checkedInToday = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.checkIn).length
)
const lateToday = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.status === 'late').length
)

const hrUpcomingBirthdays = computed(() =>
  state.employees
    .filter(employee => employee.birthday && daysUntilMonthDay(employee.birthday) <= 30)
    .map(employee => ({ ...employee, daysAway: daysUntilMonthDay(employee.birthday) }))
    .sort((a, b) => a.daysAway - b.daysAway)
)

const hrUpcomingAnniversaries = computed(() =>
  state.employees
    .filter(employee => employee.joiningDate && daysUntilMonthDay(employee.joiningDate) <= 30)
    .map(employee => ({ ...employee, daysAway: daysUntilMonthDay(employee.joiningDate) }))
    .sort((a, b) => a.daysAway - b.daysAway)
)

const hrPendingLeaves = computed(() =>
  state.leaveRequests.filter(leave => leave.status === 'pending' && leave.employeeId !== state.currentUser.id)
)
const hrPendingClaims = computed(() =>
  state.medicalClaims.filter(claim => claim.status === 'pending')
)
const hrOnboardingQueue = computed(() =>
  state.employees.filter(employee => employee.status === 'invited' || employee.onboarding)
)
const hrMissingDocuments = computed(() =>
  state.employees
    .filter(employee => employee.role !== 'super_admin')
    .map(employee => {
      const personal = employee.onboarding?.personal || {}
      const documents = employee.onboarding?.documents || {}
      const missing = [
        !personal.cnic && 'CNIC',
        !personal.bankAccount && 'Bank',
        !personal.emergencyContact && 'Emergency contact',
        !documents.cv && 'CV',
        !documents.contract && 'Contract'
      ].filter(Boolean)
      return { employee, missing }
    })
    .filter(item => item.missing.length)
)
const hrMissingCheckout = computed(() =>
  state.attendance.filter(record => record.date === todayKey() && record.checkIn && !record.checkOut)
)
const hrAbsentEmployees = computed(() => {
  const presentIds = new Set(state.attendance.filter(record => record.date === todayKey()).map(record => record.employeeId))
  return state.employees.filter(employee => employee.status === 'active' && employee.role !== 'super_admin' && !presentIds.has(employee.id))
})
const hrAttendanceExceptions = computed(() => ({
  late: state.attendance.filter(record => record.date === todayKey() && record.status === 'late'),
  missingCheckout: hrMissingCheckout.value,
  absent: hrAbsentEmployees.value
}))
const hrAttendanceExceptionGroups = computed(() => ({
  absent: {
    label: 'Absent',
    tone: 'text-slate-900',
    helper: 'Employees without a check-in today',
    showTimes: false,
    rows: hrAttendanceExceptions.value.absent.map(employee => ({
      id: `absent-${employee.id}`,
      employee,
      employeeName: employee.name,
      department: employee.department || 'Not specified',
      status: 'Absent',
      checkIn: '-',
      checkOut: '-',
      action: 'Follow up'
    }))
  },
  late: {
    label: 'Late',
    tone: 'text-orange-600',
    helper: 'Employees checked in after grace time',
    showTimes: true,
    rows: hrAttendanceExceptions.value.late.map(record => {
      const employee = state.employees.find(item => item.id === record.employeeId)
      return {
        id: `late-${record.id}`,
        employee,
        employeeName: employee?.name || 'Unknown employee',
        department: employee?.department || 'Not specified',
        status: 'Late',
        checkIn: record.checkIn || '-',
        checkOut: record.checkOut || '-',
        action: 'Review'
      }
    })
  },
  missingCheckout: {
    label: 'Missing Checkout',
    tone: 'text-rose-600',
    helper: 'Checked in but checkout is missing',
    showTimes: true,
    rows: hrAttendanceExceptions.value.missingCheckout.map(record => {
      const employee = state.employees.find(item => item.id === record.employeeId)
      return {
        id: `missing-${record.id}`,
        employee,
        employeeName: employee?.name || 'Unknown employee',
        department: employee?.department || 'Not specified',
        status: 'Missing checkout',
        checkIn: record.checkIn || '-',
        checkOut: record.checkOut || '-',
        action: 'Request checkout'
      }
    })
  }
}))
// Drill-down state for the exception modal. This lives with the cards that set
// it — previously the cards were here while the modal read a separate ref in
// Dashboard.vue, so clicking a card did nothing.
const activeAttendanceExceptionGroup = computed(() =>
  activeAttendanceException.value ? hrAttendanceExceptionGroups.value[activeAttendanceException.value] : null
)
const filteredAttendanceExceptionRows = computed(() => {
  const rows = activeAttendanceExceptionGroup.value?.rows || []
  const query = attendanceExceptionSearch.value.trim().toLowerCase()
  return rows.filter(row =>
    (!attendanceExceptionDepartment.value || row.department === attendanceExceptionDepartment.value)
    && (!query
      || row.employeeName.toLowerCase().includes(query)
      || row.department.toLowerCase().includes(query)
      || row.status.toLowerCase().includes(query))
  )
})
const attendanceExceptionDepartments = computed(() =>
  Array.from(new Set((activeAttendanceExceptionGroup.value?.rows || []).map(row => row.department))).sort()
)

const followUpTarget = ref(null)
const followUpNote = ref('')
const followUpMessage = ref('')

function openAttendanceFollowUp(row) {
  followUpTarget.value = row
  followUpNote.value = ''
  followUpMessage.value = ''
}

function sendAttendanceFollowUp() {
  if (!followUpTarget.value) return
  followUpMessage.value = `Follow-up recorded for ${followUpTarget.value.employeeName}.`
  followUpNote.value = ''
}

function exportAttendanceExceptions() {
  attendanceExportMessage.value = `${filteredAttendanceExceptionRows.value.length} ${activeAttendanceExceptionGroup.value?.label || 'attendance'} records prepared for export.`
}

function approveLeaveRequest(leave) {
  if (leave.employeeId === state.currentUser.id) {
    hrActionMessage.value = 'HR cannot approve their own leave request.'
    return
  }
  leave.status = 'approved'
  leave.hrApprovedBy = state.currentUser.name
  hrActionMessage.value = `Leave approved for ${employeeName(leave.employeeId)}.`
}

function rejectLeaveRequest(leave) {
  if (leave.employeeId === state.currentUser.id) {
    hrActionMessage.value = 'HR cannot reject their own leave request.'
    return
  }
  leave.status = 'rejected'
  leave.hrRejectedBy = state.currentUser.name
  hrActionMessage.value = `Leave rejected for ${employeeName(leave.employeeId)}.`
}

function processClaim(claim) {
  claim.status = 'processing'
  claim.hrReviewedBy = state.currentUser.name
  hrActionMessage.value = `Claim moved to processing for ${employeeName(claim.employeeId)}.`
}

function approveClaim(claim) {
  claim.status = 'approved'
  claim.hrApprovedBy = state.currentUser.name
  hrActionMessage.value = `Claim approved for ${employeeName(claim.employeeId)}.`
}

function rejectClaim(claim) {
  claim.status = 'rejected'
  claim.hrRejectedBy = state.currentUser.name
  hrActionMessage.value = `Claim rejected for ${employeeName(claim.employeeId)}.`
}

function verifyEmployeeDocuments(item) {
  if (!item.employee.onboarding) item.employee.onboarding = { personal: {}, technical: {}, documents: {} }
  if (!item.employee.onboarding.documents) item.employee.onboarding.documents = {}
  item.employee.onboarding.documents.verificationStatus = 'verified'
  item.employee.onboarding.documents.verifiedBy = state.currentUser.name
  hrActionMessage.value = `Documents marked verified for ${item.employee.name}.`
}

function rejectEmployeeDocuments(item) {
  if (!item.employee.onboarding) item.employee.onboarding = { personal: {}, technical: {}, documents: {} }
  if (!item.employee.onboarding.documents) item.employee.onboarding.documents = {}
  item.employee.onboarding.documents.verificationStatus = 'rejected'
  item.employee.onboarding.documents.rejectedBy = state.currentUser.name
  hrActionMessage.value = `Document correction requested from ${item.employee.name}.`
}

function resendInvite(employee) {
  employee.status = 'invited'
  hrActionMessage.value = `Invite reminder sent to ${employee.name}.`
}

function markOnboardingReviewed(employee) {
  employee.onboardingReviewedBy = state.currentUser.name
  employee.onboardingReviewedAt = new Date().toISOString()
  if (employee.status === 'invited') employee.status = 'active'
  hrActionMessage.value = `Onboarding reviewed for ${employee.name}.`
}
</script>

<template>
  <div>
  <div class="space-y-6 dashboard-inline-attendance">
    <div class="space-y-6">
      <div class="mb-8">
        <h2 class="text-3xl font-bold text-slate-900">HR Dashboard</h2>
        <p class="text-slate-600 mt-1">Attendance, employee requests, and HR operations</p>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <div class="text-sm font-medium text-slate-900 mb-1">Today attendance</div>
          <div class="text-xs text-slate-600" v-if="dashboardCheckedIn">Checked in at <span class="font-mono text-emerald-700">{{ dashboardCheckInTime }}</span></div>
          <div class="text-xs text-slate-600" v-else>Capture your photo before marking HR attendance</div>
        </div>
        <button
          v-if="dashboardCheckedIn"
          @click="dashboardCheckOut"
          class="bg-rose-100 text-rose-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-rose-200 transition-colors"
        >
          Check out
        </button>
        <button
          v-else
          @click="openCamera"
          class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors"
        >
          Check in
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-6 gap-4">
        <div role="button" tabindex="0" @click="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" @keydown.enter="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" @keydown.space.prevent="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Checked In</div>
          <div class="text-2xl font-bold text-emerald-600 leading-none">{{ checkedInToday }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Today</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" @keydown.enter="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" @keydown.space.prevent="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Late Today</div>
          <div class="text-2xl font-bold text-orange-600 leading-none">{{ lateToday }}</div>
          <div class="text-orange-600 text-[11px] mt-1">Attendance exceptions</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" @keydown.enter="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" @keydown.space.prevent="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Leave Approvals</div>
          <div class="kpi-value text-slate-900">{{ hrPendingLeaves.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Waiting for HR</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" @keydown.enter="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" @keydown.space.prevent="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Medical Claims</div>
          <div class="kpi-value text-slate-900">{{ hrPendingClaims.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Need review</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" @keydown.enter="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" @keydown.space.prevent="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Onboarding</div>
          <div class="kpi-value text-slate-900">{{ hrOnboardingQueue.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Invites/submissions</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" @keydown.enter="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" @keydown.space.prevent="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Missing Docs</div>
          <div class="text-2xl font-bold text-rose-600 leading-none">{{ hrMissingDocuments.length }}</div>
          <div class="text-rose-600 text-[11px] mt-1">Employee files</div>
        </div>
      </div>

      <div v-if="hrActionMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
        {{ hrActionMessage }}
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.65fr)_minmax(320px,0.35fr)] gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Attendance Exceptions</h2>
              <div class="text-xs text-slate-600 mt-1">Counts stay readable for large teams. Open any bucket for the full filtered list.</div>
            </div>
            <RouterLink to="/attendance" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Open attendance</RouterLink>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <button
              v-for="(group, key) in hrAttendanceExceptionGroups"
              :key="key"
              type="button"
              class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-left transition-colors hover:bg-white"
              @click="activeAttendanceException = key; attendanceExceptionSearch = ''; attendanceExceptionDepartment = ''; attendanceExportMessage = ''"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ group.label }}</div>
                  <div class="text-2xl font-extrabold mt-1" :class="group.tone">{{ group.rows.length }}</div>
                  <div class="text-[11px] font-semibold text-slate-500 mt-1">{{ group.helper }}</div>
                </div>
                <span class="text-xs font-bold text-slate-500">Open</span>
              </div>
            </button>
          </div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <h2 class="text-lg font-bold text-slate-900 mb-4">HR Shortcuts</h2>
          <div class="grid grid-cols-2 gap-3">
            <RouterLink to="/employees" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Employees</RouterLink>
            <RouterLink to="/leave-requests" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Leaves</RouterLink>
            <RouterLink to="/medical-claims" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Claims</RouterLink>
            <RouterLink to="/payroll" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Payroll</RouterLink>
            <RouterLink to="/calendar" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Calendar</RouterLink>
            <RouterLink to="/company-hub" class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm font-bold text-slate-900 hover:bg-white">Company Hub</RouterLink>
          </div>
        </section>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.65fr)_minmax(320px,0.35fr)] gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">{{ timesheetTitle }}</h2>
              <div class="text-xs text-slate-600 mt-1">Daily attendance vs ticket work logged.</div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
              <div class="flex items-center gap-2 text-[11px] font-bold text-slate-600">
                <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                <span>Attendance</span>
              </div>
              <div class="flex items-center gap-2 text-[11px] font-bold text-slate-600">
                <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                <span>Work logged</span>
              </div>
              <span class="company-hub-badge company-hub-badge-info">Weekly</span>
            </div>
          </div>
          <TimesheetChart :days="dailyTimesheet" :max-hours="weeklyTimesheetMaxHours" :format-duration="formatDuration" />
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">People Calendar</h2>
              <div class="text-xs text-slate-600 mt-1">Birthdays and work anniversaries in the next 30 days.</div>
            </div>
            <RouterLink to="/calendar" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Calendar</RouterLink>
          </div>
          <div class="space-y-4">
            <div>
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">Birthdays</div>
              <div v-if="hrUpcomingBirthdays.length" class="space-y-2 max-h-[130px] overflow-y-auto pr-1">
                <div v-for="employee in hrUpcomingBirthdays" :key="`top-birthday-${employee.id}`" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-3">
                  <span class="text-sm font-bold text-slate-900">{{ employee.name }}</span>
                  <span class="text-xs font-bold text-slate-500">{{ employee.daysAway }}d</span>
                </div>
              </div>
              <div v-else class="text-xs font-semibold text-slate-500">No birthdays soon.</div>
            </div>
            <div>
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-2">Anniversaries</div>
              <div v-if="hrUpcomingAnniversaries.length" class="space-y-2 max-h-[130px] overflow-y-auto pr-1">
                <div v-for="employee in hrUpcomingAnniversaries" :key="`top-anniversary-${employee.id}`" class="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-3">
                  <span class="text-sm font-bold text-slate-900">{{ employee.name }}</span>
                  <span class="text-xs font-bold text-slate-500">{{ employee.daysAway }}d</span>
                </div>
              </div>
              <div v-else class="text-xs font-semibold text-slate-500">No anniversaries soon.</div>
            </div>
          </div>
        </section>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Leave Approval Queue</h2>
              <div class="text-xs text-slate-600 mt-1">HR can review employee leaves after manager approval. HR cannot approve their own leave.</div>
            </div>
            <RouterLink to="/leave-requests" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View all</RouterLink>
          </div>
          <div v-if="hrPendingLeaves.length" class="space-y-3 max-h-[360px] overflow-y-auto pr-1">
            <div v-for="leave in hrPendingLeaves" :key="leave.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ employeeName(leave.employeeId) }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ leave.type }} - {{ formatDate(leave.from) }} to {{ formatDate(leave.to) }}</div>
                </div>
                <span class="company-hub-badge capitalize" :class="statusBadgeClass(leave.status)">{{ leave.status }}</span>
              </div>
              <div class="flex items-center justify-end gap-2 mt-3">
                <button @click="rejectLeaveRequest(leave)" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white">Reject</button>
                <button @click="approveLeaveRequest(leave)" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700">Approve</button>
              </div>
            </div>
          </div>
          <div v-else class="text-sm font-semibold text-slate-500 py-4">No leave requests waiting for HR.</div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Medical Claim Queue</h2>
              <div class="text-xs text-slate-600 mt-1">Review receipts, provider details, status, and HR notes.</div>
            </div>
            <RouterLink to="/medical-claims" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View all</RouterLink>
          </div>
          <div v-if="state.medicalClaims.length" class="space-y-3 max-h-[360px] overflow-y-auto pr-1">
            <div v-for="claim in state.medicalClaims" :key="claim.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ employeeName(claim.employeeId) }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ claim.type }} - {{ formatCurrency(claim.amount) }}</div>
                </div>
                <span class="company-hub-badge capitalize" :class="statusBadgeClass(claim.status)">{{ claim.status }}</span>
              </div>
              <div class="flex items-center justify-end gap-2 mt-3">
                <button @click="rejectClaim(claim)" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white">Reject</button>
                <button @click="processClaim(claim)" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white">Processing</button>
                <button @click="approveClaim(claim)" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700">Approve</button>
              </div>
            </div>
          </div>
          <div v-else class="text-sm font-semibold text-slate-500 py-4">No medical claims submitted.</div>
        </section>
      </div>

      <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
        <div class="flex items-center justify-between gap-3 mb-4">
          <div>
            <h2 class="text-lg font-bold text-slate-900">Onboarding Queue</h2>
            <div class="text-xs text-slate-600 mt-1">Invited employees and submitted onboarding records waiting for HR review.</div>
          </div>
          <RouterLink to="/employees" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Employees</RouterLink>
        </div>
        <div v-if="hrOnboardingQueue.length" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 max-h-[360px] overflow-y-auto pr-1">
          <div v-for="employee in hrOnboardingQueue" :key="employee.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="text-sm font-extrabold text-slate-900">{{ employee.name }}</div>
                <div class="text-xs text-slate-600 mt-1">{{ employee.email }}</div>
              </div>
              <span class="company-hub-badge capitalize" :class="statusBadgeClass(employee.status)">{{ employee.status }}</span>
            </div>
            <div class="flex items-center justify-end gap-2 mt-3">
              <button @click="resendInvite(employee)" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white">Resend invite</button>
              <button @click="markOnboardingReviewed(employee)" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700">Mark reviewed</button>
            </div>
          </div>
        </div>
        <div v-else class="text-sm font-semibold text-slate-500 py-4">No onboarding records waiting for HR.</div>
      </section>

      <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900 mb-4">Today's Punch Ledger</h2>
        <PunchLedger :rows="ledgerRows" />
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Document Review</h2>
              <div class="text-xs text-slate-600 mt-1">Missing onboarding, payroll, and identity documents.</div>
            </div>
            <RouterLink to="/employees" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Review</RouterLink>
          </div>
          <div v-if="hrMissingDocuments.length" class="space-y-3 max-h-[320px] overflow-y-auto pr-1">
            <div v-for="item in hrMissingDocuments.slice(0, 5)" :key="item.employee.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ item.employee.name }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ item.employee.email }}</div>
                </div>
                <span class="company-hub-badge company-hub-badge-warning">{{ item.employee.onboarding?.documents?.verificationStatus || 'Needs review' }}</span>
              </div>
              <div class="flex flex-wrap gap-2 mt-3">
                <span v-for="missing in item.missing.slice(0, 4)" :key="missing" class="company-hub-badge company-hub-badge-warning">{{ missing }}</span>
              </div>
              <div class="flex items-center justify-end gap-2 mt-3">
                <button @click="rejectEmployeeDocuments(item)" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white">Request correction</button>
                <button @click="verifyEmployeeDocuments(item)" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700">Mark verified</button>
              </div>
            </div>
          </div>
          <div v-else class="text-sm font-semibold text-slate-500 py-4">All employee documents look complete.</div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Payroll Support</h2>
              <div class="text-xs text-slate-600 mt-1">Salary slips and payroll records requiring HR follow-up.</div>
            </div>
            <RouterLink to="/payroll" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Payroll</RouterLink>
          </div>
          <div v-if="state.salarySlipRequests.length" class="space-y-3 max-h-[320px] overflow-y-auto pr-1">
            <div v-for="slip in state.salarySlipRequests" :key="slip.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ employeeName(slip.employeeId) }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ slip.month || slip.period || 'Payroll period' }}</div>
                </div>
                <span class="company-hub-badge capitalize" :class="statusBadgeClass(slip.status)">{{ slip.status }}</span>
              </div>
            </div>
          </div>
          <div v-else class="text-sm font-semibold text-slate-500 py-4">No salary slip records.</div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Reports</h2>
              <div class="text-xs text-slate-600 mt-1">Export-ready HR summaries.</div>
            </div>
            <RouterLink to="/reports" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Open</RouterLink>
          </div>
          <div class="space-y-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-sm font-bold text-slate-900">Attendance report</div>
              <div class="text-xs text-slate-600 mt-1">Late, absent, missing checkout.</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-sm font-bold text-slate-900">Leave and claims</div>
              <div class="text-xs text-slate-600 mt-1">Approvals, amounts, statuses.</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-sm font-bold text-slate-900">Onboarding report</div>
              <div class="text-xs text-slate-600 mt-1">Completion and missing docs.</div>
            </div>
          </div>
        </section>
      </div>

      <div v-if="false" class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="text-sm font-semibold text-slate-900 mb-3">Medical Claims</div>
          <div class="space-y-3">
            <div v-for="claim in state.medicalClaims" :key="claim.id" class="rounded-lg border border-slate-200 p-3">
              <div class="text-sm font-medium text-slate-900">{{ employeeName(claim.employeeId) }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ claim.type }} · {{ formatCurrency(claim.amount) }}</div>
              <div class="text-[11px] text-slate-500 mt-2 capitalize">{{ claim.status }}</div>
            </div>
          </div>
        </div>
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="text-sm font-semibold text-slate-900 mb-3">Leave Requests</div>
          <div class="space-y-3">
            <div v-for="leave in state.leaveRequests" :key="leave.id" class="rounded-lg border border-slate-200 p-3">
              <div class="text-sm font-medium text-slate-900">{{ employeeName(leave.employeeId) }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ leave.type }} · {{ leave.from }} to {{ leave.to }}</div>
              <div class="text-[11px] text-slate-500 mt-2 capitalize">{{ leave.status }}</div>
            </div>
          </div>
        </div>
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="text-sm font-semibold text-slate-900 mb-3">Payroll Requests</div>
          <div class="space-y-3">
            <div v-for="slip in state.salarySlipRequests" :key="slip.id" class="rounded-lg border border-slate-200 p-3">
              <div class="text-sm font-medium text-slate-900">{{ employeeName(slip.employeeId) }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ slip.month }}</div>
              <div class="text-[11px] text-slate-500 mt-2 capitalize">{{ slip.status }}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Attendance exception drill-down + follow-up. These live here, not in
       Dashboard.vue, because the state that drives them is HR state. -->
  <Teleport to="body">
    <div v-if="activeAttendanceExceptionGroup" class="fixed inset-0 z-50 bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="activeAttendanceException = ''">
      <div class="w-full max-w-5xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="p-5 border-b border-slate-200 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
          <div>
            <div class="text-lg font-extrabold text-slate-900">{{ activeAttendanceExceptionGroup.label }}</div>
            <div class="text-sm text-slate-600 mt-1">{{ activeAttendanceExceptionGroup.rows.length }} employees - {{ activeAttendanceExceptionGroup.helper }}</div>
          </div>
          <div class="flex flex-col sm:flex-row sm:items-center gap-2">
            <input
              v-model="attendanceExceptionSearch"
              class="w-full lg:w-72 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
              placeholder="Search employee or department..."
            />
            <select
              v-model="attendanceExceptionDepartment"
              class="w-full sm:w-48 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              <option value="">All departments</option>
              <option v-for="department in attendanceExceptionDepartments" :key="department" :value="department">{{ department }}</option>
            </select>
            <button @click="exportAttendanceExceptions" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
              Export
            </button>
            <button @click="activeAttendanceException = ''" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
              Close
            </button>
          </div>
        </div>
        <div class="p-5 space-y-3">
          <div v-if="attendanceExportMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
            {{ attendanceExportMessage }}
          </div>
          <div
            class="hidden md:grid gap-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-[11px] font-extrabold uppercase tracking-wide text-slate-500"
            :class="activeAttendanceExceptionGroup.showTimes ? 'grid-cols-[1.4fr_1fr_0.9fr_0.8fr_0.8fr_1fr]' : 'grid-cols-[1.5fr_1fr_0.9fr_1fr]'"
          >
            <div>Employee</div>
            <div>Department</div>
            <div>Status</div>
            <div v-if="activeAttendanceExceptionGroup.showTimes">Check In</div>
            <div v-if="activeAttendanceExceptionGroup.showTimes">Check Out</div>
            <div>Action</div>
          </div>
          <!-- Striped rows rather than detached cards, matching the tables. -->
          <div class="max-h-[520px] overflow-y-auto rounded-xl border border-slate-200 overflow-hidden">
            <div
              v-for="row in filteredAttendanceExceptionRows"
              :key="row.id"
              class="modal-row grid grid-cols-1 gap-3 md:gap-4 px-4 py-3"
              :class="activeAttendanceExceptionGroup.showTimes ? 'md:grid-cols-[1.4fr_1fr_0.9fr_0.8fr_0.8fr_1fr]' : 'md:grid-cols-[1.5fr_1fr_0.9fr_1fr]'"
            >
              <div>
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Employee</div>
                <div class="font-extrabold text-slate-900">{{ row.employeeName }}</div>
                <div class="text-xs text-slate-500">ID #{{ row.employee?.id || '-' }}</div>
              </div>
              <div>
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Department</div>
                <div class="font-semibold text-slate-700">{{ row.department }}</div>
              </div>
              <div>
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Status</div>
                <span class="company-hub-badge company-hub-badge-warning">{{ row.status }}</span>
              </div>
              <div v-if="activeAttendanceExceptionGroup.showTimes">
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Check In</div>
                <div class="font-mono font-bold text-slate-700">{{ row.checkIn }}</div>
              </div>
              <div v-if="activeAttendanceExceptionGroup.showTimes">
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Check Out</div>
                <div class="font-mono font-bold text-slate-700">{{ row.checkOut }}</div>
              </div>
              <div>
                <div class="md:hidden text-[10px] font-bold uppercase tracking-wide text-slate-500 mb-1">Action</div>
                <button
                  type="button"
                  class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50"
                  @click="openAttendanceFollowUp(row)"
                >
                  {{ row.action }}
                </button>
              </div>
            </div>
            <div v-if="filteredAttendanceExceptionRows.length === 0" class="bg-slate-50 p-6 text-center text-sm font-semibold text-slate-500">
              No matching employees found.
            </div>
          </div>
          <div class="text-xs font-semibold text-slate-500">
            Showing {{ filteredAttendanceExceptionRows.length }} of {{ activeAttendanceExceptionGroup.rows.length }} records.
          </div>
        </div>
      </div>
    </div>

    <div v-if="followUpTarget" class="fixed inset-0 z-[60] bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="followUpTarget = null">
      <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="p-5 border-b border-slate-200 flex items-start justify-between gap-4">
          <div>
            <div class="text-lg font-extrabold text-slate-900">Attendance follow-up</div>
            <div class="text-sm text-slate-600 mt-1">{{ followUpTarget.employeeName }} - {{ followUpTarget.status }}</div>
          </div>
          <button @click="followUpTarget = null" class="text-slate-500 hover:text-slate-900 text-lg leading-none">x</button>
        </div>
        <div class="p-5 space-y-4">
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Department</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ followUpTarget.department }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Action</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ followUpTarget.action }}</div>
            </div>
          </div>
          <div>
            <label class="block">
            <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">HR note</span>
            <textarea
              v-model="followUpNote"
              rows="4"
              class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
              placeholder="Example: Called employee, requested reason, reminder sent..."
            ></textarea>
            </label>
          </div>
          <div v-if="followUpMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
            {{ followUpMessage }}
          </div>
          <div class="flex items-center justify-end gap-2">
            <button @click="followUpTarget = null" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
              Close
            </button>
            <button @click="sendAttendanceFollowUp" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
              Save follow-up
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
  </div>
</template>

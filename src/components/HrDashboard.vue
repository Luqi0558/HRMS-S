<script setup>
import { computed, onMounted, ref } from 'vue'
import CardEmptyState from './CardEmptyState.vue'
import PunchLedger from './PunchLedger.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'
import { employeeService } from '../services/employeeService.js'
import { leaveService } from '../services/leaveService.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { attendanceFollowUpService } from '../services/attendanceFollowUpService.js'

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
  timesheetPeriod,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  daysUntilMonthDay,
  ledgerRowsFor,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera,
  upsertLeaveRequest,
  upsertMedicalClaim,
  logActivity,
  isAwaitingOnboardingReview,
  documentsByKey
} = useDashboard()

const activeAttendanceException = ref('')
const attendanceExceptionSearch = ref('')
const attendanceExceptionDepartment = ref('')
const attendanceExportMessage = ref('')
const hrActionMessage = ref('')
const hrDocumentActionError = ref('')
const documentReviewingId = ref(null)
// The onboarding card whose action is in flight, so its buttons can say so.
const onboardingActionId = ref(null)
const hrLeaveActionError = ref('')
const leaveDecisionId = ref(null)
const hrClaimActionError = ref('')
const claimDecisionId = ref(null)

// HR sees every employee in the punch ledger.
const ledgerRows = computed(() => ledgerRowsFor(null))

const checkedInToday = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.checkIn).length
)
const lateToday = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.status === 'late').length
)
// A remote work day must be visible as an operational roster, not hidden in a
// generic attendance ledger. `mixed` means the employee has worked both from
// the office and from home in separate sessions today.
const hrRemoteToday = computed(() =>
  state.attendance.filter(row =>
    row.date === todayKey()
    && row.checkIn
    && (row.workMode === 'remote' || row.dayWorkMode === 'mixed')
  )
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
  // employeeId, not currentUser.id: leave rows reference the employees table.
  // The intent is "not my own request", which only held when the two id spaces
  // happened to line up.
  state.leaveRequests
    .filter(leave => leave.status === 'pending' && leave.employeeId !== (state.currentUser.employeeId ?? state.currentUser.id))
    // A decision at the HR stage is persisted even when another required
    // stage remains. Do not leave the same request actionable for HR again.
    .filter(leave => leave.hrApproval?.status !== 'approved')
)
const hrPendingClaims = computed(() =>
  // Undecided, wherever it is in review — a claim being processed is still
  // waiting on HR.
  state.medicalClaims.filter(claim => ['pending', 'view', 'processing'].includes(claim.status))
)
const hrOnboardingQueue = computed(() => state.employees.filter(isAwaitingOnboardingReview))
const hrMissingDocuments = computed(() =>
  state.employees
    .filter(employee => employee.role !== 'super_admin')
    .map(employee => {
      const personal = employee.onboarding?.personal || {}
      const documents = documentsByKey(employee)
      const missing = [
        !personal.cnic && 'CNIC',
        !personal.bankAccount && 'Bank',
        !personal.emergencyContact && 'Emergency contact',
        !documents.cv && 'CV',
        !documents.contract && 'Contract'
      ].filter(Boolean)
      return { employee, missing }
    })
    .filter(item => item.missing.length && item.employee.onboardingReview?.status !== 'verified')
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
      issue: 'absent',
      attendanceId: null,
      date: todayKey(),
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
        issue: 'late',
        attendanceId: record.id,
        date: record.date,
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
        issue: 'missing_checkout',
        attendanceId: record.id,
        date: record.date,
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

// --- attendance follow-ups -------------------------------------------------
//
// HR's internal record of chasing an exception. This dialog used to show
// "Follow-up recorded" and store nothing. Notes are saved on the server and
// visible only to people who supervise the employee's attendance; messaging
// the employee is a separate, optional step with its own text.

const FOLLOW_UP_ACTIONS = [
  { value: 'note', label: 'Note only' },
  { value: 'called', label: 'Called' },
  { value: 'emailed', label: 'Emailed' },
  { value: 'met', label: 'Met in person' },
  { value: 'reminder', label: 'Reminder sent' },
  { value: 'warning', label: 'Warning issued' }
]
const FOLLOW_UP_ISSUE_LABELS = {
  late: 'Late',
  absent: 'Absent',
  missing_checkout: 'Missing checkout',
  early_leave: 'Left early',
  other: 'Other'
}
// Repeat follow-ups inside this window are flagged, so a pattern is visible
// before the next one is written.
const FOLLOW_UP_PATTERN_DAYS = 30
const FOLLOW_UP_PATTERN_COUNT = 3

const followUpTarget = ref(null)
const followUpForm = ref({ action: 'note', note: '', notifyEmployee: false, message: '' })
const followUpMessage = ref('')
const followUpError = ref('')
const savingFollowUp = ref(false)
const followUpHistory = ref([])
const loadingFollowUpHistory = ref(false)
const resolvingFollowUpId = ref(null)
const resolutionDraft = ref('')
// Today's follow-ups, so the exception list can show which rows are handled.
const todaysFollowUps = ref([])

function followUpKey(employeeId, issue) {
  return `${employeeId}:${issue}`
}

const todaysFollowUpIndex = computed(() =>
  new Map(todaysFollowUps.value.map(item => [followUpKey(item.employeeId, item.issue), item]))
)

/** Today's follow-up for an exception row, if HR has already recorded one. */
function followUpForRow(row) {
  return row?.employee ? todaysFollowUpIndex.value.get(followUpKey(row.employee.id, row.issue)) || null : null
}

/** Follow-ups for this employee in the last thirty days — a pattern worth seeing. */
const recentFollowUpCount = computed(() => {
  const since = Date.now() - FOLLOW_UP_PATTERN_DAYS * 24 * 60 * 60 * 1000
  return followUpHistory.value.filter(item => new Date(item.date).getTime() >= since).length
})

/** A polite first draft of the employee message, specific to the issue. */
function suggestedEmployeeMessage(row) {
  const day = row?.date ? formatDate(row.date) : 'today'
  if (row?.issue === 'absent') return `Hi ${row.employeeName}, we have no check-in from you for ${day}. Please let HR know the reason, or apply for leave if you are away.`
  if (row?.issue === 'late') return `Hi ${row.employeeName}, your check-in on ${day} was after the grace time. Please let HR know if there was a reason we should be aware of.`
  if (row?.issue === 'missing_checkout') return `Hi ${row.employeeName}, there is no check-out recorded for ${day}. Please check out, or tell HR when you finished so the record can be corrected.`
  return `Hi ${row?.employeeName || ''}, HR would like a quick word about your attendance on ${day}.`
}

async function loadTodaysFollowUps() {
  try {
    todaysFollowUps.value = await attendanceFollowUpService.list({ from: todayKey(), to: todayKey() })
  } catch {
    // Without them the list simply shows no "followed up" badges.
    todaysFollowUps.value = []
  }
}

async function openAttendanceFollowUp(row) {
  followUpTarget.value = row
  followUpForm.value = { action: 'note', note: '', notifyEmployee: false, message: suggestedEmployeeMessage(row) }
  followUpMessage.value = ''
  followUpError.value = ''
  resolvingFollowUpId.value = null
  followUpHistory.value = []

  if (!row?.employee) return

  loadingFollowUpHistory.value = true
  try {
    followUpHistory.value = await attendanceFollowUpService.list({ employeeId: row.employee.id })
  } catch (error) {
    followUpError.value = error?.message || 'Previous follow-ups could not be loaded.'
  } finally {
    loadingFollowUpHistory.value = false
  }
}

async function sendAttendanceFollowUp() {
  const row = followUpTarget.value
  if (!row?.employee || savingFollowUp.value) return

  const note = followUpForm.value.note.trim()
  const message = followUpForm.value.notifyEmployee ? followUpForm.value.message.trim() : ''
  if (!note) {
    followUpError.value = 'Write an internal note describing the follow-up.'
    return
  }
  if (followUpForm.value.notifyEmployee && !message) {
    followUpError.value = 'Write the message for the employee, or untick "Also message the employee".'
    return
  }

  savingFollowUp.value = true
  followUpError.value = ''
  followUpMessage.value = ''
  try {
    const saved = await attendanceFollowUpService.record({
      employeeId: row.employee.id,
      attendanceId: row.attendanceId,
      date: row.date,
      issue: row.issue,
      action: followUpForm.value.action,
      note,
      messageToEmployee: message || null
    })
    followUpHistory.value = [saved, ...followUpHistory.value]
    if (saved.date === todayKey()) todaysFollowUps.value = [saved, ...todaysFollowUps.value]
    followUpMessage.value = saved.employeeMessaged
      ? `Follow-up saved and ${row.employeeName} was messaged.`
      : `Follow-up saved for ${row.employeeName}.`
    followUpForm.value = { action: 'note', note: '', notifyEmployee: false, message: suggestedEmployeeMessage(row) }
  } catch (error) {
    followUpError.value = error?.message || 'The follow-up could not be saved.'
  } finally {
    savingFollowUp.value = false
  }
}

function startResolvingFollowUp(item) {
  resolvingFollowUpId.value = item.id
  resolutionDraft.value = ''
}

async function resolveFollowUp(item) {
  if (!item || savingFollowUp.value) return

  savingFollowUp.value = true
  followUpError.value = ''
  try {
    const resolved = await attendanceFollowUpService.resolve(item.id, resolutionDraft.value.trim() || null)
    const replace = list => list.map(entry => entry.id === resolved.id ? resolved : entry)
    followUpHistory.value = replace(followUpHistory.value)
    todaysFollowUps.value = replace(todaysFollowUps.value)
    resolvingFollowUpId.value = null
  } catch (error) {
    followUpError.value = error?.message || 'That follow-up could not be resolved.'
  } finally {
    savingFollowUp.value = false
  }
}

function followUpActionLabel(action) {
  return FOLLOW_UP_ACTIONS.find(item => item.value === action)?.label || action
}

onMounted(loadTodaysFollowUps)

/** One CSV cell: quoted, and defused if a spreadsheet would read it as a formula. */
function csvCell(value) {
  let text = String(value ?? '')
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`
  return `"${text.replace(/"/g, '""')}"`
}

/**
 * Downloads the rows on screen as CSV.
 *
 * This used to say "N records prepared for export" and download nothing. It
 * exports exactly what HR is looking at — the group, search and department
 * filter applied — including absences, which have no attendance record and so
 * cannot come from the server's attendance report.
 */
function exportAttendanceExceptions() {
  const group = activeAttendanceExceptionGroup.value
  const rows = filteredAttendanceExceptionRows.value
  if (!group || rows.length === 0) {
    attendanceExportMessage.value = 'There are no records to export.'
    return
  }

  const header = ['Employee', 'Department', 'Status', 'Date', 'Check in', 'Check out', 'Followed up']
  const lines = rows.map(row => [
    row.employeeName,
    row.department,
    row.status,
    row.date || todayKey(),
    row.checkIn,
    row.checkOut,
    followUpForRow(row) ? followUpForRow(row).status : 'no'
  ].map(csvCell).join(','))

  // A byte-order mark so Excel opens UTF-8 names correctly.
  const blob = new Blob(['\ufeff' + [header.map(csvCell).join(','), ...lines].join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `attendance-${group.label.toLowerCase().replace(/\s+/g, '-')}-${todayKey()}.csv`
  link.click()
  URL.revokeObjectURL(url)

  attendanceExportMessage.value = `${rows.length} ${group.label.toLowerCase()} record${rows.length === 1 ? '' : 's'} exported.`
}

async function decideLeaveRequest(leave, decision) {
  const currentEmployeeId = state.currentUser.employeeId ?? state.currentUser.id
  if (leave.employeeId === currentEmployeeId) {
    hrActionMessage.value = `HR cannot ${decision === 'approved' ? 'approve' : 'reject'} their own leave request.`
    return
  }

  hrActionMessage.value = ''
  hrLeaveActionError.value = ''
  leaveDecisionId.value = leave.id
  try {
    const updated = await leaveService.decide(leave.id, decision)
    upsertLeaveRequest(updated)
    if (decision === 'approved' && updated.status === 'pending') {
      hrActionMessage.value = `HR approval recorded for ${employeeName(leave.employeeId)}. The request is awaiting its remaining approval stage.`
    } else {
      hrActionMessage.value = decision === 'approved'
        ? `Leave approved for ${employeeName(leave.employeeId)}.`
        : `Leave rejected for ${employeeName(leave.employeeId)}.`
    }
  } catch (error) {
    hrLeaveActionError.value = error?.message || 'Unable to save the leave decision.'
  } finally {
    leaveDecisionId.value = null
  }
}

async function decideMedicalClaim(claim, decision) {
  if (claimDecisionId.value) return

  hrActionMessage.value = ''
  hrClaimActionError.value = ''
  claimDecisionId.value = claim.id
  try {
    // No approvedAmount means full approval, which is the explicit API
    // contract. Partial approvals belong on the claim detail screen where HR
    // can enter and explain the amount.
    const updated = await medicalClaimService.decide(claim.id, decision)
    upsertMedicalClaim(updated)
    hrActionMessage.value = decision === 'approved'
      ? `Medical claim approved for ${employeeName(claim.employeeId)}.`
      : `Medical claim rejected for ${employeeName(claim.employeeId)}.`
  } catch (error) {
    hrClaimActionError.value = error?.message || 'Unable to save the medical claim decision.'
  } finally {
    claimDecisionId.value = null
  }
}

async function reviewEmployeeDocuments(item, action) {
  if (documentReviewingId.value) return

  const note = action === 'correction_requested'
    ? `Please provide or correct: ${item.missing.join(', ')}.`
    : null

  hrActionMessage.value = ''
  hrDocumentActionError.value = ''
  documentReviewingId.value = item.employee.id
  try {
    const response = await employeeService.reviewDocuments(item.employee.id, { action, note })
    Object.assign(item.employee, response.employee)
    hrActionMessage.value = action === 'verified'
      ? `Documents marked verified for ${item.employee.name}.`
      : `Document correction request saved for ${item.employee.name}.`
  } catch (error) {
    hrDocumentActionError.value = error?.message || 'Unable to save the document review.'
  } finally {
    documentReviewingId.value = null
  }
}

/**
 * Sends a fresh invitation link.
 *
 * This used to set the status in memory and announce "Invite reminder sent"
 * without making any request — no email went out while HR was told it had.
 * The API issues a new link and revokes the old one; it refuses (409) when
 * this environment cannot send email, rather than stranding the invitee
 * without a working link, and that message is shown as it comes.
 */
async function resendInvite(employee) {
  if (onboardingActionId.value) return

  onboardingActionId.value = employee.id
  hrActionMessage.value = ''
  hrDocumentActionError.value = ''
  try {
    const response = await employeeService.resendInvitation(employee.id)
    hrActionMessage.value = response?.message || `A fresh invitation was sent to ${employee.email}.`
  } catch (error) {
    hrDocumentActionError.value = error?.message || 'The invitation could not be resent.'
  } finally {
    onboardingActionId.value = null
  }
}

/**
 * Records HR's review of submitted onboarding documents.
 *
 * Used to stamp a reviewer on the record in memory and flip `invited` to
 * `active` — neither saved, and the second is not HR's to decide: an account
 * becomes active when its owner completes onboarding. This is the same
 * document review the Missing Documents card performs.
 */
async function markOnboardingReviewed(employee) {
  if (onboardingActionId.value) return

  onboardingActionId.value = employee.id
  hrActionMessage.value = ''
  hrDocumentActionError.value = ''
  try {
    const response = await employeeService.reviewDocuments(employee.id, { action: 'verified', note: null })
    Object.assign(employee, response.employee)
    hrActionMessage.value = `Onboarding documents verified for ${employee.name}.`
  } catch (error) {
    hrDocumentActionError.value = error?.message || 'Unable to save the onboarding review.'
  } finally {
    onboardingActionId.value = null
  }
}
</script>

<template>
  <div>
  <div class="orbit-dashboard role-dashboard hr-dashboard space-y-5 dashboard-inline-attendance">
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
        <div role="button" tabindex="0" @click="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" @keydown.enter="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" @keydown.space.prevent="goToRoute({ name: 'attendance', query: { date: 'today', presence: 'checked-in' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Checked In</div>
          <div class="text-2xl font-bold text-emerald-600 leading-none">{{ checkedInToday }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Today</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" @keydown.enter="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" @keydown.space.prevent="goToRoute({ name: 'attendance', query: { date: 'today', status: 'late' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Late Today</div>
          <div class="text-2xl font-bold text-orange-600 leading-none">{{ lateToday }}</div>
          <div class="text-orange-600 text-[11px] mt-1">Attendance exceptions</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" @keydown.enter="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" @keydown.space.prevent="goToRoute({ name: 'leave-requests', query: { filter: 'pending-approval' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Leave Approvals</div>
          <div class="kpi-value text-slate-900">{{ hrPendingLeaves.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Waiting for HR</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" @keydown.enter="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" @keydown.space.prevent="goToRoute({ name: 'medical-claims', query: { filter: 'pending' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Medical Claims</div>
          <div class="kpi-value text-slate-900">{{ hrPendingClaims.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Need review</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" @keydown.enter="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" @keydown.space.prevent="goToRoute({ name: 'employees', query: { filter: 'onboarding' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Onboarding</div>
          <div class="kpi-value text-slate-900">{{ hrOnboardingQueue.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Invites/submissions</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" @keydown.enter="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" @keydown.space.prevent="goToRoute({ name: 'employees', query: { filter: 'missing-docs' } })" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-slate-600 text-xs font-medium mb-1">Missing Docs</div>
          <div class="text-2xl font-bold text-rose-600 leading-none">{{ hrMissingDocuments.length }}</div>
          <div class="text-rose-600 text-[11px] mt-1">Employee files</div>
        </div>
      </div>

      <div v-if="hrActionMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
        {{ hrActionMessage }}
      </div>
      <div v-if="hrDocumentActionError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
        {{ hrDocumentActionError }}
      </div>
      <div v-if="hrLeaveActionError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
        {{ hrLeaveActionError }}
      </div>
      <div v-if="hrClaimActionError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
        {{ hrClaimActionError }}
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
          <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
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
            <button
              type="button"
              class="rounded-xl border border-violet-200 bg-violet-50 p-4 text-left transition-colors hover:bg-violet-100"
              @click="goToRoute({ name: 'attendance', query: { date: 'today', workMode: 'remote' } })"
            >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-xs font-bold uppercase tracking-wide text-violet-700">WFH today</div>
                  <div class="text-2xl font-extrabold mt-1 text-violet-700">{{ hrRemoteToday.length }}</div>
                  <div class="text-[11px] font-semibold text-violet-700 mt-1">Remote or mixed location</div>
                </div>
                <span class="text-xs font-bold text-violet-700">Open</span>
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
                <span>At desk</span>
              </div>
              <div class="flex items-center gap-2 text-[11px] font-bold text-slate-600">
                <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                <span>On tickets</span>
              </div>
              <div class="flex items-center gap-1 rounded-lg bg-slate-100 p-0.5">
            <button
            v-for="period in ['weekly', 'monthly']"
            :key="period"
            @click="timesheetPeriod = period"
            class="rounded-md px-2.5 py-1 text-[11px] font-bold capitalize transition-colors"
            :class="timesheetPeriod === period ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'"
            >{{ period }}</button>
            </div>
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
                <button :disabled="leaveDecisionId === leave.id" @click="decideLeaveRequest(leave, 'rejected')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white disabled:opacity-60">{{ leaveDecisionId === leave.id ? 'Saving...' : 'Reject' }}</button>
                <button :disabled="leaveDecisionId === leave.id" @click="decideLeaveRequest(leave, 'approved')" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:opacity-60">{{ leaveDecisionId === leave.id ? 'Saving...' : 'Approve' }}</button>
              </div>
            </div>
          </div>
          <CardEmptyState
            v-else
            compact
            variant="calendar"
            title="No leave requests waiting"
            description="There are no employee leave requests requiring HR review."
          />
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Medical Claim Queue</h2>
              <div class="text-xs text-slate-600 mt-1">Claims awaiting a decision. Review receipts, provider details, and notes.</div>
            </div>
            <RouterLink to="/medical-claims" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View all</RouterLink>
          </div>
          <!-- hrPendingClaims, not every claim. This card is a queue: it lists
               what is waiting on a decision. Rendering the full list kept
               settled claims here for good, so a claim the super admin had
               already approved still looked like HR's to action. The same
               computed already feeds the KPI count above, and the Leave queue
               beside it was always filtered this way. -->
          <div v-if="hrPendingClaims.length" class="space-y-3 max-h-[360px] overflow-y-auto pr-1">
            <div v-for="claim in hrPendingClaims" :key="claim.id" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ employeeName(claim.employeeId) }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ claim.type }} - {{ formatCurrency(claim.amount) }}</div>
                </div>
                <span class="company-hub-badge capitalize" :class="statusBadgeClass(claim.status)">{{ claim.status }}</span>
              </div>
              <div class="flex items-center justify-end gap-2 mt-3">
                <button :disabled="claimDecisionId === claim.id" @click="decideMedicalClaim(claim, 'rejected')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white disabled:opacity-60">{{ claimDecisionId === claim.id ? 'Saving...' : 'Reject' }}</button>
                <button :disabled="claimDecisionId === claim.id" @click="decideMedicalClaim(claim, 'approved')" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:opacity-60">{{ claimDecisionId === claim.id ? 'Saving...' : 'Approve' }}</button>
              </div>
            </div>
          </div>
          <CardEmptyState
            v-else
            compact
            variant="review"
            title="No claims waiting on a decision"
            description="Medical claims that need HR review will appear here."
          />
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
              <button
                v-if="employee.status === 'invited'"
                :disabled="onboardingActionId !== null"
                @click="resendInvite(employee)"
                class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white disabled:opacity-60"
              >
                {{ onboardingActionId === employee.id ? 'Sending...' : 'Resend invite' }}
              </button>
              <button
                v-else
                :disabled="onboardingActionId !== null"
                @click="markOnboardingReviewed(employee)"
                class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:opacity-60"
              >
                {{ onboardingActionId === employee.id ? 'Saving...' : 'Mark verified' }}
              </button>
            </div>
          </div>
        </div>
        <CardEmptyState
          v-else
          compact
          variant="people"
          title="No onboarding records waiting"
          description="New employee onboarding submissions will appear here."
        />
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
                <span class="company-hub-badge company-hub-badge-warning">{{ item.employee.onboardingReview?.status === 'correction_requested' ? 'Correction requested' : 'Needs review' }}</span>
              </div>
              <div class="flex flex-wrap gap-2 mt-3">
                <span v-for="missing in item.missing.slice(0, 4)" :key="missing" class="company-hub-badge company-hub-badge-warning">{{ missing }}</span>
              </div>
              <div class="flex items-center justify-end gap-2 mt-3">
                <button :disabled="documentReviewingId === item.employee.id" @click="reviewEmployeeDocuments(item, 'correction_requested')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white disabled:opacity-60">{{ documentReviewingId === item.employee.id ? 'Saving...' : 'Request correction' }}</button>
                <button :disabled="documentReviewingId === item.employee.id" @click="reviewEmployeeDocuments(item, 'verified')" class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700 disabled:opacity-60">{{ documentReviewingId === item.employee.id ? 'Saving...' : 'Mark verified' }}</button>
              </div>
            </div>
          </div>
          <CardEmptyState
            v-else
            compact
            variant="review"
            title="All employee documents look complete"
            description="Documents needing review or correction will appear here."
          />
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
          <CardEmptyState
            v-else
            compact
            variant="inbox"
            title="No salary slip records"
            description="Payroll records requiring HR follow-up will appear here."
          />
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
                  class="text-xs font-bold rounded-lg border px-3 py-2 hover:bg-slate-50"
                  :class="followUpForRow(row) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-700'"
                  @click="openAttendanceFollowUp(row)"
                >
                  {{ followUpForRow(row) ? (followUpForRow(row).status === 'resolved' ? 'Resolved' : 'Followed up') : row.action }}
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
      <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="p-5 border-b border-slate-200 flex items-start justify-between gap-4">
          <div>
            <div class="text-lg font-extrabold text-slate-900">Attendance follow-up</div>
            <div class="text-sm text-slate-600 mt-1">{{ followUpTarget.employeeName }} - {{ followUpTarget.status }} - {{ formatDate(followUpTarget.date) }}</div>
          </div>
          <button @click="followUpTarget = null" class="text-slate-500 hover:text-slate-900 text-lg leading-none" aria-label="Close">x</button>
        </div>
        <div class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Department</div>
              <div class="text-sm font-extrabold text-slate-900 mt-1">{{ followUpTarget.department }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Last {{ FOLLOW_UP_PATTERN_DAYS }} days</div>
              <div class="text-sm font-extrabold mt-1" :class="recentFollowUpCount >= FOLLOW_UP_PATTERN_COUNT ? 'text-rose-700' : 'text-slate-900'">
                {{ recentFollowUpCount }} follow-up{{ recentFollowUpCount === 1 ? '' : 's' }}
              </div>
            </div>
          </div>

          <div v-if="recentFollowUpCount >= FOLLOW_UP_PATTERN_COUNT" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-800">
            {{ followUpTarget.employeeName }} has {{ recentFollowUpCount }} follow-ups in the last {{ FOLLOW_UP_PATTERN_DAYS }} days. Consider a conversation about the pattern rather than another reminder.
          </div>

          <div class="space-y-3">
            <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-2">What did you do?</span>
              <select v-model="followUpForm.action" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500">
                <option v-for="option in FOLLOW_UP_ACTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
              </select>
            </label>
            <label class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1">Internal note</span>
              <span class="text-[11px] text-slate-500 block mb-2">Visible to HR and the employee's supervisors only. The employee never sees this.</span>
              <textarea
                v-model="followUpForm.note"
                rows="3"
                maxlength="2000"
                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
                placeholder="Example: Called employee, requested reason, reminder sent..."
              ></textarea>
            </label>
            <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
              <input v-model="followUpForm.notifyEmployee" type="checkbox" class="rounded border-slate-300" />
              Also message the employee
            </label>
            <label v-if="followUpForm.notifyEmployee" class="block">
              <span class="text-xs font-bold uppercase tracking-wide text-slate-500 block mb-1">Message to {{ followUpTarget.employeeName }}</span>
              <span class="text-[11px] text-slate-500 block mb-2">Sent as a direct message. Write it for the employee to read.</span>
              <textarea
                v-model="followUpForm.message"
                rows="3"
                maxlength="2000"
                class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-emerald-500"
              ></textarea>
            </label>
          </div>

          <div v-if="followUpError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700" role="alert">
            {{ followUpError }}
          </div>
          <div v-if="followUpMessage" class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
            {{ followUpMessage }}
          </div>
          <div class="flex items-center justify-end gap-2">
            <button @click="followUpTarget = null" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
              Close
            </button>
            <button @click="sendAttendanceFollowUp" :disabled="savingFollowUp" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors disabled:opacity-60">
              {{ savingFollowUp ? 'Saving...' : 'Save follow-up' }}
            </button>
          </div>

          <div class="border-t border-slate-200 pt-4">
            <div class="text-xs font-bold uppercase tracking-wide text-slate-500 mb-3">Previous follow-ups</div>
            <div v-if="loadingFollowUpHistory" class="text-sm text-slate-500">Loading...</div>
            <div v-else-if="followUpHistory.length === 0" class="text-sm text-slate-500">No earlier follow-ups for {{ followUpTarget.employeeName }}.</div>
            <div v-else class="space-y-2">
              <div v-for="item in followUpHistory" :key="item.id" class="rounded-lg border border-slate-200 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="text-sm font-bold text-slate-900">
                      {{ FOLLOW_UP_ISSUE_LABELS[item.issue] || item.issue }} - {{ formatDate(item.date) }}
                      <span class="ml-1 text-xs font-semibold text-slate-500">{{ followUpActionLabel(item.action) }}</span>
                    </div>
                    <div class="text-sm text-slate-700 mt-1 whitespace-pre-wrap">{{ item.note }}</div>
                    <div class="text-[11px] text-slate-500 mt-1">
                      {{ item.createdByName || 'HR' }}<span v-if="item.employeeMessaged"> - employee messaged</span>
                    </div>
                    <div v-if="item.status === 'resolved'" class="text-[11px] text-emerald-700 mt-1">
                      Resolved by {{ item.resolvedByName || 'HR' }}<span v-if="item.resolution">: {{ item.resolution }}</span>
                    </div>
                  </div>
                  <span class="company-hub-badge flex-shrink-0" :class="item.status === 'resolved' ? 'company-hub-badge-success' : 'company-hub-badge-warning'">{{ item.status }}</span>
                </div>
                <div v-if="item.status === 'open'" class="mt-2">
                  <div v-if="resolvingFollowUpId === item.id" class="flex gap-2">
                    <input v-model="resolutionDraft" maxlength="2000" class="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-xs" placeholder="Outcome (optional), e.g. medical reason confirmed" />
                    <button @click="resolveFollowUp(item)" :disabled="savingFollowUp" class="text-xs font-bold rounded-lg bg-emerald-600 text-white px-3 py-1.5 disabled:opacity-60">Resolve</button>
                    <button @click="resolvingFollowUpId = null" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-1.5 text-slate-700">Cancel</button>
                  </div>
                  <button v-else @click="startResolvingFollowUp(item)" class="text-xs font-bold text-emerald-700 hover:text-emerald-800">Mark resolved</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
  </div>
</template>

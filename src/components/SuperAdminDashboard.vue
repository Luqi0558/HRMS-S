<script setup>
import { computed, onMounted, ref } from 'vue'
import CardEmptyState from './CardEmptyState.vue'
import PunchLedger from './PunchLedger.vue'
import { useDashboard } from '../composables/useDashboard.js'
import { leaveService } from '../services/leaveService.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { payrollService } from '../services/payrollService.js'
import { projectService } from '../services/projectService.js'
import { employeeService } from '../services/employeeService.js'
import { financeService } from '../services/financeService.js'

// Super Admin: company-wide oversight. Money in / money out / net position,
// approvals, delivery, and the audit trail. This branch was previously a bare
// `v-else`, which served the whole finance dashboard to any unrecognised role.
const {
  state,
  todayKey,
  formatDuration,
  formatCurrency,
  employeeName,
  formatDate,
  formatCompanyDate,
  formatDateTime,
  statusBadgeClass,
  goToRoute,
  ledgerRowsFor,
  projectReceived,
  projectPending,
  projectCommissionEarned,
  projectCommissionPaid,
  projectCommissionOwed,
  companyMoneyIn,
  companyMoneyOut,
  companyNetPosition,
  pettyCashBalance,
  pettyCashIsLow,
  pettyCashPendingExpenses,
  pettyCashPendingTopUps,
  upsertLeaveRequest,
  upsertMedicalClaim,
  setProjects,
  setModulePayments,
  paymentsForModule,
  logActivity,
  isAwaitingOnboardingReview,
  documentsByKey
} = useDashboard()

const superAdminActionMessage = ref('')
const superAdminActionError = ref('')
const superAdminDecisionKey = ref('')
const superAdminDetail = ref(null)
const superAdminPayrollRuns = ref([])

onMounted(async () => {
  // A Super Admin approves a submitted company payroll run, not each HR draft
  // payslip request. These are intentionally read from their dedicated API.
  const [runs, projects, payments] = await Promise.allSettled([
    payrollService.runs(),
    projectService.list(),
    // Every received, pending and commission figure below is derived from
    // these. Without them each one read zero.
    financeService.payments()
  ])

  if (runs.status === 'fulfilled') superAdminPayrollRuns.value = runs.value
  if (projects.status === 'fulfilled') setProjects(projects.value)
  if (payments.status === 'fulfilled') setModulePayments(payments.value)
})

// Super admin sees every employee in the punch ledger.
const ledgerRows = computed(() => ledgerRowsFor(null))

const checkedInToday = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.checkIn).length
)
const openTickets = computed(() => state.tickets.filter(ticket => ticket.status !== 'done').length)
const pendingInvites = computed(() => state.employees.filter(employee => employee.status === 'invited').length)
const totalRevenue = computed(() =>
  state.projects.reduce(
    (total, project) => total + (project.modules || []).reduce((sum, module) => sum + Number(module.budget || 0), 0),
    0
  )
)
const financeModules = computed(() =>
  state.projects.flatMap(project =>
    (project.modules || []).map(module => ({
      ...module,
      payments: paymentsForModule(module.id),
      categoryName: project.name,
      managerId: project.managerId,
      teamMembers: module.teamMembers || []
    }))
  )
)
// Company position, split by direction. Revenue alone was being presented as
// "Company Finance Snapshot", which hid every outgoing cost.
const moneyIn = computed(() => companyMoneyIn())
const moneyOut = computed(() => companyMoneyOut())
const netPosition = computed(() => companyNetPosition())
const expenseBreakdown = computed(() => [
  { key: 'payroll', label: 'Paid payroll', amount: moneyOut.value.payroll, route: { name: 'payroll' } },
  { key: 'commissions', label: 'Commissions owed', amount: moneyOut.value.commissions, route: { name: 'finance' } },
  { key: 'claims', label: 'Medical claims', amount: moneyOut.value.claims, route: { name: 'medical-claims' } },
  { key: 'petty', label: 'Petty cash', amount: moneyOut.value.petty, route: { name: 'petty-cash' } }
].sort((a, b) => b.amount - a.amount))

const superAdminFinanceTotals = computed(() => ({
  budget: financeModules.value.reduce((total, module) => total + Number(module.budget || 0), 0),
  received: financeModules.value.reduce((total, module) => total + projectReceived(module), 0),
  pending: financeModules.value.reduce((total, module) => total + projectPending(module), 0),
  commissionEarned: financeModules.value.reduce((total, module) => total + projectCommissionEarned(module), 0),
  commissionOwed: financeModules.value.reduce((total, module) => total + projectCommissionOwed(module), 0),
  commissionPaid: financeModules.value.reduce((total, module) => total + projectCommissionPaid(module), 0)
}))
const superAdminPendingCommissions = computed(() =>
  financeModules.value.filter(module => (module.commission?.status || 'pending') === 'pending' && projectCommissionEarned(module) > 0)
)
const superAdminApprovedCommissions = computed(() =>
  financeModules.value.filter(module => module.commission?.status === 'approved' && projectCommissionOwed(module) > 0)
)
const superAdminPendingClientBalances = computed(() =>
  financeModules.value.filter(module => projectPending(module) > 0)
)
const superAdminPayrollDue = computed(() =>
  superAdminPayrollRuns.value.filter(run => run.status === 'submitted')
)
const superAdminPendingApprovals = computed(() => [
  ...state.leaveRequests
    .filter(leave => leave.status === 'pending')
    .map(leave => ({
      id: `leave-${leave.id}`,
      type: 'Leave',
      title: employeeName(leave.employeeId),
      detail: `${leave.type} - ${formatDate(leave.from)} to ${formatDate(leave.to)}`,
      status: leave.status,
      route: '/leave-requests',
      sourceType: 'leave',
      source: leave
    })),
  ...state.medicalClaims
    .filter(claim => ['pending', 'view', 'processing'].includes(claim.status))
    .map(claim => ({
      id: `claim-${claim.id}`,
      type: 'Medical',
      title: employeeName(claim.employeeId),
      detail: `${claim.type} - ${formatCurrency(claim.amount)}`,
      status: claim.status,
      route: '/medical-claims',
      sourceType: 'claim',
      source: claim
    })),
  ...superAdminPendingCommissions.value.map(module => ({
    id: `commission-${module.id}`,
    type: 'Commission',
    title: module.name,
    detail: `${employeeName(module.managerId)} - ${formatCurrency(projectCommissionEarned(module))}`,
    status: module.commission?.status || 'pending',
    route: '/finance',
    sourceType: 'commission',
    source: module
  })),
  ...superAdminPayrollDue.value.map(request => ({
    id: `payroll-run-${request.id}`,
    type: 'Payroll run',
    title: `Payroll — ${formatDate(request.period)}`,
    detail: `${request.slipCount || 0} payslips prepared by HR`,
    status: request.status,
    route: '/payroll',
    sourceType: 'payrollRun',
    source: request
  }))
])
// Attendance/onboarding counts for the exceptions panel. Defined here rather
// than imported from HrDashboard — super admin reads the same underlying data
// but the two dashboards are independent components.
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
    .filter(item => item.missing.length)
)

const superAdminOperationalExceptions = computed(() => ({
  absent: hrAttendanceExceptions.value.absent.length,
  late: hrAttendanceExceptions.value.late.length,
  missingCheckout: hrAttendanceExceptions.value.missingCheckout.length,
  blockedUsers: state.employees.filter(employee => employee.status === 'blocked').length,
  pendingOnboarding: hrOnboardingQueue.value.length,
  missingDocuments: hrMissingDocuments.value.length
}))
const superAdminProjectDelivery = computed(() => ({
  activeModules: financeModules.value.filter(module => !['delivered', 'completed'].includes(module.status)).length,
  deliveredModules: financeModules.value.filter(module => ['delivered', 'completed'].includes(module.status)).length,
  openTickets: state.tickets.filter(ticket => ticket.status !== 'done').length,
  completedTickets: state.tickets.filter(ticket => ticket.status === 'done').length
}))
function isDashboardTicketOverdue(ticket) {
  return Boolean(ticket.deadline && ticket.status !== 'done' && ticket.deadline < todayKey())
}
const superAdminDeliveryRows = computed(() =>
  financeModules.value.map(module => {
    const tickets = state.tickets.filter(ticket => ticket.moduleId === module.id)
    const completedTickets = tickets.filter(ticket => ticket.status === 'done').length
    const openTickets = tickets.filter(ticket => ticket.status !== 'done').length
    const overdueTickets = tickets.filter(isDashboardTicketOverdue).length
    const completionPercent = tickets.length ? Math.round((completedTickets / tickets.length) * 100) : 0
    const moneyPercent = Number(module.budget || 0)
      ? Math.min(100, Math.round((projectReceived(module) / Number(module.budget || 0)) * 100))
      : 0
    return {
      ...module,
      totalTickets: tickets.length,
      openTickets,
      completedTickets,
      overdueTickets,
      completionPercent,
      moneyPercent
    }
  })
)
const superAdminAuditRows = computed(() =>
  state.activityLogs.slice(0, 8)
)
const superAdminControls = computed(() => [
  { label: 'Access Control', detail: 'Roles and permissions', route: '/access-control' },
  { label: 'Employees', detail: 'Block/unblock profiles', route: '/employees' },
  { label: 'Settings', detail: 'Company policy setup', route: '/settings' },
  { label: 'Activity Log', detail: 'Audit every action', route: '/activity-log' }
])
const superAdminTrends = computed(() => [
  {
    label: 'Revenue Collected',
    value: superAdminFinanceTotals.value.received,
    max: Math.max(1, superAdminFinanceTotals.value.budget),
    text: formatCurrency(superAdminFinanceTotals.value.received),
    shortLabel: 'Revenue',
    tone: 'bg-emerald-500',
    softTone: 'bg-emerald-500/10',
    textTone: 'text-emerald-600'
  },
  {
    label: 'Attendance Health',
    value: checkedInToday.value,
    max: Math.max(1, state.employees.filter(employee => employee.status === 'active').length),
    text: `${checkedInToday.value} checked in`,
    shortLabel: 'Attendance',
    tone: 'bg-blue-500',
    softTone: 'bg-blue-500/10',
    textTone: 'text-blue-600'
  },
  {
    label: 'Ticket Completion',
    value: superAdminProjectDelivery.value.completedTickets,
    max: Math.max(1, state.tickets.length),
    text: `${superAdminProjectDelivery.value.completedTickets}/${state.tickets.length} done`,
    shortLabel: 'Tickets',
    tone: 'bg-violet-500',
    softTone: 'bg-violet-500/10',
    textTone: 'text-violet-600'
  },
  {
    label: 'Approval Load',
    value: superAdminPendingApprovals.value.length,
    max: Math.max(1, superAdminPendingApprovals.value.length + 5),
    text: `${superAdminPendingApprovals.value.length} pending`,
    shortLabel: 'Approvals',
    tone: 'bg-rose-500',
    softTone: 'bg-rose-500/10',
    textTone: 'text-rose-600'
  }
])
function dateKeyFromDate(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
const superAdminWeeklyTrend = computed(() => {
  const today = new Date()
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(today)
    date.setDate(today.getDate() - (6 - index))
    return { date, key: dateKeyFromDate(date) }
  })
  const wanted = new Set(days.map(day => day.key))

  // Index each source ONCE by date key. The previous version re-scanned every
  // attendance row, ticket, time log, payment, leave and claim for all seven
  // days — roughly 70 full array passes on every recompute, which is what made
  // the panel and its modals feel sluggish.
  const attendanceByDay = new Map()
  for (const record of state.attendance) {
    if (wanted.has(record.date)) attendanceByDay.set(record.date, (attendanceByDay.get(record.date) || 0) + 1)
  }

  const logsByDay = new Map()
  for (const ticket of state.tickets) {
    for (const log of ticket.timeLogs || []) {
      const key = (log.end || log.start || '').slice(0, 10)
      if (wanted.has(key)) logsByDay.set(key, (logsByDay.get(key) || 0) + 1)
    }
  }

  const paymentsByDay = new Map()
  for (const module of financeModules.value) {
    for (const payment of module.payments || []) {
      if (wanted.has(payment.date)) {
        paymentsByDay.set(payment.date, (paymentsByDay.get(payment.date) || 0) + Number(payment.amount || 0))
      }
    }
  }

  const approvalsByDay = new Map()
  const bump = key => {
    if (wanted.has(key)) approvalsByDay.set(key, (approvalsByDay.get(key) || 0) + 1)
  }
  for (const request of state.leaveRequests) {
    bump(request.from)
    // A request spanning one day would otherwise be counted twice.
    if (request.to !== request.from) bump(request.to)
  }
  for (const claim of state.medicalClaims) bump(claim.submittedAt)

  return days.map(({ date, key }) => {
    return {
      key,
      label: formatCompanyDate(date, { weekday: 'short' }),
      date: formatCompanyDate(date, { month: 'short', day: 'numeric' }),
      attendanceCount: attendanceByDay.get(key) || 0,
      loggedTickets: logsByDay.get(key) || 0,
      payments: paymentsByDay.get(key) || 0,
      approvals: approvalsByDay.get(key) || 0
    }
  })
})
const superAdminWeeklyTrendMax = computed(() =>
  Math.max(
    1,
    ...superAdminWeeklyTrend.value.flatMap(day => [
      day.attendanceCount,
      day.loggedTickets,
      day.approvals,
      Math.ceil(day.payments / 100000)
    ])
  )
)

function realModuleById(moduleId) {
  return state.projects.flatMap(project => project.modules || []).find(module => module.id === moduleId)
}

function closeSuperAdminDetail() {
  superAdminDetail.value = null
}

function openSuperAdminDetail(type, payload = null) {
  const base = {
    approvals: {
      title: 'Pending Approvals',
      subtitle: 'Leaves, claims, payroll, and commissions waiting for Super Admin action.',
      rows: superAdminPendingApprovals.value
    },
    pendingBalances: {
      title: 'Pending Client Balances',
      subtitle: 'Modules where the client budget has not been fully received.',
      rows: superAdminPendingClientBalances.value.map(module => ({
        id: module.id,
        title: module.name,
        detail: `${module.categoryName} - ${employeeName(module.managerId)}`,
        amount: formatCurrency(projectPending(module)),
        status: 'pending'
      }))
    },
    commissionActions: {
      title: 'Commission Actions',
      subtitle: 'Manager commissions that still need approval or payment.',
      rows: [...superAdminPendingCommissions.value, ...superAdminApprovedCommissions.value].map(module => ({
        id: module.id,
        title: module.name,
        detail: employeeName(module.managerId),
        amount: formatCurrency(projectCommissionOwed(module) || projectCommissionEarned(module)),
        status: module.commission?.status || 'pending',
        sourceType: 'commission',
        source: module
      }))
    },
    // Attendance exceptions. These three previously assigned to
    // `activeAttendanceException`, which lives in HrDashboard — here it was an
    // undeclared variable, so the boxes silently did nothing when clicked.
    absent: {
      title: 'Absent Today',
      subtitle: 'Active employees with no check-in recorded today.',
      rows: hrAttendanceExceptions.value.absent.map(employee => ({
        id: `absent-${employee.id}`,
        title: employee.name,
        detail: employee.department || 'No department',
        status: 'absent'
      }))
    },
    late: {
      title: 'Late Check-ins',
      subtitle: 'Employees who checked in after the grace period.',
      rows: hrAttendanceExceptions.value.late.map(record => ({
        id: `late-${record.id}`,
        title: employeeName(record.employeeId),
        detail: `Checked in ${record.checkIn || '-'}`,
        status: 'late'
      }))
    },
    missingCheckout: {
      title: 'Missing Checkout',
      subtitle: 'Checked in today but no checkout recorded.',
      rows: hrAttendanceExceptions.value.missingCheckout.map(record => ({
        id: `missing-${record.id}`,
        title: employeeName(record.employeeId),
        detail: `Checked in ${record.checkIn || '-'}`,
        status: 'missing checkout'
      }))
    },
    blockedUsers: {
      title: 'Blocked Users',
      subtitle: 'Profiles currently blocked from accessing the app.',
      rows: state.employees
        .filter(employee => employee.status === 'blocked')
        .map(employee => ({
          id: employee.id,
          title: employee.name,
          detail: `${employee.email} - ${employee.role}`,
          status: employee.status
        }))
    },
    onboarding: {
      title: 'Onboarding Queue',
      subtitle: 'Invited employees and submitted onboarding profiles.',
      rows: hrOnboardingQueue.value.map(employee => ({
        id: employee.id,
        title: employee.name,
        detail: employee.email,
        status: employee.status,
        sourceType: 'onboarding',
        source: employee
      }))
    },
    missingDocs: {
      title: 'Missing Documents',
      subtitle: 'Employee profiles with incomplete onboarding documents.',
      rows: hrMissingDocuments.value.map(item => ({
        id: item.employee.id,
        title: item.employee.name,
        detail: item.missing.join(', '),
        status: item.employee.onboarding?.documents?.verificationStatus || 'needs review',
        sourceType: 'documents',
        source: item
      }))
    },
    delivery: {
      title: 'Module Delivery',
      subtitle: 'Active and delivered modules with open and completed ticket counts.',
      rows: superAdminDeliveryRows.value.map(module => ({
        id: module.id,
        title: module.name,
        detail: `${module.categoryName} - ${employeeName(module.managerId)}`,
        amount: `${module.completionPercent}% complete - ${module.openTickets} open - ${module.overdueTickets} overdue`,
        status: ['delivered', 'completed'].includes(module.status) ? 'delivered' : 'active',
        sourceType: 'delivery',
        source: module
      }))
    },
    audit: {
      title: 'Audit Trail',
      subtitle: 'Recent approvals, finance, payroll, and profile activity.',
      rows: superAdminAuditRows.value.map(log => ({
        id: log.id,
        title: log.action,
        detail: `${log.actorName} - ${log.module}`,
        amount: log.details,
        status: log.actorRole
      }))
    },
    trends: {
      title: 'Executive Trends',
      subtitle: 'Compact indicators used on the Super Admin dashboard.',
      rows: superAdminTrends.value.map(trend => ({
        id: trend.label,
        title: trend.label,
        detail: trend.text,
        amount: `${Math.min(100, Math.round((trend.value / trend.max) * 100))}%`,
        status: 'tracked'
      }))
    }
  }

  if (type === 'deliveryItem' && payload) {
    superAdminDetail.value = {
      type,
      title: payload.name,
      subtitle: `${payload.categoryName} - ${employeeName(payload.managerId)}`,
      rows: [
        { id: 'status', title: 'Delivery status', detail: ['delivered', 'completed'].includes(payload.status) ? 'Delivered' : 'Active' },
        { id: 'progress', title: 'Ticket completion', detail: `${payload.completionPercent || 0}%` },
        { id: 'open', title: 'Open tickets', detail: String(payload.openTickets) },
        { id: 'completed', title: 'Completed tickets', detail: String(payload.completedTickets) },
        { id: 'overdue', title: 'Overdue tickets', detail: String(payload.overdueTickets || 0) },
        { id: 'budget', title: 'Budget', detail: formatCurrency(payload.budget || 0) },
        { id: 'received', title: 'Received', detail: formatCurrency(projectReceived(payload)) },
        { id: 'pending', title: 'Pending', detail: formatCurrency(projectPending(payload)) }
      ]
    }
    return
  }

  superAdminDetail.value = { type, ...(base[type] || base.approvals) }
}

function updateStoredModule(module) {
  const project = state.projects.find(item => item.id === module?.projectId)
  const index = project?.modules?.findIndex(item => item.id === module.id) ?? -1
  if (index >= 0) project.modules[index] = { ...project.modules[index], ...module }
}

function isSuperAdminDecisionPending(item) {
  return superAdminDecisionKey.value === item?.id
}

/**
 * What the primary button does for a row, in words that say so.
 *
 * Onboarding, document and delivery rows used to show a generic Approve and
 * Reject wired to nothing: the click said "Saving…" and then silently did
 * nothing, while the Super Admin believed they had verified documents or
 * confirmed a delivery. Each now names, and performs, its real action.
 */
function approveActionLabel(row) {
  if (row.sourceType === 'payrollRun') return 'Release'
  if (row.sourceType === 'onboarding') return row.source?.status === 'invited' ? 'Resend invite' : 'Mark verified'
  if (row.sourceType === 'documents') return 'Mark verified'
  if (row.sourceType === 'delivery') return row.status === 'delivered' ? 'Reopen' : 'Mark delivered'
  return 'Approve'
}

function rejectActionLabel(row) {
  return ['onboarding', 'documents'].includes(row.sourceType) ? 'Request correction' : 'Reject'
}

/** Rows with a second, negative action. An invitee has nothing to correct yet. */
function canRejectApproval(row) {
  if (row.sourceType === 'onboarding') return row.source?.status !== 'invited'
  return ['leave', 'claim', 'documents'].includes(row.sourceType)
}

/** The employee an onboarding or documents row is about. */
function reviewedEmployee(item) {
  return item.sourceType === 'documents' ? item.source.employee : item.source
}

/** Redraws the open dialog so a row that is settled drops out of it. */
function refreshOpenDetail() {
  const type = superAdminDetail.value?.type
  if (['approvals', 'onboarding', 'missingDocs', 'delivery'].includes(type)) openSuperAdminDetail(type)
}

async function approveSuperAdminApproval(item) {
  if (!item?.source || isSuperAdminDecisionPending(item)) return

  superAdminActionMessage.value = ''
  superAdminActionError.value = ''
  superAdminDecisionKey.value = item.id
  try {
    if (item.sourceType === 'leave') {
      upsertLeaveRequest(await leaveService.decide(item.source.id, 'approved'))
      superAdminActionMessage.value = `Leave approved for ${employeeName(item.source.employeeId)}.`
    } else if (item.sourceType === 'claim') {
      upsertMedicalClaim(await medicalClaimService.decide(item.source.id, 'approved'))
      superAdminActionMessage.value = `Medical claim approved for ${employeeName(item.source.employeeId)}.`
    } else if (item.sourceType === 'commission') {
      updateStoredModule(await projectService.decideModuleCommission(item.source.id, 'approved'))
      superAdminActionMessage.value = `Commission approved for payment for ${item.source.name}.`
    } else if (item.sourceType === 'payrollRun') {
      const updated = await payrollService.releaseRun(item.source.id)
      superAdminPayrollRuns.value = superAdminPayrollRuns.value.map(run => run.id === updated.id ? updated : run)
      superAdminActionMessage.value = `Payroll for ${formatDate(updated.period)} released for payment.`
    } else if (item.sourceType === 'onboarding' && item.source.status === 'invited') {
      const response = await employeeService.resendInvitation(item.source.id)
      superAdminActionMessage.value = response?.message || `A fresh invitation was sent to ${item.source.email}.`
    } else if (item.sourceType === 'onboarding' || item.sourceType === 'documents') {
      const employee = reviewedEmployee(item)
      const response = await employeeService.reviewDocuments(employee.id, { action: 'verified', note: null })
      Object.assign(employee, response.employee)
      superAdminActionMessage.value = `Onboarding documents verified for ${employee.name}.`
    } else if (item.sourceType === 'delivery') {
      const delivered = item.status !== 'delivered'
      updateStoredModule(await projectService.setModuleDelivered(item.source.id, delivered))
      superAdminActionMessage.value = delivered
        ? `${item.source.name} marked delivered.`
        : `${item.source.name} reopened for work.`
    }
    refreshOpenDetail()
  } catch (error) {
    superAdminActionError.value = error?.message || 'Unable to save this approval.'
  } finally {
    superAdminDecisionKey.value = ''
  }
}

async function rejectSuperAdminApproval(item) {
  if (!item?.source || isSuperAdminDecisionPending(item)) return

  superAdminActionMessage.value = ''
  superAdminActionError.value = ''
  superAdminDecisionKey.value = item.id
  try {
    if (item.sourceType === 'leave') {
      upsertLeaveRequest(await leaveService.decide(item.source.id, 'rejected'))
      superAdminActionMessage.value = `Leave rejected for ${employeeName(item.source.employeeId)}.`
    } else if (item.sourceType === 'claim') {
      upsertMedicalClaim(await medicalClaimService.decide(item.source.id, 'rejected'))
      superAdminActionMessage.value = `Medical claim rejected for ${employeeName(item.source.employeeId)}.`
    } else if (item.sourceType === 'onboarding' || item.sourceType === 'documents') {
      const employee = reviewedEmployee(item)
      const missing = item.sourceType === 'documents' ? item.source.missing || [] : []
      const note = missing.length
        ? `Please provide or correct: ${missing.join(', ')}.`
        : 'Please review and resubmit your onboarding documents.'
      const response = await employeeService.reviewDocuments(employee.id, { action: 'correction_requested', note })
      Object.assign(employee, response.employee)
      superAdminActionMessage.value = `Correction requested from ${employee.name}.`
    }
    refreshOpenDetail()
  } catch (error) {
    superAdminActionError.value = error?.message || 'Unable to save this rejection.'
  } finally {
    superAdminDecisionKey.value = ''
  }
}

async function paySuperAdminCommission(item) {
  if (item?.sourceType !== 'commission' || isSuperAdminDecisionPending(item)) return

  superAdminActionMessage.value = ''
  superAdminActionError.value = ''
  superAdminDecisionKey.value = item.id
  try {
    updateStoredModule(await projectService.decideModuleCommission(item.source.id, 'paid'))
    superAdminActionMessage.value = `Commission marked paid for ${item.source.name}.`
    if (superAdminDetail.value?.type === 'approvals') openSuperAdminDetail('approvals')
    if (superAdminDetail.value?.type === 'commissionActions') openSuperAdminDetail('commissionActions')
  } catch (error) {
    superAdminActionError.value = error?.message || 'Unable to mark this commission as paid.'
  } finally {
    superAdminDecisionKey.value = ''
  }
}
</script>

<template>
  <div class="orbit-dashboard super-admin-dashboard">
    <div class="space-y-5">
      <div class="space-y-5">
      <!-- Position at a glance: in, out, net, and what needs the admin.
           Previously this row was 4 revenue cards, which read as if client
           budgets were the whole financial picture. -->
      <div class="super-admin-kpis grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div role="button" tabindex="0" @click="goToRoute({ name: 'finance' })" @keydown.enter="goToRoute({ name: 'finance' })" @keydown.space.prevent="goToRoute({ name: 'finance' })" class="super-admin-kpi dashboard-click-card orbit-kpi kpi-tile">
          <div class="super-admin-kpi-label">
            <span class="super-admin-kpi-icon super-admin-kpi-icon-positive">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 18v2h14v-2"/></svg>
            </span>
            <div>Money in</div>
          </div>
          <div class="kpi-value text-emerald-600">{{ formatCurrency(moneyIn.received) }}</div>
          <div class="super-admin-kpi-context">
            {{ formatCurrency(moneyIn.outstanding) }} still outstanding
          </div>
        </div>

        <div role="button" tabindex="0" @click="goToRoute({ name: 'petty-cash' })" @keydown.enter="goToRoute({ name: 'petty-cash' })" @keydown.space.prevent="goToRoute({ name: 'petty-cash' })" class="super-admin-kpi dashboard-click-card orbit-kpi kpi-tile">
          <div class="super-admin-kpi-label">
            <span class="super-admin-kpi-icon super-admin-kpi-icon-neutral">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21V9"/><path d="m17 14-5-5-5 5"/><path d="M5 6V4h14v2"/></svg>
            </span>
            <div>Money out</div>
          </div>
          <div class="kpi-value text-slate-950">{{ formatCurrency(moneyOut.total) }}</div>
          <div class="super-admin-kpi-context">
            Paid payroll, committed costs, petty cash
          </div>
        </div>

        <div class="super-admin-kpi orbit-kpi kpi-tile">
          <div class="super-admin-kpi-label">
            <span class="super-admin-kpi-icon" :class="netPosition >= 0 ? 'super-admin-kpi-icon-positive' : 'super-admin-kpi-icon-problem'">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16"/><path d="M12 5v15"/><path d="m6 9 3 5 3-5"/><path d="m12 9 3 5 3-5"/></svg>
            </span>
            <div>Net position</div>
          </div>
          <div class="kpi-value" :class="netPosition >= 0 ? 'text-slate-900' : 'text-rose-600'">
            {{ formatCurrency(netPosition) }}
          </div>
          <div class="super-admin-kpi-context">
            Received minus all outgoings
          </div>
        </div>

        <div role="button" tabindex="0" @click="openSuperAdminDetail('approvals')" @keydown.enter="openSuperAdminDetail('approvals')" @keydown.space.prevent="openSuperAdminDetail('approvals')" class="super-admin-kpi super-admin-kpi-attention dashboard-click-card orbit-kpi kpi-tile">
          <div class="super-admin-kpi-label">
            <span class="super-admin-kpi-icon super-admin-kpi-icon-warning">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0"/></svg>
            </span>
            <div>Needs your attention</div>
          </div>
          <div class="kpi-value text-amber-700">{{ superAdminPendingApprovals.length + pettyCashPendingExpenses().length + pettyCashPendingTopUps().length }}</div>
          <div class="super-admin-kpi-context">
            Approvals waiting
          </div>
        </div>
      </div>

      <!-- Money in / Money out, side by side. Commission sits on the OUT side:
           it is owed to a manager, not earned from a client. -->
      <section class="finance-overview bg-white rounded-xl border border-slate-200 shadow-sm p-3">
        <div class="finance-overview-heading">Finance overview</div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-3">
        <section class="super-admin-card bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="super-admin-card-header px-4 py-3 border-b border-slate-200 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
            <div class="text-sm font-bold text-slate-900">Money In — client revenue</div>
          </div>
          <span class="finance-panel-icon finance-panel-icon-positive" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16v12H4z"/><path d="M8 7V5h8v2"/><path d="M8 14h8"/><circle cx="16" cy="11" r="1"/></svg>
          </span>
          <div class="super-admin-finance-list divide-y divide-slate-200">
            <div class="flex items-center justify-between px-4 py-3">
              <div class="text-sm text-slate-700">Total budgets</div>
              <div class="text-sm font-bold text-slate-900">{{ formatCurrency(moneyIn.budget) }}</div>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <div class="text-sm text-slate-700">Received</div>
              <div class="text-sm font-bold text-emerald-600">{{ formatCurrency(moneyIn.received) }}</div>
            </div>
            <div class="flex items-center justify-between px-4 py-3">
              <div class="text-sm text-slate-700">Outstanding</div>
              <div class="text-sm font-bold text-orange-600">{{ formatCurrency(moneyIn.outstanding) }}</div>
            </div>
          </div>
        </section>

        <section class="super-admin-card bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
          <div class="super-admin-card-header px-4 py-3 border-b border-slate-200 flex items-center gap-2">
            <span class="h-2 w-2 rounded-full bg-rose-500"></span>
            <div class="text-sm font-bold text-slate-900">Money Out — company costs</div>
          </div>
          <span class="finance-panel-icon finance-panel-icon-negative" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10v18l-3-2-2 2-2-2-3 2z"/><path d="M10 8h4M10 12h4M10 16h4"/></svg>
          </span>
          <div class="super-admin-finance-list divide-y divide-slate-200">
            <button
              v-for="row in expenseBreakdown"
              :key="row.key"
              @click="goToRoute(row.route)"
              class="super-admin-expense-row w-full flex items-center justify-between px-4 py-3 transition-colors text-left"
            >
              <div class="text-sm text-slate-700">{{ row.label }}</div>
              <div class="text-sm font-bold text-slate-900">{{ formatCurrency(row.amount) }}</div>
            </button>
          </div>
        </section>
        </div>
      </section>

      <!-- Petty cash strip: HR runs it, but the super admin owns the variance. -->
      <section
        role="button"
        tabindex="0"
        @click="goToRoute({ name: 'petty-cash' })"
        @keydown.enter="goToRoute({ name: 'petty-cash' })"
        @keydown.space.prevent="goToRoute({ name: 'petty-cash' })"
        class="super-admin-petty-cash dashboard-click-card bg-white rounded-xl p-4 border shadow-sm flex flex-wrap items-center justify-between gap-4"
        :class="pettyCashIsLow() ? 'border-amber-300' : 'border-slate-200'"
      >
        <div class="petty-cash-primary">
        <span class="petty-cash-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7h18v12H3z"/><path d="M7 7V5h10v2"/><path d="M7 12h10"/><circle cx="16" cy="16" r="1.5"/></svg>
        </span>
        <div>
          <div class="text-sm font-bold text-slate-900">Petty cash in hand</div>
          <div class="text-xs text-slate-600 mt-1">
            <span v-if="pettyCashIsLow()" class="font-semibold text-amber-700">Running low — a top-up is needed</span>
            <span v-else>Office float held by HR</span>
          </div>
        </div>
        </div>
        <div class="flex flex-wrap items-center gap-5">
          <div class="text-right">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Balance</div>
            <div class="text-xl font-extrabold" :class="pettyCashIsLow() ? 'text-amber-700' : 'text-slate-900'">{{ formatCurrency(pettyCashBalance()) }}</div>
          </div>
          <div class="text-right">
            <div class="text-[11px] font-bold uppercase tracking-wide text-slate-500">Awaiting you</div>
            <div class="text-xl font-extrabold text-slate-900">{{ pettyCashPendingExpenses().length + pettyCashPendingTopUps().length }}</div>
          </div>
          <button type="button" class="super-admin-petty-action" @click.stop="goToRoute({ name: 'petty-cash' })">
            {{ pettyCashIsLow() ? 'Top up' : 'View cash' }}
          </button>
        </div>
      </section>

      <div v-if="superAdminActionMessage" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
        {{ superAdminActionMessage }}
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_420px] gap-4 items-stretch">
        <div class="space-y-4 min-w-0">
          <section class="super-admin-panel project-finance-panel orbit-card p-5">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <!-- Renamed: this covers client projects only. Calling it
                   "Company Finance" implied it was the whole picture. -->
              <h2 class="text-lg font-bold text-slate-900">Project Finance Detail</h2>
              <div class="text-xs text-slate-600 mt-1">Per-module budgets, client payments, and manager commissions. Company-wide totals are above.</div>
            </div>
            <RouterLink to="/finance" class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold">Finance</RouterLink>
          </div>
          <div class="project-finance-stats grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="project-finance-stat orbit-finance-stat orbit-finance-stat-earned p-4">
              <span class="project-finance-stat-icon project-finance-stat-icon-earned" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 3-4 3 2 5-6"/></svg>
              </span>
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Commission Earned</div>
              <div class="text-xl font-extrabold text-slate-900 mt-2">{{ formatCurrency(superAdminFinanceTotals.commissionEarned) }}</div>
              <svg class="project-finance-sparkline project-finance-sparkline-earned" viewBox="0 0 160 28" preserveAspectRatio="none" aria-hidden="true"><path d="M2 23 20 16 36 21 55 11 73 19 91 8 111 18 131 10 158 17"/></svg>
            </div>
            <div class="project-finance-stat orbit-finance-stat orbit-finance-stat-paid p-4">
              <span class="project-finance-stat-icon project-finance-stat-icon-paid" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8h14v11H5z"/><path d="M8 8V5h8v3"/><path d="m9 14 2 2 4-4"/></svg>
              </span>
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Commission Paid</div>
              <div class="text-xl font-extrabold text-emerald-600 mt-2">{{ formatCurrency(superAdminFinanceTotals.commissionPaid) }}</div>
              <svg class="project-finance-sparkline project-finance-sparkline-paid" viewBox="0 0 160 28" preserveAspectRatio="none" aria-hidden="true"><path d="M2 20 18 9 34 19 52 12 67 16 85 7 103 21 121 12 140 15 158 8"/></svg>
            </div>
            <div class="project-finance-stat orbit-finance-stat orbit-finance-stat-payroll p-4">
              <span class="project-finance-stat-icon project-finance-stat-icon-payroll" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3h10v18l-3-2-2 2-2-2-3 2z"/><path d="M10 8h4M10 12h4M10 16h4"/></svg>
              </span>
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Payroll Due</div>
              <div class="text-xl font-extrabold text-orange-600 mt-2">{{ superAdminPayrollDue.length }}</div>
              <svg class="project-finance-sparkline project-finance-sparkline-payroll" viewBox="0 0 160 28" preserveAspectRatio="none" aria-hidden="true"><path d="M2 21 21 14 39 20 58 12 76 18 96 7 114 16 133 9 158 14"/></svg>
            </div>
          </div>
          </section>

          <section class="super-admin-panel super-admin-attendance-panel orbit-card p-5">
            <div class="flex items-center gap-3 mb-4">
              <div>
                <h2 class="text-lg font-bold text-slate-900">Today's Attendance</h2>
                <div class="text-xs text-slate-600 mt-1">Company check-ins and working status for today.</div>
              </div>
            </div>
            <PunchLedger :rows="ledgerRows" header-filters />
          </section>
        </div>

        <section class="super-admin-panel approvals-panel orbit-card h-full p-5 flex flex-col">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Approvals Center</h2>
              <div class="text-xs text-slate-600 mt-1">Company-wide approvals waiting for Super Admin.</div>
            </div>
            <button type="button" @click="openSuperAdminDetail('approvals')" class="approvals-view-all orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold">
              <span>View all</span>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
            </button>
          </div>
          <div v-if="superAdminPendingApprovals.length" class="space-y-3 max-h-[430px] overflow-y-auto pr-1">
            <div v-if="superAdminActionError" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
              {{ superAdminActionError }}
            </div>
            <div v-for="item in superAdminPendingApprovals" :key="item.id" class="orbit-approval-item p-4">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ item.title }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ item.type }} - {{ item.detail }}</div>
                </div>
                <span class="company-hub-badge capitalize" :class="statusBadgeClass(item.status)">{{ item.status }}</span>
              </div>
              <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
                <RouterLink :to="item.route" class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold">Open page</RouterLink>
                <button
                  v-if="!['commission', 'payrollRun'].includes(item.sourceType)"
                  type="button"
                  @click="rejectSuperAdminApproval(item)"
                  :disabled="isSuperAdminDecisionPending(item)"
                  class="orbit-button orbit-button-secondary px-3 py-2 text-xs font-bold"
                >
                  {{ isSuperAdminDecisionPending(item) ? 'Saving…' : 'Reject' }}
                </button>
                <button
                  v-if="item.sourceType === 'commission' && item.status === 'approved'"
                  type="button"
                  @click="paySuperAdminCommission(item)"
                  :disabled="isSuperAdminDecisionPending(item)"
                  class="orbit-button orbit-button-primary px-3 py-2 text-xs font-bold"
                >
                  {{ isSuperAdminDecisionPending(item) ? 'Saving…' : 'Mark paid' }}
                </button>
                <button
                  v-else
                  type="button"
                  @click="approveSuperAdminApproval(item)"
                  :disabled="isSuperAdminDecisionPending(item)"
                  class="orbit-button orbit-button-primary px-3 py-2 text-xs font-bold"
                >
                  {{ isSuperAdminDecisionPending(item) ? 'Saving…' : item.sourceType === 'payrollRun' ? 'Release' : 'Approve' }}
                </button>
              </div>
            </div>
          </div>
          <CardEmptyState
            v-else
            class="flex-1"
            variant="review"
            title="No approvals waiting"
            description="You're all caught up!"
          />
        </section>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.65fr)_minmax(320px,0.35fr)] gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Operational Exceptions</h2>
              <div class="text-xs text-slate-600 mt-1">Attendance, access, onboarding, and document issues.</div>
            </div>
            <RouterLink to="/attendance" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Attendance</RouterLink>
          </div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <button type="button" @click="openSuperAdminDetail('absent')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Absent</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ superAdminOperationalExceptions.absent }}</div>
            </button>
            <button type="button" @click="openSuperAdminDetail('late')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Late</div>
              <div class="text-2xl font-extrabold text-orange-600 mt-1">{{ superAdminOperationalExceptions.late }}</div>
            </button>
            <button type="button" @click="openSuperAdminDetail('missingCheckout')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Missing Checkout</div>
              <div class="text-2xl font-extrabold text-rose-600 mt-1">{{ superAdminOperationalExceptions.missingCheckout }}</div>
            </button>
            <button type="button" @click="openSuperAdminDetail('blockedUsers')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Blocked Users</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ superAdminOperationalExceptions.blockedUsers }}</div>
            </button>
            <button type="button" @click="openSuperAdminDetail('onboarding')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Onboarding</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ superAdminOperationalExceptions.pendingOnboarding }}</div>
            </button>
            <button type="button" @click="openSuperAdminDetail('missingDocs')" class="text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Missing Docs</div>
              <div class="text-2xl font-extrabold text-rose-600 mt-1">{{ superAdminOperationalExceptions.missingDocuments }}</div>
            </button>
          </div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Delivery Overview</h2>
              <div class="text-xs text-slate-600 mt-1">Project/module delivery status.</div>
            </div>
            <button type="button" @click="openSuperAdminDetail('delivery')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View detail</button>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Active Modules</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ superAdminProjectDelivery.activeModules }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Delivered</div>
              <div class="text-2xl font-extrabold text-emerald-600 mt-1">{{ superAdminProjectDelivery.deliveredModules }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Open Tickets</div>
              <div class="text-2xl font-extrabold text-blue-600 mt-1">{{ superAdminProjectDelivery.openTickets }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Completed</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ superAdminProjectDelivery.completedTickets }}</div>
            </div>
          </div>
        </section>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.55fr)_minmax(0,0.45fr)] gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Admin Controls</h2>
              <div class="text-xs text-slate-600 mt-1">Role, access, employee, and system administration shortcuts.</div>
            </div>
            <RouterLink to="/access-control" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">Manage roles</RouterLink>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <RouterLink
              v-for="control in superAdminControls"
              :key="control.label"
              :to="control.route"
              class="rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white"
            >
              <div class="text-sm font-extrabold text-slate-900">{{ control.label }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ control.detail }}</div>
            </RouterLink>
          </div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Latest Audit Trail</h2>
              <div class="text-xs text-slate-600 mt-1">Recent approvals, finance, payroll, and profile activity.</div>
            </div>
            <button type="button" @click="openSuperAdminDetail('audit')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View all</button>
          </div>
          <div v-if="superAdminAuditRows.length" class="space-y-3 max-h-[280px] overflow-y-auto pr-1">
            <button type="button" v-for="log in superAdminAuditRows" :key="log.id" @click="openSuperAdminDetail('audit')" class="w-full text-left rounded-xl border border-slate-200 bg-slate-50 p-3 hover:bg-white">
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="text-sm font-extrabold text-slate-900">{{ log.action }}</div>
                  <div class="text-xs text-slate-600 mt-1">{{ log.actorName }} - {{ log.module }}</div>
                </div>
                <span class="company-hub-badge company-hub-badge-muted">{{ log.actorRole }}</span>
              </div>
              <div class="text-xs text-slate-500 mt-2">{{ log.details }}</div>
            </button>
          </div>
          <CardEmptyState
            v-else
            compact
            variant="review"
            title="No audit activity yet"
            description="New approvals and system changes will appear here."
          />
        </section>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,0.6fr)_minmax(0,0.4fr)] gap-4">
        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Module Delivery Detail</h2>
              <div class="text-xs text-slate-600 mt-1">Delivery health by module: progress, blockers, owner, and payment coverage.</div>
            </div>
            <button type="button" @click="openSuperAdminDetail('delivery')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View all</button>
          </div>
          <div class="space-y-3 max-h-[320px] overflow-y-auto pr-1">
            <button type="button" v-for="module in superAdminDeliveryRows" :key="module.id" @click="openSuperAdminDetail('deliveryItem', module)" class="w-full text-left rounded-xl border border-slate-200 bg-slate-50 p-4 hover:bg-white">
              <div class="flex flex-col gap-4">
                <div class="flex items-start justify-between gap-3">
                  <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <div class="text-sm font-extrabold text-slate-900 truncate">{{ module.name }}</div>
                      <span v-if="module.overdueTickets" class="company-hub-badge company-hub-badge-warning">{{ module.overdueTickets }} overdue</span>
                    </div>
                    <div class="text-xs text-slate-600 mt-1">{{ module.categoryName }} - {{ employeeName(module.managerId) }}</div>
                  </div>
                  <span class="company-hub-badge capitalize" :class="['delivered', 'completed'].includes(module.status) ? 'company-hub-badge-success' : 'company-hub-badge-muted'">
                    {{ ['delivered', 'completed'].includes(module.status) ? 'delivered' : 'active' }}
                  </span>
                </div>

                <div>
                  <div class="flex items-center justify-between text-[11px] font-bold text-slate-500 mb-2">
                    <span>Ticket completion</span>
                    <span>{{ module.completionPercent }}%</span>
                  </div>
                  <div class="h-2 rounded-full bg-slate-200 overflow-hidden">
                    <div class="h-full rounded-full bg-slate-950" :style="{ width: `${module.completionPercent}%` }"></div>
                  </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
                  <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Tickets</div>
                    <div class="text-sm font-extrabold text-slate-900 mt-1">{{ module.completedTickets }}/{{ module.totalTickets }}</div>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Open</div>
                    <div class="text-sm font-extrabold text-blue-600 mt-1">{{ module.openTickets }}</div>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Budget</div>
                    <div class="text-sm font-extrabold text-slate-900 mt-1">{{ formatCurrency(module.budget || 0) }}</div>
                  </div>
                  <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <div class="text-[10px] font-bold uppercase tracking-wide text-slate-500">Paid</div>
                    <div class="text-sm font-extrabold text-emerald-600 mt-1">{{ module.moneyPercent }}%</div>
                  </div>
                </div>
              </div>
            </button>
          </div>
        </section>

        <section class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
          <div class="flex items-center justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Trends</h2>
              <div class="text-xs text-slate-600 mt-1">Executive health across finance, people, delivery, and approvals.</div>
            </div>
            <button type="button" @click="openSuperAdminDetail('trends')" class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-slate-50">View</button>
          </div>
          <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between gap-3 mb-4">
              <div>
                <div class="text-sm font-extrabold text-slate-900">Last 7 days</div>
                <div class="text-xs text-slate-500 mt-1">Daily activity, attendance, approvals, and payment movement.</div>
              </div>
              <span class="company-hub-badge company-hub-badge-info">Daily</span>
            </div>
            <div class="grid grid-cols-[36px_repeat(7,minmax(0,1fr))] gap-2 items-end h-56">
              <div class="h-full flex flex-col justify-between text-[10px] font-bold text-slate-400 py-1">
                <span>{{ superAdminWeeklyTrendMax }}</span>
                <span>{{ Math.ceil(superAdminWeeklyTrendMax * 0.75) }}</span>
                <span>{{ Math.ceil(superAdminWeeklyTrendMax * 0.5) }}</span>
                <span>{{ Math.ceil(superAdminWeeklyTrendMax * 0.25) }}</span>
                <span>0</span>
              </div>
              <div
                v-for="day in superAdminWeeklyTrend"
                :key="day.key"
                class="h-full min-w-0 flex flex-col justify-end gap-2"
              >
                <div class="relative h-36 rounded-lg border border-slate-200 bg-white overflow-hidden px-2 pt-4 pb-2">
                  <div class="absolute inset-x-0 top-1/4 border-t border-dashed border-slate-200"></div>
                  <div class="absolute inset-x-0 top-1/2 border-t border-dashed border-slate-200"></div>
                  <div class="absolute inset-x-0 top-3/4 border-t border-dashed border-slate-200"></div>
                  <div class="relative z-10 h-full flex items-end justify-center gap-1.5">
                    <span class="w-2 rounded-t-full bg-emerald-500" :style="{ height: `${Math.max(5, (day.attendanceCount / superAdminWeeklyTrendMax) * 100)}%` }" :title="`${day.attendanceCount} attendance records`"></span>
                    <span class="w-2 rounded-t-full bg-blue-500" :style="{ height: `${Math.max(5, (day.loggedTickets / superAdminWeeklyTrendMax) * 100)}%` }" :title="`${day.loggedTickets} ticket logs`"></span>
                    <span class="w-2 rounded-t-full bg-violet-500" :style="{ height: `${Math.max(5, (day.approvals / superAdminWeeklyTrendMax) * 100)}%` }" :title="`${day.approvals} approval items`"></span>
                    <span class="w-2 rounded-t-full bg-amber-500" :style="{ height: `${Math.max(5, (Math.ceil(day.payments / 100000) / superAdminWeeklyTrendMax) * 100)}%` }" :title="`${formatCurrency(day.payments)} received`"></span>
                  </div>
                </div>
                <div class="min-h-[54px]">
                  <div class="truncate text-xs font-extrabold text-slate-900">{{ day.label }}</div>
                  <div class="truncate text-[11px] font-semibold text-slate-500 mt-1">{{ day.date }}</div>
                </div>
              </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-2">
              <div class="flex items-center gap-2 min-w-0"><span class="h-2.5 w-2.5 rounded-full shrink-0 bg-emerald-500"></span><span class="truncate text-[11px] font-bold text-slate-600">Attendance records</span></div>
              <div class="flex items-center gap-2 min-w-0"><span class="h-2.5 w-2.5 rounded-full shrink-0 bg-blue-500"></span><span class="truncate text-[11px] font-bold text-slate-600">Ticket logs</span></div>
              <div class="flex items-center gap-2 min-w-0"><span class="h-2.5 w-2.5 rounded-full shrink-0 bg-violet-500"></span><span class="truncate text-[11px] font-bold text-slate-600">Approval items</span></div>
              <div class="flex items-center gap-2 min-w-0"><span class="h-2.5 w-2.5 rounded-full shrink-0 bg-amber-500"></span><span class="truncate text-[11px] font-bold text-slate-600">Payments per 100k</span></div>
            </div>
          </div>
        </section>
      </div>

      <div v-if="false">
      <!-- Header -->
      <div class="mb-8">
        <!-- <h1 class="text-3xl font-bold text-slate-900">System Overview ðŸ¢</h1>
        <p class="text-slate-600 mt-1">Organization-wide metrics and insights</p> -->
      </div>

      <!-- Key Metrics -->
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-8">
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Revenue</div>
          <div class="text-2xl font-bold text-slate-900 leading-none">{{ formatCurrency(totalRevenue) }}</div>
        </div>
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Total Employees</div>
          <div class="text-2xl font-bold text-slate-900 leading-none">{{ state.employees.length }}</div>
        </div>
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Active Projects</div>
          <div class="text-2xl font-bold text-emerald-600 leading-none">{{ state.projects.length }}</div>
        </div>
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Pending Invites</div>
          <div class="text-2xl font-bold text-orange-600 leading-none">{{ pendingInvites }}</div>
        </div>
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Open Tickets</div>
          <div class="text-2xl font-bold text-blue-600 leading-none">{{ openTickets }}</div>
        </div>
      </div>

      <!-- Punch Ledger -->
      <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm mb-8">
        <h2 class="text-lg font-bold text-slate-900 mb-4">Today's Attendance</h2>
        <PunchLedger :rows="ledgerRows" />
      </div>

      <!-- All Projects -->
      <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900 mb-4">All Projects</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div v-for="p in state.projects" :key="p.id" class="p-4 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
            <div class="font-semibold text-slate-900">{{ p.name }}</div>
            <div class="text-sm text-slate-600 mt-1">{{ p.description }}</div>
            <div class="flex gap-2 mt-2">
              <span class="text-xs px-2 py-1 bg-blue-100 text-blue-700 rounded">{{ state.tickets.filter(t => t.projectId === p.id).length }} tickets</span>
            </div>
          </div>
        </div>
      </div>
      </div>
    </div>
  </div>

  <!-- Detail drawer for the approval/finance drill-downs. Lives here with the
       18 triggers that open it. -->
  <Teleport to="body">
    <div v-if="superAdminDetail" class="fixed inset-0 z-[60] bg-black/40 backdrop-blur-[2px] flex items-center justify-center px-4 py-4 overflow-y-auto" @click.self="closeSuperAdminDetail">
      <div class="w-full max-w-5xl rounded-2xl bg-white shadow-2xl border border-slate-200 overflow-hidden">
        <div class="p-5 border-b border-slate-200 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
          <div>
            <div class="text-lg font-extrabold text-slate-900">{{ superAdminDetail.title }}</div>
            <div class="text-sm text-slate-600 mt-1">{{ superAdminDetail.subtitle }}</div>
          </div>
          <button @click="closeSuperAdminDetail" class="text-sm border border-slate-200 rounded-lg px-4 py-2 hover:bg-slate-50 transition-colors text-slate-700 font-medium">
            Close
          </button>
        </div>

        <div class="max-h-[70vh] overflow-y-auto">
          <div v-if="superAdminActionMessage" class="m-5 mb-0 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
            {{ superAdminActionMessage }}
          </div>
          <div v-if="superAdminActionError" class="m-5 mb-0 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
            {{ superAdminActionError }}
          </div>

          <!-- Rendered as striped rows rather than detached cards, so record
               lists in dialogs read the same as the tables elsewhere. -->
          <div class="modal-row-list">
            <div
              v-for="row in superAdminDetail.rows"
              :key="row.id"
              class="modal-row px-5 py-4"
            >
            <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
              <div>
                <div class="text-sm font-extrabold text-slate-900">{{ row.title }}</div>
                <div class="text-xs text-slate-600 mt-1">{{ row.detail }}</div>
                <div v-if="row.amount" class="text-sm font-extrabold text-slate-900 mt-2">{{ row.amount }}</div>
              </div>
              <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                <span v-if="row.status" class="company-hub-badge capitalize" :class="statusBadgeClass(row.status)">{{ row.status }}</span>
                <template v-if="superAdminDetail.type === 'approvals' || row.sourceType">
                  <button
                    v-if="row.sourceType && canRejectApproval(row)"
                    type="button"
                    @click="rejectSuperAdminApproval(row)"
                    :disabled="isSuperAdminDecisionPending(row)"
                    class="text-xs font-bold rounded-lg border border-slate-200 px-3 py-2 text-slate-700 hover:bg-white"
                  >
                    {{ isSuperAdminDecisionPending(row) ? 'Saving…' : rejectActionLabel(row) }}
                  </button>
                  <button
                    v-if="row.sourceType === 'commission' && row.status === 'approved'"
                    type="button"
                    @click="paySuperAdminCommission(row)"
                    :disabled="isSuperAdminDecisionPending(row)"
                    class="bg-emerald-600 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-emerald-700"
                  >
                    {{ isSuperAdminDecisionPending(row) ? 'Saving…' : 'Mark paid' }}
                  </button>
                  <button
                    v-else-if="row.sourceType"
                    type="button"
                    @click="approveSuperAdminApproval(row)"
                    :disabled="isSuperAdminDecisionPending(row)"
                    class="bg-slate-950 text-white text-xs font-bold rounded-lg px-3 py-2 hover:bg-slate-800"
                  >
                    {{ isSuperAdminDecisionPending(row) ? 'Saving…' : approveActionLabel(row) }}
                  </button>
                </template>
              </div>
            </div>
            </div>
          </div>

          <div v-if="superAdminDetail.rows.length === 0" class="m-5 rounded-xl border border-slate-200 bg-slate-50 p-6 text-center text-sm font-semibold text-slate-500">
            No records found.
          </div>
        </div>
      </div>
    </div>
  </Teleport>
  </div>
</template>

import { reactive, computed } from 'vue'

const currentUserStorageKey = 'orbit-current-user'

// A signed-out session. Previously this defaulted to a hardcoded manager
// ("Zara Nadeem"), which meant anyone opening the app was silently logged in —
// the router guard checked permissions but never authentication.
const guestUser = { id: null, name: '', role: 'guest', email: '', employeeId: null }

function loadStoredCurrentUser() {
  if (typeof window === 'undefined') return { ...guestUser }
  try {
    const stored = window.localStorage.getItem(currentUserStorageKey)
    if (!stored) return { ...guestUser }
    const parsed = JSON.parse(stored)
    if (!parsed?.id || !parsed?.name || !parsed?.role) return { ...guestUser }
    return {
      id: parsed.id,
      name: parsed.name,
      role: parsed.role,
      email: parsed.email || '',
      // Restored so a page refresh resubscribes to the right channel without
      // waiting for another login.
      employeeId: parsed.employeeId ?? null
    }
  } catch {
    return { ...guestUser }
  }
}

function persistCurrentUser(user) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(currentUserStorageKey, JSON.stringify(user))
}

// Single reactive store, backed by the Laravel API.
//
// Every collection below starts EMPTY and is filled by the view that needs it,
// through a service in src/services. Nothing is seeded with sample data: mock
// rows make a failed request look like a working page, and that failure is
// silent and plausible — the worst kind. If a list is empty here, either the
// fetch has not landed yet or it genuinely returned nothing.
//
// The exceptions are the modules whose APIs do not exist yet (assets,
// announcements, policies) and rolePermissions, whose seed is a deliberate
// fallback documented at its declaration.
const state = reactive({
  currentUser: loadStoredCurrentUser(),
  // All API-backed. Seeded EMPTY, not with sample rows: mock data makes a
  // failed request look like a working page, which is the worst kind of
  // failure — silent and plausible. Every view that reads these now fetches
  // them on mount.
  employees: [],
  attendance: [],
  projects: [],
  tickets: [],
  medicalClaims: [],
  leaveRequests: [],
  salarySlipRequests: [],
  // The asset register, API-backed. Seeded empty like everything else with a
  // real endpoint: sample rows would show equipment the company does not own.
  assets: [],
  // Petty cash: office running costs, distinct from Finance (client revenue).
  // Float in, expenses out, and a reconciliation that compares the book
  // balance against physically counted cash. All API-backed, so seeded empty.
  pettyCashTopUps: [],
  pettyCashExpenses: [],
  // Unread counts as the server reports them, so the badge does not depend on
  // the client having loaded every row it is counting.
  unreadCounts: { messages: 0, notifications: 0 },
  // The POLL's health: 'idle' | 'live' | 'reconnecting'. This is the one that
  // matters — the poll is what guarantees delivery, so this is what the UI
  // warns on. Surfaced so a stalled feed is visible rather than looking like
  // an absence of news.
  syncStatus: 'idle',
  // The Pusher socket's health: 'idle' | 'live' | 'polling'. Only an
  // accelerator, so 'polling' is not a problem — it just means messages take
  // up to three seconds instead of arriving instantly.
  socketStatus: 'idle',
  // Presence has two independent sources and they must not be conflated.
  // `online` is who has a browser connected, from the Pusher presence channel.
  // `chosen` is what people have declared — busy, away — which persists across
  // sessions. Someone can be online AND busy; those are different facts.
  presence: { online: {}, chosen: {} },
  // The API's view of the tin — float, spend, balance and the low flag. Null
  // until loaded, which is when the local helpers below are used instead.
  pettyCashSummary: null,
  pettyCashReconciliations: [],
  // Company Hub, API-backed. Seeded empty: a sample policy is a rule the
  // company never wrote, which in an HR system is worse than an empty page.
  announcements: [],
  policies: [],
  // Loaded from the API. Seeded empty rather than with sample conversations:
  // mock messages would render for the moment before the fetch lands, showing
  // people a conversation that does not exist and then replacing it.
  messages: [],

  // Single source of truth for role permissions. `can(module, action, resource)`
  // reads this table — editing a row in Access Control changes real behaviour.
  //
  // scope narrows *which records* a role may act on:
  //   'all'   - every record in the module
  //   'owned' - only records the user owns (project.managerId === user.id)
  //   'team'  - only records the user is a team member of
  //   'self'  - only the user's own record (own payslip, own profile)
  //
  // These rows are the SEED VALUES ONLY. After sign-in, setRolePermissions()
  // replaces them with the grid the API returns for the current user, which is
  // the authoritative copy (role_permissions in the tenant database). They are
  // kept so the app still renders if that fetch fails, and so the shape of a
  // row stays documented in one obvious place.
  rolePermissions: [
    // Employee
    { role: 'employee', module: 'Dashboard', view: true, create: false, approve: false, finance: false, scope: 'self' },
    // Employees may view projects they are on, but cannot create project
    // sections — ticket creation is the separate 'Tickets' module below.
    { role: 'employee', module: 'Projects', view: true, create: false, approve: false, finance: false, scope: 'team' },
    { role: 'employee', module: 'Tickets', view: true, create: true, approve: false, finance: false, scope: 'team' },
    { role: 'employee', module: 'Leave Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'employee', module: 'Medical Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'employee', module: 'Payroll', view: true, create: false, approve: false, finance: false, scope: 'self' },
    { role: 'employee', module: 'Messages', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'employee', module: 'Company Hub', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'employee', module: 'Profile', view: true, create: false, approve: false, finance: false, scope: 'self' },

    // Manager
    { role: 'manager', module: 'Dashboard', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Employees', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Projects', view: true, create: true, approve: true, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Tickets', view: true, create: true, approve: false, finance: false, scope: 'owned' },
    // Managers observe their team's leave but no longer approve it — the Team
    // Lead holds stage one and HR holds final sign-off.
    { role: 'manager', module: 'Team Leaves', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Leave Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'manager', module: 'Medical Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'manager', module: 'Finance', view: true, create: false, approve: false, finance: true, scope: 'owned' },
    { role: 'manager', module: 'Payroll', view: true, create: false, approve: false, finance: false, scope: 'self' },
    { role: 'manager', module: 'Reports', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Messages', view: true, create: true, approve: false, finance: false, scope: 'owned' },
    { role: 'manager', module: 'Company Hub', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'manager', module: 'Profile', view: true, create: false, approve: false, finance: false, scope: 'self' },

    // HR
    { role: 'hr', module: 'Dashboard', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Employees', view: true, create: true, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Attendance', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Leave Request', view: true, create: true, approve: true, finance: false, scope: 'all' },
    { role: 'hr', module: 'Medical Request', view: true, create: false, approve: true, finance: false, scope: 'all' },
    { role: 'hr', module: 'Payroll', view: true, create: true, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Assets', view: true, create: true, approve: false, finance: false, scope: 'all' },
    // HR runs the petty cash tin: records spend, requests top-ups, reconciles.
    // approve stays false — the person holding the cash must not sign off their
    // own replenishment or their own over-threshold spend.
    { role: 'hr', module: 'Petty Cash', view: true, create: true, approve: false, finance: true, scope: 'all' },
    { role: 'hr', module: 'Calendar', view: true, create: true, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Reports', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Messages', view: true, create: true, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Company Hub', view: true, create: true, approve: false, finance: false, scope: 'all' },
    { role: 'hr', module: 'Profile', view: true, create: false, approve: false, finance: false, scope: 'self' },

    // Team Lead — example of a custom role added as DATA ONLY, with no code
    // change anywhere. Sees its own projects/tickets like a manager, but holds
    // no finance access and cannot approve leave.
    { role: 'team_lead', module: 'Dashboard', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'team_lead', module: 'Projects', view: true, create: false, approve: true, finance: false, scope: 'owned' },
    // Leads sign off their squad's tickets — the core of the role.
    { role: 'team_lead', module: 'Tickets', view: true, create: true, approve: true, finance: false, scope: 'owned' },
    // Leads own the FIRST approval stage for their squad's leave: they know
    // sprint state and task completeness. HR still gives final sign-off.
    { role: 'team_lead', module: 'Team Leaves', view: true, create: false, approve: true, finance: false, scope: 'owned' },
    { role: 'team_lead', module: 'Leave Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'team_lead', module: 'Medical Request', view: true, create: true, approve: false, finance: false, scope: 'self' },
    { role: 'team_lead', module: 'Payroll', view: true, create: false, approve: false, finance: false, scope: 'self' },
    { role: 'team_lead', module: 'Reports', view: true, create: false, approve: false, finance: false, scope: 'owned' },
    { role: 'team_lead', module: 'Messages', view: true, create: true, approve: false, finance: false, scope: 'owned' },
    { role: 'team_lead', module: 'Company Hub', view: true, create: false, approve: false, finance: false, scope: 'all' },
    { role: 'team_lead', module: 'Profile', view: true, create: false, approve: false, finance: false, scope: 'self' },

    // Final-stage leave approval (the super admin step of the approval chain).
    // No non-super-admin role holds it today; add a row here to delegate it.

    // Super Admin — full access everywhere (see SUPER_ADMIN_EXCLUDED below).
    { role: 'super_admin', module: 'All modules', view: true, create: true, approve: true, finance: true, scope: 'all' },
    // Oversight-only modules: super admin reviews these but never files its
    // own request, and does not work tickets. create stays false by design.
    { role: 'super_admin', module: 'Leave Request', view: true, create: false, approve: true, finance: false, scope: 'all' },
    { role: 'super_admin', module: 'Medical Request', view: true, create: false, approve: true, finance: false, scope: 'all' },
    { role: 'super_admin', module: 'Tickets', view: true, create: false, approve: false, finance: false, scope: 'all' }
  ],
  // The audit trail, loaded from the API. Seeded empty: a sample entry would
  // put a fabricated action in a log whose whole value is that it is true.
  activityLogs: [],
  settings: {
    company: {
      name: 'Orbit HRMS',
      timezone: 'Asia/Karachi',
      workWeek: 'Monday - Friday',
      // Locale settings drive every currency/date format in the app. Changing
      // these here re-formats the whole UI — no component edits needed.
      locale: 'en-US',
      currency: 'PKR',
      // 'code' renders "PKR 1,200" (current look). 'symbol' renders "₨1,200".
      currencyDisplay: 'code'
    },
    attendance: {
      startTime: '09:30',
      endTime: '18:00',
      graceMinutes: 10,
      cameraCheckinRequired: true
    },
    leave: {
      annualDays: 18,
      sickDays: 10,
      casualDays: 8,
      halfDayEnabled: true,
      twoStepApproval: true
    },
    claims: {
      annualMedicalLimit: 100000,
      receiptRequiredAbove: 5000,
      approvalFlow: 'HR + Super Admin'
    },
    finance: {
      defaultCommissionType: 'percent',
      defaultCommissionValue: 8,
      superAdminApprovalRequired: true
    },
    pettyCash: {
      // Spend at or below this is recorded directly by HR; above it the entry
      // stays 'pending' until a super admin approves.
      approvalThreshold: 5000,
      // Warn when the running balance drops below this so a top-up is requested
      // before the tin runs dry.
      lowBalanceThreshold: 10000,
      categories: ['Office supplies', 'Refreshments', 'Travel', 'Courier', 'Repairs', 'Utilities', 'Miscellaneous']
    },
    // Job titles offered in the designation dropdowns. Named for what the UI
    // calls them; `employee.department` is a separate per-record field.
    designations: [
      'Junior Software Engineer',
      'Associate Software Engineer',
      'Software Engineer',
      'Senior Software Engineer',
      'QA Engineer',
      'Senior QA Engineer',
      'Project Manager',
      'Team Lead',
      'Bidder',
      'Intern',
      'UI/UX Designer',
      'HR Executive',
      'DevOps Engineer'
    ],
    stacks: ['MERN', '.NET', 'Angular', 'Laravel', 'Vue', 'QA Automation', 'UI/UX']
  },
  // Delivered by the sync poll, so seeded empty. A sample notification would
  // announce something that never happened, and clicking it would go nowhere.
  notifications: [],
  // At most one ticket timer runs per user at a time.
  activeTimer: null // { ticketId }
})

function dateKey(date = new Date()) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function todayKey() {
  return dateKey(new Date())
}

function isToday(isoString) {
  if (!isoString) return false
  return isoString.slice(0, 10) === todayKey()
}

function ticketSeconds(ticket) {
  let total = 0
  for (const log of ticket.timeLogs) {
    const start = new Date(log.start).getTime()
    const end = log.end ? new Date(log.end).getTime() : Date.now()
    total += Math.max(0, (end - start) / 1000)
  }
  return total
}

function ticketSecondsToday(ticket) {
  let total = 0
  for (const log of ticket.timeLogs) {
    if (!isToday(log.start)) continue
    const start = new Date(log.start).getTime()
    const end = log.end ? new Date(log.end).getTime() : Date.now()
    total += Math.max(0, (end - start) / 1000)
  }
  return total
}

function formatDuration(totalSeconds) {
  const s = Math.floor(totalSeconds)
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  if (h === 0) return `${m}m`
  return `${h}h ${String(m).padStart(2, '0')}m`
}

// ---------------------------------------------------------------------------
// Permissions
//
// can(module, action, resource?) is the single entry point for every access
// check in the UI. It answers two questions from state.rolePermissions:
//   1. does this role hold `action` on `module`?          (the boolean columns)
//   2. may it act on *this* record?                       (the scope column)
//
// Passing no resource asks only question 1 — use that for page-level gates
// ("can this role open Finance at all?"). Pass a resource for record-level
// gates ("can this manager approve *this* project?").
//
// NOTE: this is UX only. Every rule here must be re-enforced server-side once
// the Laravel API lands — a hidden button is not a permission.
// ---------------------------------------------------------------------------

const SUPER_ADMIN_ROW = { view: true, create: true, approve: true, finance: true, scope: 'all' }

// Super admin oversees the company rather than participating in it: it reviews
// leave/claims and never files its own, and it does not work tickets. These
// modules therefore fall through to an explicit row instead of the blanket
// super-admin grant.
const SUPER_ADMIN_EXCLUDED = ['Leave Request', 'Medical Request', 'Tickets']

function permissionRow(module, user = state.currentUser) {
  // Direct match first. Once the API grid is loaded it already contains a
  // resolved row per module for super_admin, so the fallthrough below is only
  // reached with the seeded rows (API unreachable, or before sign-in).
  const row = state.rolePermissions.find(r => r.role === user.role && r.module === module)
  if (row) return row

  if (user.role === 'super_admin' && !SUPER_ADMIN_EXCLUDED.includes(module)) {
    return SUPER_ADMIN_ROW
  }
  return null
}

// Does the user own / belong to this record? Kept in one place so the
// ownership rules stop being copy-pasted across views.
function matchesScope(scope, resource, user = state.currentUser) {
  if (scope === 'all') return true
  if (!resource) return true // page-level check, no record to test

  switch (scope) {
    case 'owned':
      // A module is owned by its Team Lead, a project by its manager.
      if (resource.leadId !== undefined && resource.leadId === user.id) return true
      if (resource.managerId !== undefined) return resource.managerId === user.id
      // Fall back to team membership for records without an explicit owner.
      return (resource.teamMembers || []).includes(user.id)
    case 'team':
      if ((resource.teamMembers || []).includes(user.id)) return true
      // Tickets belong to their assignee.
      if (resource.assigneeId !== undefined) return resource.assigneeId === user.id
      return false
    case 'self': {
      const ownerId = resource.employeeId ?? resource.userId ?? resource.id
      return ownerId === user.id
    }
    default:
      return false
  }
}

function can(module, action = 'view', resource = null, user = state.currentUser) {
  const row = permissionRow(module, user)
  if (!row) return false
  if (!row[action]) return false
  return matchesScope(row.scope, resource, user)
}

// Filters a list down to the records the user may act on. Replaces the
// hand-rolled `if (role === 'super_admin') ... else if (role === 'manager')`
// filters that were duplicated across Projects, Reports, and Finance.
function scopedList(module, list, action = 'view', user = state.currentUser) {
  const row = permissionRow(module, user)
  if (!row || !row[action]) return []
  if (row.scope === 'all') return list
  return list.filter(item => matchesScope(row.scope, item, user))
}

// ---------------------------------------------------------------------------
// Company money position
//
// The dashboard previously showed only client revenue under the heading
// "Company Finance Snapshot", which understated reality — payroll, claims and
// petty cash all leave the company but appeared nowhere. These helpers give
// both sides so a net position can be shown honestly.
//
// Commission is deliberately counted as an EXPENSE: money owed out to a
// manager, not revenue earned in.
// ---------------------------------------------------------------------------

const BASE_SALARY_BY_ROLE = {
  employee: 95000,
  manager: 180000,
  hr: 130000,
  team_lead: 150000
}

// Mirrors SalarySlips.vue defaultPayrollDetails() — kept here so the dashboard
// and the payroll page cannot drift apart.
function monthlyPayrollCost() {
  return state.employees
    .filter(employee => employee.status === 'active')
    .reduce((total, employee) => {
      const request = state.salarySlipRequests.find(item => item.employeeId === employee.id)
      const base = Number(request?.details?.baseSalary ?? employee.salary ?? BASE_SALARY_BY_ROLE[employee.role] ?? 90000)
      const details = request?.details || {}
      const allowances = Number(details.houseAllowance ?? Math.round(base * 0.12))
        + Number(details.medicalAllowance ?? Math.round(base * 0.06))
        + Number(details.bonus || 0)
      const deductions = Number(details.taxDeduction ?? Math.round(base * 0.05))
        + Number(details.attendanceDeduction ?? Math.round(base * 0.03))
        + Number(details.advanceDeduction || 0)
      return total + (base + allowances - deductions)
    }, 0)
}

// Only approved claims are a real liability; pending ones may be rejected.
function approvedClaimsCost() {
  return state.medicalClaims
    .filter(claim => claim.status === 'approved')
    .reduce((total, claim) => total + Number(claim.amount || 0), 0)
}

function allProjectModules() {
  return state.projects.flatMap(project => project.modules || [])
}

function companyMoneyIn() {
  const modules = allProjectModules()
  const budget = modules.reduce((total, module) => total + Number(module.budget || 0), 0)
  const received = modules.reduce((total, module) => total + projectReceived(module), 0)
  return { budget, received, outstanding: Math.max(0, budget - received) }
}

function companyMoneyOut() {
  const modules = allProjectModules()
  const commissions = modules.reduce((total, module) => total + projectCommissionOwed(module), 0)
  const payroll = monthlyPayrollCost()
  const claims = approvedClaimsCost()
  const petty = pettyCashTotalSpent()
  return { payroll, claims, petty, commissions, total: payroll + claims + petty + commissions }
}

function companyNetPosition() {
  return companyMoneyIn().received - companyMoneyOut().total
}

// ---------------------------------------------------------------------------
// Petty cash
//
// Book balance = approved top-ups − expenses that actually left the tin.
// A rejected expense never left, so it must not reduce the balance; a pending
// one has (HR already paid the payee), so it does.
// ---------------------------------------------------------------------------

function pettyCashApprovedTopUps() {
  return state.pettyCashTopUps.filter(topUp => topUp.status === 'approved')
}

// Cash is gone the moment HR pays it out — only an explicit rejection reverses that.
function pettyCashCountedExpenses() {
  return state.pettyCashExpenses.filter(expense => expense.status !== 'rejected')
}

// Each of these prefers the API summary once it has loaded. The local sums
// remain for the first paint, but the server owns the tin: recomputing a
// balance the API already published is a second chance to disagree with it.
function pettyCashTotalFloat() {
  if (state.pettyCashSummary) return Number(state.pettyCashSummary.float || 0)
  return pettyCashApprovedTopUps().reduce((sum, topUp) => sum + Number(topUp.amount || 0), 0)
}

function pettyCashTotalSpent() {
  if (state.pettyCashSummary) return Number(state.pettyCashSummary.spent || 0)
  return pettyCashCountedExpenses().reduce((sum, expense) => sum + Number(expense.amount || 0), 0)
}

function pettyCashBalance() {
  if (state.pettyCashSummary) return Number(state.pettyCashSummary.balance || 0)
  return pettyCashTotalFloat() - pettyCashTotalSpent()
}

function pettyCashIsLow() {
  if (state.pettyCashSummary) return Boolean(state.pettyCashSummary.isLow)
  return pettyCashBalance() < Number(state.settings.pettyCash.lowBalanceThreshold || 0)
}

// Above the threshold an entry needs a super admin sign-off before it is settled.
function pettyCashNeedsApproval(amount) {
  const threshold = Number(state.settings.pettyCash.approvalThreshold || 0)
  return threshold > 0 && Number(amount || 0) > threshold
}

function pettyCashPendingExpenses() {
  return state.pettyCashExpenses.filter(expense => expense.status === 'pending')
}

function pettyCashPendingTopUps() {
  return state.pettyCashTopUps.filter(topUp => topUp.status === 'pending')
}

// Spend per category, largest first — drives the breakdown panel.
function pettyCashByCategory() {
  const totals = new Map()
  pettyCashCountedExpenses().forEach(expense => {
    const key = expense.category || 'Miscellaneous'
    totals.set(key, (totals.get(key) || 0) + Number(expense.amount || 0))
  })
  return Array.from(totals, ([category, amount]) => ({ category, amount }))
    .sort((a, b) => b.amount - a.amount)
}

// ---------------------------------------------------------------------------
// Team Lead / squad helpers
//
// A "squad" is the set of modules a user leads (module.leadId) plus the people
// on those modules. Leads are working engineers, so they also keep their own
// tickets — these helpers deliberately separate "my work" from "squad work".
// ---------------------------------------------------------------------------

function ledModules(userId = state.currentUser.id) {
  return state.projects.flatMap(project =>
    (project.modules || [])
      .filter(module => module.leadId === userId)
      .map(module => ({ ...module, projectId: project.id, projectName: project.name }))
  )
}

function squadMemberIds(userId = state.currentUser.id) {
  const ids = new Set()
  ledModules(userId).forEach(module => (module.teamMembers || []).forEach(id => ids.add(id)))
  ids.delete(userId) // the lead is not their own report
  return Array.from(ids)
}

function squadTickets(userId = state.currentUser.id) {
  const moduleIds = new Set(ledModules(userId).map(module => module.id))
  return state.tickets.filter(ticket => moduleIds.has(ticket.moduleId))
}

function isTicketOverdue(ticket) {
  if (!ticket?.deadline || ticket.status === 'done') return false
  return ticket.deadline < todayKey()
}

// Tickets the squad has finished and handed back for the lead to sign off.
function squadReviewQueue(userId = state.currentUser.id) {
  return squadTickets(userId).filter(ticket => ticket.status === 'review')
}

function squadOverdueTickets(userId = state.currentUser.id) {
  return squadTickets(userId).filter(isTicketOverdue)
}

// Hours logged today per squad member, for the capacity widget.
function squadCapacityToday(userId = state.currentUser.id) {
  return squadMemberIds(userId).map(id => {
    const employee = state.employees.find(item => item.id === id)
    const attendanceRow = state.attendance.find(row => row.employeeId === id && row.date === todayKey())
    const activeTicket = state.tickets.find(ticket =>
      ticket.assigneeId === id && (ticket.timeLogs || []).some(log => log.end === null)
    )
    return {
      id,
      name: employee?.name || 'Unknown',
      checkIn: attendanceRow?.checkIn || null,
      status: attendanceRow?.status || 'absent',
      seconds: employeeSecondsToday(id),
      activeTicket: activeTicket || null
    }
  })
}

// Leave requests from this lead's squad that still need their stage-one
// approval. Surfaced on the Team Lead dashboard.
function squadLeaveAwaitingLead(userId = state.currentUser.id) {
  const memberIds = new Set(squadMemberIds(userId))
  return state.leaveRequests.filter(request =>
    memberIds.has(request.employeeId)
    && request.status !== 'rejected'
    && (request.managerApproval?.status || 'pending') !== 'approved'
  )
}

// Every role that appears in the permission table. Adding a role is a data
// change — no code edit required.
const availableRoles = computed(() =>
  Array.from(new Set(state.rolePermissions.map(row => row.role)))
)

function roleLabel(role) {
  if (role === 'super_admin') return 'Super Admin'
  if (role === 'hr') return 'HR'
  return String(role || '').replace(/_/g, ' ').replace(/\b\w/g, letter => letter.toUpperCase())
}

function setCurrentUserRole(role) {
  if (availableRoles.value.includes(role)) {
    state.currentUser.role = role
    persistCurrentUser(state.currentUser)
  }
}

/**
 * Timers are persisted server-side, where "one running timer per person" is
 * enforced in a transaction. The local update below is optimistic so the
 * counter starts ticking immediately; a failure is reverted by the caller
 * reloading, since a timer that appears to run but was never recorded is worse
 * than one that visibly failed to start.
 */
async function startTicketTimer(ticketId) {
  if (state.currentUser.role !== 'employee') return
  // Stop any timer currently running for this user before starting a new one.
  if (state.activeTimer) {
    stopTicketTimer(state.activeTimer.ticketId)
  }
  const ticket = state.tickets.find(t => t.id === ticketId)
  if (!ticket) return
  if (!ticket.timeLogs) ticket.timeLogs = []
  ticket.timeLogs.push({ start: new Date().toISOString(), end: null })
  if (ticket.status === 'todo') ticket.status = 'in_progress'
  state.activeTimer = { ticketId }

  try {
    const { ticketService } = await import('../services/ticketService.js')
    upsertTicket(await ticketService.startTimer(ticketId))
  } catch {
    // Roll back the optimistic log so the UI does not show time that is not
    // being recorded.
    ticket.timeLogs = (ticket.timeLogs || []).filter(log => log.end !== null)
    state.activeTimer = null
  }
}

async function stopTicketTimer(ticketId) {
  const ticket = state.tickets.find(t => t.id === ticketId)
  if (!ticket) return
  const openLog = (ticket.timeLogs || []).find(l => l.end === null)
  if (openLog) openLog.end = new Date().toISOString()
  if (state.activeTimer && state.activeTimer.ticketId === ticketId) {
    state.activeTimer = null
  }

  try {
    const { ticketService } = await import('../services/ticketService.js')
    await ticketService.stopTimer()
  } catch {
    /* the log is closed locally; a reload will reconcile from the server */
  }
}

function projectSecondsToday(projectId) {
  return state.tickets
    .filter(t => t.projectId === projectId)
    .reduce((sum, t) => sum + ticketSecondsToday(t), 0)
}

function employeeSecondsToday(employeeId) {
  return state.tickets
    .filter(t => t.assigneeId === employeeId)
    .reduce((sum, t) => sum + ticketSecondsToday(t), 0)
}

const totalHoursToday = computed(() =>
  state.projects.reduce((sum, p) => sum + projectSecondsToday(p.id), 0)
)

function projectBudget(project) {
  return Number(project?.budget || 0)
}

function projectReceived(project) {
  return (project?.payments || []).reduce((total, payment) => total + Number(payment.amount || 0), 0)
}

function projectPending(project) {
  return Math.max(0, projectBudget(project) - projectReceived(project))
}

function projectCommissionEarned(project) {
  const commission = project?.commission || { type: 'percent', value: 0 }
  const received = projectReceived(project)
  if (commission.type === 'fixed') {
    const budget = projectBudget(project)
    if (!budget) return 0
    return Math.min(Number(commission.value || 0), Number(commission.value || 0) * (received / budget))
  }
  return received * (Number(commission.value || 0) / 100)
}

function projectCommissionPaid(project) {
  return project?.commission?.status === 'paid' ? projectCommissionEarned(project) : 0
}

function projectCommissionOwed(project) {
  return Math.max(0, projectCommissionEarned(project) - projectCommissionPaid(project))
}

function companyLocale() {
  return state.settings.company.locale || 'en-US'
}

function companyTimezone() {
  return state.settings.company.timezone || undefined
}

function currencyCode() {
  return state.settings.company.currency || 'PKR'
}

// Formats money using the tenant's locale + currency. Defaults to the
// "PKR 1,200" style the UI already used; set company.currencyDisplay to
// 'symbol' for "₨1,200".
function formatCurrency(amount) {
  const value = Number(amount || 0)
  const code = currencyCode()
  if (state.settings.company.currencyDisplay === 'symbol') {
    return new Intl.NumberFormat(companyLocale(), {
      style: 'currency',
      currency: code,
      maximumFractionDigits: 0
    }).format(value)
  }
  // Round to whole units. Percentage-based commissions produce values like
  // 102105.263, and without this they rendered as "PKR 961,955.263" beside
  // clean figures, which reads as a defect.
  return `${code} ${value.toLocaleString(companyLocale(), { maximumFractionDigits: 0 })}`
}

// Bare number, no currency code — for table cells that already show the
// currency in the column header.
function formatAmount(amount) {
  return Number(amount || 0).toLocaleString(companyLocale(), { maximumFractionDigits: 0 })
}

// All date/time rendering goes through these so a tenant in another timezone
// sees their own local dates rather than the browser's.
function formatDate(value, options = { month: 'short', day: 'numeric', year: 'numeric' }) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'
  return date.toLocaleDateString(companyLocale(), { timeZone: companyTimezone(), ...options })
}

function formatTime(value = new Date(), options = { hour: '2-digit', minute: '2-digit' }) {
  const date = value ? new Date(value) : new Date()
  if (Number.isNaN(date.getTime())) return '-'
  return date.toLocaleTimeString(companyLocale(), { timeZone: companyTimezone(), ...options })
}

// "HH:MM" in the company timezone — a stable, comparable clock value
// independent of the display locale's 12/24-hour preference.
function clockValue(value = new Date()) {
  const date = value ? new Date(value) : new Date()
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleTimeString('en-GB', {
    timeZone: companyTimezone(),
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  })
}

function minutesFromClock(value) {
  const [hours, minutes] = String(value || '').split(':').map(Number)
  if (Number.isNaN(hours) || Number.isNaN(minutes)) return null
  return hours * 60 + minutes
}

// Attendance status honouring settings.attendance.startTime + graceMinutes,
// so changing the grace period in Settings actually changes who is marked late.
function attendanceStatusFor(checkInClock) {
  const checkIn = minutesFromClock(checkInClock)
  const start = minutesFromClock(state.settings.attendance.startTime)
  if (checkIn === null || start === null) return 'present'
  const grace = Number(state.settings.attendance.graceMinutes || 0)
  return checkIn > start + grace ? 'late' : 'present'
}

// Combined date + time stamp (activity feeds, comment timestamps, the clock).
function formatDateTime(value = new Date(), options = { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) {
  const date = value ? new Date(value) : new Date()
  if (Number.isNaN(date.getTime())) return '-'
  return date.toLocaleString(companyLocale(), { timeZone: companyTimezone(), ...options })
}

function notificationAudienceMatches(notification, user = state.currentUser) {
  const roles = notification.recipients?.roles || []
  const userIds = notification.recipients?.userIds || []
  return roles.includes(user.role) || userIds.includes(user.id)
}

const visibleNotifications = computed(() =>
  state.notifications.filter(notification => notificationAudienceMatches(notification))
)

const unreadNotifications = computed(() =>
  visibleNotifications.value.filter(notification => !(notification.readBy || []).includes(state.currentUser.id))
)

function pushNotification({ title, message, type = 'system', roles = [], userIds = [], route = null }) {
  state.notifications.unshift({
    id: Date.now() + Math.floor(Math.random() * 1000),
    title,
    message,
    type,
    route,
    createdAt: new Date().toISOString(),
    readBy: [],
    recipients: {
      roles,
      userIds
    }
  })
}

/**
 * Marks a notification read.
 *
 * Updates locally first so the badge responds immediately, then persists. A
 * server-backed row is told to the API; a seeded one has no server record to
 * update, which is why the call is conditional rather than unconditional.
 */
function markNotificationRead(notificationId) {
  const notification = state.notifications.find(item => item.id === notificationId)
  if (!notification) return
  if (!notification.readBy) notification.readBy = []
  if (notification.readBy.includes(state.currentUser.id)) return

  notification.readBy.push(state.currentUser.id)

  if (!notification.fromServer) return

  if (state.unreadCounts.notifications > 0) state.unreadCounts.notifications -= 1

  // Fire and forget: the badge is already right, and a failed write is
  // corrected by the next poll rather than needing to block the click.
  import('../services/messageService.js')
    .then(({ messageService }) => messageService.markNotificationRead(notificationId))
    .catch(() => {})
}

function markAllNotificationsRead() {
  visibleNotifications.value.forEach(notification => markNotificationRead(notification.id))
}

/**
 * Records one action in the audit trail.
 *
 * Seventeen modules call this. It used to write only to the reactive array
 * below, so every leave approval, payroll release and petty-cash decision was
 * lost on refresh — the one log an HR system actually has to keep.
 *
 * Added locally first so an administrator watching the page sees it at once,
 * then persisted. Fire-and-forget on purpose: the action being logged has
 * already happened, so failing the log would only lose the record of it, and
 * interrupting the user over a failed audit write helps nobody. The server
 * copy is authoritative and replaces this row on the next load.
 */
function logActivity({ module, action, details, targetId = null, targetType = '' }) {
  state.activityLogs.unshift({
    id: `local:${Date.now()}`,
    actorId: state.currentUser.id,
    actorName: state.currentUser.name,
    actorRole: state.currentUser.role,
    module,
    action,
    details,
    targetId,
    targetType,
    createdAt: new Date().toISOString()
  })

  // Skipped when signed out: there is no token to attribute the action to, and
  // the request would only 401.
  if (!state.currentUser.id) return

  import('../services/activityLogService.js')
    .then(({ activityLogService }) =>
      activityLogService.record({ module, action, details, targetId, targetType }))
    .catch(() => {
      /* the local entry stands; the next load reads the server's copy */
    })
}

/** Replaces the local trail with the server's, newest first. */
function setActivityLogs(rows) {
  state.activityLogs = (rows || []).map(row => ({
    id: row.id,
    actorId: row.actorId,
    actorName: row.actorName,
    actorRole: row.actorRole,
    module: row.module,
    action: row.action,
    details: row.details,
    targetId: row.targetId,
    targetType: row.targetType,
    createdAt: row.createdAt
  }))
}

/**
 * @param {number} [employeeId] the employment record's id, which is what the
 *   realtime channel is keyed on — a user id would subscribe to the wrong
 *   channel and be rejected by channel authorisation.
 */
function setCurrentUser(id, name, role, email = '', employeeId = null) {
  state.currentUser = { id, name, role, email, employeeId }
  persistCurrentUser(state.currentUser)
}

/**
 * Replaces the seeded grid with the one the API returned for the signed-in
 * user. The API scopes rows to their role and has already resolved the
 * super-admin fallthrough, so each row arrives without a `role` key — it is
 * stamped back on here because permissionRow() looks rows up by role.
 */
function setRolePermissions(rows) {
  if (!Array.isArray(rows) || rows.length === 0) return

  const role = state.currentUser.role
  state.rolePermissions = rows.map(row => ({
    role,
    module: row.module,
    view: Boolean(row.view),
    create: Boolean(row.create),
    approve: Boolean(row.approve),
    finance: Boolean(row.finance),
    scope: row.scope
  }))
}

// ---------------------------------------------------------------------------
// API-backed collections
//
// These replace the mock arrays once loaded. The API already scopes each
// response to what the signed-in user may see, so the client-side scopedList()
// calls become a second, narrower filter rather than the security boundary —
// which is correct: the browser copy was never enforceable anyway.
// ---------------------------------------------------------------------------

/**
 * Maps an API project onto the shape the views expect. The API nests modules
 * and returns member ids under `teamMembers`; both already match, so this is
 * mostly a pass-through that guarantees the arrays exist.
 */
function mapProject(project) {
  return {
    ...project,
    teamMembers: project.teamMembers || [],
    modules: (project.modules || []).map(module => ({
      ...module,
      teamMembers: module.teamMembers || []
    }))
  }
}

function setProjects(rows) {
  state.projects = (rows || []).map(mapProject)
}

function setTickets(rows) {
  state.tickets = (rows || []).map(ticket => ({
    ...ticket,
    // The views read `comments` and `timeLogs` unconditionally; the list
    // endpoint omits them (they are detail-only), so default them here rather
    // than guarding every `.length` in the templates.
    comments: ticket.comments || [],
    timeLogs: ticket.timeLogs || []
  }))
}

function setEmployees(rows) {
  state.employees = (rows || []).map(employee => ({
    ...employee,
    // Views filter on `role`, which lives on the user rather than the
    // employment record. The list endpoint eager-loads it.
    role: employee.role || employee.user?.role || 'employee'
  }))
}

function setAttendance(rows) {
  state.attendance = (rows || []).map(row => ({
    ...row,
    // The ledger renders a photo thumbnail when one exists. The API stores a
    // path rather than the image, and only returns whether there is one, so
    // rows loaded from the server have no inline photo — only a punch made in
    // this session does.
    checkInPhoto: row.checkInPhoto || null,
    wfh: Boolean(row.wfh)
  }))
}

/** Replaces today's punch after a check in or out, leaving history intact. */
function upsertAttendance(row) {
  if (!row) return
  const index = state.attendance.findIndex(a => a.id === row.id)
  if (index === -1) state.attendance.unshift({ ...row, checkInPhoto: row.checkInPhoto || null })
  // Merge rather than replace: a check-out response carries no photo, and
  // overwriting would drop the one captured at check-in.
  else state.attendance[index] = { ...state.attendance[index], ...row }
}

/**
 * Maps an API leave request onto the shape the view expects.
 *
 * The API returns approvals as a flat `[{stage, decision, approverName}]`
 * array; the view reads `managerApproval` / `hrApproval` objects and has done
 * since before the API existed. Projecting here keeps that whole screen —
 * status labels, timelines, review columns — working unchanged.
 *
 * 'lead' maps to managerApproval: the field name predates the rename of that
 * stage to Team Lead. 'escalation' is super admin's stage, used for requests
 * no one else can settle — HR's own leave has no lead above it.
 */
function mapLeaveRequest(request) {
  const approvals = request.approvals || []
  const stage = name => {
    const row = approvals.find(a => a.stage === name)
    if (!row) return { status: 'pending' }
    return { status: row.decision, byName: row.approverName, at: row.decidedAt, note: row.comment }
  }

  return {
    ...request,
    approvals,
    managerApproval: stage('lead'),
    hrApproval: stage('hr'),
    superAdminApproval: stage('escalation'),
    // The list renders 'half' vs 'full' from a duration field the API does not
    // store — it keeps `days`, which is the figure the balance is drawn from.
    duration: request.days % 1 !== 0 ? 'half' : 'full'
  }
}

function setLeaveRequests(rows) {
  state.leaveRequests = (rows || []).map(mapLeaveRequest)
}

function upsertLeaveRequest(request) {
  if (!request) return
  const mapped = mapLeaveRequest(request)
  const index = state.leaveRequests.findIndex(r => r.id === mapped.id)
  if (index === -1) state.leaveRequests.unshift(mapped)
  else state.leaveRequests[index] = { ...state.leaveRequests[index], ...mapped }
}

function setMedicalClaims(rows) {
  state.medicalClaims = (rows || []).map(claim => ({
    ...claim,
    // Amounts arrive as strings to protect precision in transit. The views
    // format and total them, so they are numbers from here on.
    amount: Number(claim.amount),
    approvedAmount: claim.approvedAmount === null ? null : Number(claim.approvedAmount)
  }))
}

function upsertMedicalClaim(claim) {
  if (!claim) return
  const mapped = {
    ...claim,
    amount: Number(claim.amount),
    approvedAmount: claim.approvedAmount === null ? null : Number(claim.approvedAmount)
  }
  const index = state.medicalClaims.findIndex(c => c.id === mapped.id)
  if (index === -1) state.medicalClaims.unshift(mapped)
  else state.medicalClaims[index] = { ...state.medicalClaims[index], ...mapped }
}

/**
 * Merges the server-held policy groups into settings.
 *
 * Only attendance, leave and claims are stored server-side. The remaining
 * groups (company, finance, pettyCash, designations, stacks) are still local
 * and belong to later phases, so this merges per group instead of replacing
 * `state.settings` wholesale — a replace would erase them.
 */
function setServerSettings(groups) {
  Object.entries(groups || {}).forEach(([group, values]) => {
    if (!values || typeof values !== 'object') return
    state.settings[group] = { ...state.settings[group], ...values }
  })
}

/**
 * Maps an API slip request onto what SalarySlips.vue reads.
 *
 * The view keys rows by a "June 2026" month label and reads the slip's
 * figures off the request, so the issued slip is flattened onto it. Money
 * arrives as strings — pay is encrypted and JSON numbers are doubles — and is
 * converted here, since every consumer does arithmetic on it.
 */
function mapSlipRequest(request) {
  const slip = request.slip || null

  return {
    ...request,
    month: request.month,
    slipNo: slip ? `SLP-${String(slip.id).padStart(5, '0')}` : '',
    generatedAt: slip?.issuedAt || '',
    approvedAt: slip?.approvedAt || '',
    paidAt: slip?.paidAt || '',
    payment: slip?.payment || null,
    // The slip's own state is the real one once issued; before that the
    // request is merely pending or rejected.
    status: slip?.status || request.status,
    details: slip
      ? {
          baseSalary: Number(slip.baseSalary),
          houseAllowance: Number(slip.houseAllowance),
          medicalAllowance: Number(slip.medicalAllowance),
          bonus: Number(slip.bonus),
          taxDeduction: Number(slip.taxDeduction),
          attendanceDeduction: Number(slip.attendanceDeduction),
          advanceDeduction: Number(slip.advanceDeduction),
          notes: slip.notes || ''
        }
      : null,
    slipId: slip?.id || null,
    history: request.history || []
  }
}

function setSalarySlipRequests(rows) {
  state.salarySlipRequests = (rows || []).map(mapSlipRequest)
}

function upsertSalarySlipRequest(request) {
  if (!request) return
  const mapped = mapSlipRequest(request)
  const index = state.salarySlipRequests.findIndex(r => r.id === mapped.id)
  if (index === -1) state.salarySlipRequests.unshift(mapped)
  else state.salarySlipRequests[index] = { ...state.salarySlipRequests[index], ...mapped }
}

/**
 * The petty cash ledgers and the tin's position.
 *
 * `pettyCashSummary` holds the API's figures. The local pettyCashBalance()
 * helpers stay as a fallback for before the fetch lands, but once the summary
 * is set it wins — a second calculation in the browser is a second chance to
 * disagree with the ledger.
 */
function setPettyCash({ summary, topUps, expenses, reconciliations }) {
  if (summary) state.pettyCashSummary = summary
  if (topUps) state.pettyCashTopUps = topUps.map(mapPettyCashRow)
  if (expenses) state.pettyCashExpenses = expenses.map(mapPettyCashRow)
  if (reconciliations) state.pettyCashReconciliations = reconciliations.map(mapPettyCashRow)
}

/** Amounts arrive as strings to protect precision; the views total them. */
function mapPettyCashRow(row) {
  return {
    ...row,
    amount: row.amount === undefined ? undefined : Number(row.amount),
    countedAmount: row.countedAmount === undefined ? undefined : Number(row.countedAmount),
    bookBalance: row.bookBalance === undefined ? undefined : Number(row.bookBalance),
    variance: row.variance === undefined ? undefined : Number(row.variance)
  }
}

// ---------------------------------------------------------------------------
// Live feed
//
// syncService polls /api/sync and hands the results here. Both collections are
// merged by id rather than replaced: a poll returns only what is new, so
// overwriting would discard everything older than the last few seconds.
// ---------------------------------------------------------------------------

/**
 * Maps an API notification onto the shape the bell already reads.
 *
 * The store's rows carry `message` and a `readBy` array (a client-side idea
 * of who has read a shared row); the API tracks read state per recipient
 * because a notification belongs to exactly one person. Translating here
 * keeps every consumer of unreadNotifications() working untouched.
 */
function mapNotification(row) {
  return {
    id: row.id,
    title: row.title,
    message: row.body,
    type: row.type,
    route: row.route,
    createdAt: row.createdAt,
    // Already read on the server means read for this user.
    readBy: row.isRead ? [state.currentUser.id] : [],
    recipients: { roles: [], userIds: [state.currentUser.id] },
    fromServer: true
  }
}

// Broadcasts are stored by audience id so the API can resolve the group
// itself; these are the labels the UI shows for them.
const AUDIENCE_LABELS = {
  all_company: 'All company',
  employees: 'All employees',
  managers: 'All managers',
  hr: 'HR team'
}

function mapMessage(row) {
  return {
    id: row.id,
    senderId: row.senderId,
    senderName: row.senderName,
    audience: AUDIENCE_LABELS[row.audience] || row.audience,
    subject: row.subject,
    body: row.body,
    createdAt: row.createdAt,
    readBy: row.isRead ? [state.currentUser.id] : [],
    // The real delivery list: the view groups a conversation by who is on the
    // other side, and tells a broadcast from a direct message by the count.
    // Falling back to the current user would make every message look like a
    // one-to-one with themselves.
    recipientIds: row.recipientIds ?? [state.currentUser.id],
    // Present only on messages you sent: how many recipients have read it.
    // Drives the delivery ticks.
    delivery: row.delivery ?? null,
    // 'sent' once the API has it. An optimistic row starts 'sending' and
    // becomes 'failed' if the request does not land.
    status: 'sent',
    fromServer: true
  }
}

/** Merges newly polled rows in, newest first, without duplicating. */
function mergeById(collection, rows) {
  rows.forEach(row => {
    const index = collection.findIndex(item => item.id === row.id && item.fromServer)
    if (index === -1) collection.unshift(row)
    else collection[index] = { ...collection[index], ...row }
  })
}

/**
 * Adds a message to the thread before the API has confirmed it.
 *
 * Chat has to feel immediate: waiting for a round trip before showing what
 * someone just typed is the difference between an app that feels instant and
 * one that feels sluggish, even at 150ms. The row carries a temporary id and
 * status 'sending' until confirmOptimisticMessage() swaps in the real one.
 */
function addOptimisticMessage({ tempId, body, subject, audience, recipientIds }) {
  state.messages.unshift({
    id: tempId,
    // The exact payload, kept so a retry replays what was actually sent rather
    // than rebuilding it from UI state that has since moved on.
    payload: { body, subject, audience, recipientIds },
    senderId: state.currentUser.id,
    senderName: state.currentUser.name,
    senderRole: state.currentUser.role,
    audience,
    subject,
    body,
    createdAt: new Date().toISOString(),
    readBy: [state.currentUser.id],
    recipientIds,
    delivery: null,
    status: 'sending',
    fromServer: false
  })
}

/** Replaces a pending row with what the API actually stored. */
function confirmOptimisticMessage(tempId, sent) {
  const index = state.messages.findIndex(item => item.id === tempId)
  if (index === -1) return

  state.messages[index] = {
    ...state.messages[index],
    id: sent.id,
    createdAt: sent.createdAt,
    recipientIds: sent.recipientIds ?? state.messages[index].recipientIds,
    delivery: { total: (sent.recipientIds ?? []).length, read: 0 },
    status: 'sent',
    fromServer: true
  }
}

/**
 * Marks a pending row failed, keeping the text so it can be retried rather
 * than silently vanishing — losing what someone typed is worse than showing
 * that it did not send.
 */
function failOptimisticMessage(tempId) {
  const message = state.messages.find(item => item.id === tempId)
  if (message) message.status = 'failed'
}

function removeMessage(id) {
  const index = state.messages.findIndex(item => item.id === id)
  if (index !== -1) state.messages.splice(index, 1)
}

function applySync({ messages, notifications, receipts, unread }) {
  if (messages?.length) mergeById(state.messages, messages.map(mapMessage))
  if (notifications?.length) mergeById(state.notifications, notifications.map(mapNotification))

  // Delivery counts for messages this user sent, so a single tick becomes a
  // double one when the other side opens the thread — without a reload.
  receipts?.forEach(receipt => {
    const message = state.messages.find(item => item.id === receipt.id)
    if (message) message.delivery = { total: receipt.total, read: receipt.read }
  })

  if (unread) state.unreadCounts = unread
}

/**
 * Applies a presence event from the socket.
 *
 * 'here' is the full roster on joining and replaces what we had; the others
 * are deltas. A member's entry carries their chosen status too, so joining
 * populates both halves at once.
 */
function applyPresence(event) {
  if (!event) return

  if (event.type === 'here') {
    const online = {}
    ;(event.members || []).forEach(member => {
      online[member.id] = true
      if (member.status) state.presence.chosen[member.id] = { status: member.status, message: member.message }
    })
    state.presence.online = online
    return
  }

  if (event.type === 'joined' && event.member) {
    state.presence.online[event.member.id] = true
    if (event.member.status) {
      state.presence.chosen[event.member.id] = { status: event.member.status, message: event.member.message }
    }
    return
  }

  if (event.type === 'left' && event.member) {
    delete state.presence.online[event.member.id]
    return
  }

  // A status change from someone who stayed connected.
  if (event.type === 'changed' && event.member) {
    state.presence.chosen[event.member.id] = {
      status: event.member.status,
      message: event.member.message
    }
  }
}

/** The chosen statuses fetched on load, for people who are currently offline. */
function setChosenPresence(rows) {
  const chosen = { ...state.presence.chosen }
  ;(rows || []).forEach(row => {
    chosen[row.id] = { status: row.status, message: row.message }
  })
  state.presence.chosen = chosen
}

/**
 * One person's effective status: active, away, or offline.
 *
 * Away wins over the connection — someone who marked themselves away is away
 * whether or not the browser is still open, which is the whole point of
 * saying so. Otherwise presence follows the socket.
 */
function presenceFor(employeeId) {
  const chosen = state.presence.chosen[employeeId]
  const isOnline = Boolean(state.presence.online[employeeId])

  if (chosen?.status === 'away') {
    return { status: 'away', message: chosen.message }
  }

  return {
    status: isOnline ? 'active' : 'offline',
    message: chosen?.message ?? null
  }
}

/** Adds a page of older history without disturbing what is already loaded. */
function prependMessages(rows) {
  const existing = new Set(state.messages.map(m => m.id))
  const older = (rows || []).map(mapMessage).filter(m => !existing.has(m.id))
  state.messages.push(...older)
}

/** Maps API assets onto the shape the view reads. */
function setAssets(rows) {
  state.assets = (rows || []).map(row => ({
    ...row,
    // The view reads these off the asset; the API keeps them on the open
    // assignment, because an asset issued three times has three sets of dates
    // and only the current one belongs here.
    issuedAt: row.issuedAt || '',
    returnDue: row.returnDue || ''
  }))
}

/** Both Company Hub lists, from one call. */
function setCompanyHub({ announcements, policies }) {
  if (announcements) state.announcements = announcements
  if (policies) state.policies = policies
}

function setMessages(rows) {
  state.messages = (rows || []).map(mapMessage)
}

/** Marks a server-backed row read locally, so the badge updates at once. */
function markMessageReadLocally(id) {
  const message = state.messages.find(item => item.id === id && item.fromServer)
  if (message && !message.readBy.includes(state.currentUser.id)) {
    message.readBy.push(state.currentUser.id)
    if (state.unreadCounts.messages > 0) state.unreadCounts.messages -= 1
  }
}

/** Replaces one project in place, e.g. after a member sync. */
function upsertProject(project) {
  const mapped = mapProject(project)
  const index = state.projects.findIndex(p => p.id === mapped.id)
  if (index === -1) state.projects.push(mapped)
  else state.projects[index] = mapped
}

function upsertTicket(ticket) {
  const index = state.tickets.findIndex(t => t.id === ticket.id)
  const mapped = {
    ...ticket,
    comments: ticket.comments || [],
    timeLogs: ticket.timeLogs || []
  }
  if (index === -1) state.tickets.push(mapped)
  else state.tickets[index] = { ...state.tickets[index], ...mapped }
}

/**
 * Ends the session and clears everything that belonged to it.
 *
 * Not just the user: the message history, notifications, unread counts, sync
 * cursors and presence all have to go. Clearing only currentUser left the next
 * person to sign in on this browser looking at the previous user's
 * conversations — the cursors were already past those ids, so nothing would
 * ever correct it.
 */
function clearCurrentUser() {
  state.currentUser = { ...guestUser }
  state.messages = []
  state.notifications = []
  state.unreadCounts = { messages: 0, notifications: 0 }
  state.presence = { online: {}, chosen: {} }
  state.syncStatus = 'idle'
  state.socketStatus = 'idle'

  try {
    window.localStorage.removeItem(currentUserStorageKey)
  } catch {
    /* storage unavailable */
  }
}

function isAuthenticated() {
  return Boolean(state.currentUser.id)
}

export function useStore() {
  return {
    state,
    todayKey,
    ticketSeconds,
    ticketSecondsToday,
    projectSecondsToday,
    employeeSecondsToday,
    formatDuration,
    formatCurrency,
    formatAmount,
    formatDate,
    formatTime,
    formatDateTime,
    clockValue,
    attendanceStatusFor,
    currencyCode,
    projectBudget,
    projectReceived,
    projectPending,
    projectCommissionEarned,
    projectCommissionPaid,
    projectCommissionOwed,
    visibleNotifications,
    unreadNotifications,
    pushNotification,
    markNotificationRead,
    markAllNotificationsRead,
    logActivity,
    availableRoles,
    roleLabel,
    companyMoneyIn,
    companyMoneyOut,
    companyNetPosition,
    monthlyPayrollCost,
    approvedClaimsCost,
    pettyCashTotalFloat,
    pettyCashTotalSpent,
    pettyCashBalance,
    pettyCashIsLow,
    pettyCashNeedsApproval,
    pettyCashPendingExpenses,
    pettyCashPendingTopUps,
    pettyCashByCategory,
    ledModules,
    squadMemberIds,
    squadTickets,
    squadReviewQueue,
    squadOverdueTickets,
    squadCapacityToday,
    squadLeaveAwaitingLead,
    isTicketOverdue,
    can,
    scopedList,
    permissionRow,
    setCurrentUserRole,
    setCurrentUser,
    clearCurrentUser,
    isAuthenticated,
    setRolePermissions,
    setProjects,
    setTickets,
    setEmployees,
    setAttendance,
    upsertAttendance,
    setLeaveRequests,
    upsertLeaveRequest,
    setMedicalClaims,
    upsertMedicalClaim,
    setSalarySlipRequests,
    upsertSalarySlipRequest,
    setPettyCash,
    applySync,
    setAssets,
    setCompanyHub,
    setMessages,
    setActivityLogs,
    prependMessages,
    applyPresence,
    setChosenPresence,
    presenceFor,
    addOptimisticMessage,
    confirmOptimisticMessage,
    failOptimisticMessage,
    removeMessage,
    markMessageReadLocally,
    setServerSettings,
    upsertProject,
    upsertTicket,
    startTicketTimer,
    stopTicketTimer,
    totalHoursToday
  }
}

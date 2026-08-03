import { reactive, computed } from 'vue'
import { employees, attendance, projects, tickets, medicalClaims, leaveRequests, salarySlipRequests } from '../data/mock.js'

const currentUserStorageKey = 'orbit-current-user'

// A signed-out session. Previously this defaulted to a hardcoded manager
// ("Zara Nadeem"), which meant anyone opening the app was silently logged in —
// the router guard checked permissions but never authentication.
const guestUser = { id: null, name: '', role: 'guest', email: '' }

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
      email: parsed.email || ''
    }
  } catch {
    return { ...guestUser }
  }
}

function persistCurrentUser(user) {
  if (typeof window === 'undefined') return
  window.localStorage.setItem(currentUserStorageKey, JSON.stringify(user))
}

// Single reactive store, no backend yet. Swap the mutating functions below
// for Laravel API calls (axios) once the backend is ready — the shape of
// state.tickets / state.attendance is designed to map 1:1 onto typical
// REST responses.
const state = reactive({
  currentUser: loadStoredCurrentUser(),
  employees,
  attendance,
  projects,
  tickets,
  medicalClaims,
  leaveRequests,
  salarySlipRequests,
  assets: [
    {
      id: 1,
      name: 'MacBook Pro 14',
      category: 'Laptop',
      serialNumber: 'ORB-MBP-014',
      assignedTo: 1,
      condition: 'Good',
      status: 'assigned',
      issuedAt: '2026-06-10',
      returnDue: '2027-06-10',
      notes: 'Developer machine with charger and sleeve.'
    },
    {
      id: 2,
      name: 'Dell Latitude 7440',
      category: 'Laptop',
      serialNumber: 'ORB-DLL-022',
      assignedTo: 3,
      condition: 'Excellent',
      status: 'assigned',
      issuedAt: '2026-05-18',
      returnDue: '2027-05-18',
      notes: 'Manager laptop.'
    },
    {
      id: 3,
      name: 'Logitech MX Master 3S',
      category: 'Accessory',
      serialNumber: 'ORB-MSE-008',
      assignedTo: null,
      condition: 'New',
      status: 'available',
      issuedAt: '',
      returnDue: '',
      notes: 'Available in IT inventory.'
    }
  ],
  // Petty cash: office running costs, distinct from Finance (client revenue).
  // Float in, expenses out, and a reconciliation that compares the book balance
  // against physically counted cash.
  pettyCashTopUps: [
    {
      id: 1,
      amount: 50000,
      date: '2026-07-01',
      reference: 'Opening float',
      note: 'Initial petty cash float for July.',
      requestedById: 6,
      status: 'approved',
      approvedById: 99,
      approvedAt: '2026-07-01T09:00:00'
    },
    {
      id: 2,
      amount: 20000,
      date: '2026-07-21',
      reference: 'Mid-month top-up',
      note: 'Replenishment after courier and refreshment spend.',
      requestedById: 6,
      status: 'pending',
      approvedById: null,
      approvedAt: null
    }
  ],
  pettyCashExpenses: [
    {
      id: 1, amount: 2400, date: '2026-07-03', category: 'Office supplies',
      payee: 'Metro Stationers', description: 'Printer paper and markers.',
      receiptName: 'metro-3jul.jpg', loggedById: 6,
      status: 'recorded', approvedById: null, approvedAt: null
    },
    {
      id: 2, amount: 1850, date: '2026-07-08', category: 'Refreshments',
      payee: 'Cafe Junction', description: 'Team lunch during release day.',
      receiptName: '', loggedById: 6,
      status: 'recorded', approvedById: null, approvedAt: null
    },
    {
      id: 3, amount: 12500, date: '2026-07-17', category: 'Repairs',
      payee: 'CoolAir Services', description: 'Office AC servicing.',
      receiptName: 'coolair-inv.pdf', loggedById: 6,
      status: 'pending', approvedById: null, approvedAt: null
    },
    {
      id: 4, amount: 900, date: '2026-07-24', category: 'Courier',
      payee: 'TCS Express', description: 'Contract documents to client.',
      receiptName: '', loggedById: 6,
      status: 'recorded', approvedById: null, approvedAt: null
    }
  ],
  pettyCashReconciliations: [
    {
      id: 1,
      date: '2026-06-30',
      countedAmount: 4500,
      bookBalance: 4500,
      variance: 0,
      note: 'June close — cash matched.',
      byId: 6
    }
  ],
  announcements: [
    {
      id: 1,
      title: 'Quarterly performance reviews',
      audience: 'All company',
      priority: 'normal',
      body: 'Team leads will schedule review sessions during the last week of July.',
      authorId: 99,
      publishedAt: new Date().toISOString()
    },
    {
      id: 2,
      title: 'Updated leave policy',
      audience: 'Employees and Managers',
      priority: 'high',
      body: 'Half-day leave is now available in the leave request form.',
      authorId: 6,
      publishedAt: new Date().toISOString()
    }
  ],
  policies: [
    {
      id: 1,
      title: 'Remote work policy',
      category: 'HR',
      ownerId: 6,
      version: '1.2',
      updatedAt: '2026-07-01',
      summary: 'Guidelines for remote attendance, availability, and approvals.',
      acknowledgmentRequired: true
    },
    {
      id: 2,
      title: 'Asset handling policy',
      category: 'IT',
      ownerId: 6,
      version: '1.0',
      updatedAt: '2026-06-20',
      summary: 'Employees are responsible for company assets assigned to them.',
      acknowledgmentRequired: true
    }
  ],
  messages: [
    {
      id: 1,
      senderId: 3,
      senderName: 'Zara Nadeem',
      senderRole: 'manager',
      recipientIds: [1],
      audience: 'Direct message',
      subject: 'Today priorities',
      body: 'Please focus on the WebAuthn check-in flow first, then update the ticket before EOD.',
      createdAt: new Date().toISOString(),
      readBy: []
    },
    {
      id: 2,
      senderId: 99,
      senderName: 'Admin User',
      senderRole: 'super_admin',
      recipientIds: [1, 2, 3, 4, 6],
      audience: 'All employees',
      subject: 'Friday town hall',
      body: 'We will have a short company town hall this Friday to review delivery updates and celebrate team milestones.',
      createdAt: new Date().toISOString(),
      readBy: [3]
    }
  ],
  // Single source of truth for role permissions. `can(module, action, resource)`
  // reads this table — editing a row in Access Control changes real behaviour.
  //
  // scope narrows *which records* a role may act on:
  //   'all'   - every record in the module
  //   'owned' - only records the user owns (project.managerId === user.id)
  //   'team'  - only records the user is a team member of
  //   'self'  - only the user's own record (own payslip, own profile)
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
  activityLogs: [
    {
      id: 1,
      actorId: 99,
      actorName: 'System',
      actorRole: 'system',
      module: 'finance',
      action: 'module enabled',
      details: 'Finance tracking and notifications were enabled.',
      createdAt: new Date().toISOString()
    }
  ],
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
  notifications: [
    {
      id: 1,
      title: 'Finance module ready',
      message: 'Module budgets, payments, and commissions are now tracked in Finance.',
      type: 'finance',
      createdAt: new Date().toISOString(),
      readBy: [],
      recipients: { roles: ['manager', 'super_admin'], userIds: [] }
    }
  ],
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
  if (user.role === 'super_admin' && !SUPER_ADMIN_EXCLUDED.includes(module)) {
    return SUPER_ADMIN_ROW
  }
  return state.rolePermissions.find(row => row.role === user.role && row.module === module) || null
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

function pettyCashTotalFloat() {
  return pettyCashApprovedTopUps().reduce((sum, topUp) => sum + Number(topUp.amount || 0), 0)
}

function pettyCashTotalSpent() {
  return pettyCashCountedExpenses().reduce((sum, expense) => sum + Number(expense.amount || 0), 0)
}

function pettyCashBalance() {
  return pettyCashTotalFloat() - pettyCashTotalSpent()
}

function pettyCashIsLow() {
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

function startTicketTimer(ticketId) {
  if (state.currentUser.role !== 'employee') return
  // Stop any timer currently running for this user before starting a new one.
  if (state.activeTimer) {
    stopTicketTimer(state.activeTimer.ticketId)
  }
  const ticket = state.tickets.find(t => t.id === ticketId)
  if (!ticket) return
  ticket.timeLogs.push({ start: new Date().toISOString(), end: null })
  if (ticket.status === 'todo') ticket.status = 'in_progress'
  state.activeTimer = { ticketId }
}

function stopTicketTimer(ticketId) {
  const ticket = state.tickets.find(t => t.id === ticketId)
  if (!ticket) return
  const openLog = ticket.timeLogs.find(l => l.end === null)
  if (openLog) openLog.end = new Date().toISOString()
  if (state.activeTimer && state.activeTimer.ticketId === ticketId) {
    state.activeTimer = null
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

function markNotificationRead(notificationId) {
  const notification = state.notifications.find(item => item.id === notificationId)
  if (!notification) return
  if (!notification.readBy) notification.readBy = []
  if (!notification.readBy.includes(state.currentUser.id)) {
    notification.readBy.push(state.currentUser.id)
  }
}

function markAllNotificationsRead() {
  visibleNotifications.value.forEach(notification => markNotificationRead(notification.id))
}

function logActivity({ module, action, details, targetId = null, targetType = '' }) {
  state.activityLogs.unshift({
    id: Date.now() + Math.floor(Math.random() * 1000),
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
}

function setCurrentUser(id, name, role, email = '') {
  state.currentUser = { id, name, role, email }
  persistCurrentUser(state.currentUser)
}

function clearCurrentUser() {
  state.currentUser = { ...guestUser }
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
    startTicketTimer,
    stopTicketTimer,
    totalHoursToday
  }
}

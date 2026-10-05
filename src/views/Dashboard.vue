<script setup>
// Role router for the dashboard: picks the component for the current role and
// hosts the two modals that more than one role opens.
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { projectService } from '../services/projectService.js'
import { ticketService } from '../services/ticketService.js'
import { employeeService } from '../services/employeeService.js'
import { attendanceService } from '../services/attendanceService.js'
import { leaveService } from '../services/leaveService.js'
import { medicalClaimService } from '../services/medicalClaimService.js'
import { payrollService } from '../services/payrollService.js'
import TicketDetailModal from '../components/TicketDetailModal.vue'
import CameraCheckInModal from '../components/CameraCheckInModal.vue'
import TeamLeadDashboard from '../components/TeamLeadDashboard.vue'
import QaDashboard from '../components/QaDashboard.vue'
import InternDashboard from '../components/InternDashboard.vue'
import EmployeeDashboard from '../components/EmployeeDashboard.vue'
import ManagerDashboard from '../components/ManagerDashboard.vue'
import HrDashboard from '../components/HrDashboard.vue'
import SuperAdminDashboard from '../components/SuperAdminDashboard.vue'
import EffectivenessSnapshot from '../components/EffectivenessSnapshot.vue'

const router = useRouter()
const {
  state, can,
  setProjects, setTickets, setEmployees,
  setAttendance, setLeaveRequests, setMedicalClaims, setSalarySlipRequests
} = useStore()

/**
 * The dashboard is a common entry point — reached by redirect after login and
 * by direct URL — so it cannot assume any other view has populated the store.
 *
 * Each fetch is independent and non-fatal: the five role dashboards read
 * different slices, and a role that lacks one permission (an employee has no
 * Employees row; HR has no Projects row) must still see the rest of its page
 * rather than an error.
 */
async function loadDashboardData() {
  const results = await Promise.allSettled([
    projectService.list(),
    ticketService.list(),
    employeeService.list(),
    // The role dashboards read all four of these — attendance for the
    // check-in widget and the absentee lists, leave and claims for the
    // approval queues, payroll for the "due" tile. They used to render from
    // seeded mock data, so the pages looked populated while fetching none of
    // it: the API could fail completely and nobody would notice.
    attendanceService.list(),
    leaveService.list(),
    medicalClaimService.list(),
    payrollService.requests()
  ])

  const [projects, tickets, employees, attendance, leave, claims, payroll] = results

  if (projects.status === 'fulfilled') setProjects(projects.value)
  if (tickets.status === 'fulfilled') setTickets(tickets.value.data)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
  if (attendance.status === 'fulfilled') setAttendance(attendance.value)
  if (leave.status === 'fulfilled') setLeaveRequests(leave.value)
  if (claims.status === 'fulfilled') setMedicalClaims(claims.value)
  if (payroll.status === 'fulfilled') setSalarySlipRequests(payroll.value)
}

onMounted(loadDashboardData)

// Modules a non-standard role can reach, driven by the permission table.
const fallbackLinks = computed(() => [
  { name: 'projects', label: 'Projects', module: 'Projects' },
  { name: 'leave-requests', label: 'Leave Requests', module: 'Leave Request' },
  { name: 'medical-claims', label: 'Medical Claims', module: 'Medical Request' },
  { name: 'payroll', label: 'Payroll', module: 'Payroll' },
  { name: 'reports', label: 'Reports', module: 'Reports' },
  { name: 'messages', label: 'Messages', module: 'Messages' },
  { name: 'company-hub', label: 'Company Hub', module: 'Company Hub' },
  { name: 'profile', label: 'My Profile', module: null }
].filter(link => !link.module || can(link.module, 'view')))

const isTeamLead = computed(() => state.currentUser.role === 'team_lead')
const isSuperAdmin = computed(() => state.currentUser.role === 'super_admin')
const isManager = computed(() => state.currentUser.role === 'manager')
const isEmployee = computed(() => state.currentUser.role === 'employee')
const isHR = computed(() => state.currentUser.role === 'hr')
const isQa = computed(() => state.currentUser.role === 'qa')
const isIntern = computed(() => state.currentUser.role === 'intern')
const dashboardTicket = ref(null)
const dashboardTicketDraft = ref(null)
const dashboardNewComment = ref('')
const dashboardNewAttachmentName = ref('')
function goToRoute(route) {
  if (!route) return
  router.push(route)
}

function openDashboardTicket(ticket) {
  dashboardTicket.value = ticket
  dashboardTicketDraft.value = {
    title: ticket.title,
    description: ticket.description || '',
    status: ticket.status,
    priority: ticket.priority,
    assigneeId: ticket.assigneeId,
    assignedById: ticket.assignedById || null,
    deadline: ticket.deadline || ''
  }
  dashboardNewComment.value = ''
  dashboardNewAttachmentName.value = ''
}

function closeDashboardTicket() {
  dashboardTicket.value = null
  dashboardTicketDraft.value = null
  dashboardNewComment.value = ''
  dashboardNewAttachmentName.value = ''
}

</script>

<template>
  <!-- Single root. The role branch plus the two modals are three root nodes
       otherwise, and a fragment root breaks patching when the modals mount
       (Vue throws "Cannot read properties of null (reading 'parentNode')"). -->
  <div>
  <!-- EMPLOYEE DASHBOARD -->
  <EmployeeDashboard v-if="isEmployee" @open-ticket="openDashboardTicket" />

  <!-- MANAGER DASHBOARD -->
  <ManagerDashboard v-else-if="isManager" />

  <!-- HR DASHBOARD -->
  <HrDashboard v-else-if="isHR" />

  <!-- SUPER ADMIN DASHBOARD -->
  <!-- Team Lead: a working engineer who also runs a squad. Top row is their
       own IC work; everything below is the modules they lead. Deliberately no
       budgets, commissions, or company-wide attendance. -->
  <TeamLeadDashboard v-else-if="isTeamLead" @open-ticket="openDashboardTicket" />

  <!-- Super admin overview. Explicitly gated: a bare v-else here served this
       full finance/company dashboard to ANY role that was not employee,
       manager, or HR. -->
  <SuperAdminDashboard v-else-if="isSuperAdmin" />

  <!-- QA owns the review column; the intern page is their own work plus who is
       responsible for them. Both emit open-ticket like the others so the shared
       modal below serves every role. -->
  <QaDashboard v-else-if="isQa" @open-ticket="openDashboardTicket" />

  <InternDashboard v-else-if="isIntern" @open-ticket="openDashboardTicket" />

  <!-- Any role outside the four built-in dashboards (e.g. a custom role added
       via Access Control) gets a neutral overview instead of falling through
       to another role's page. -->
  <div v-else class="space-y-6">
    <div>
      <div class="text-2xl font-bold text-slate-900">Welcome, {{ state.currentUser.name }}</div>
      <div class="text-sm text-slate-600 mt-1">Here is what you have access to.</div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
      <button
        v-for="link in fallbackLinks"
        :key="link.name"
        @click="goToRoute({ name: link.name })"
        class="bg-white rounded-xl p-4 border border-slate-200 shadow-sm text-left hover:border-slate-300 transition-colors"
      >
        <div class="text-sm font-bold text-slate-900">{{ link.label }}</div>
        <div class="text-xs text-slate-500 mt-1">Open {{ link.label.toLowerCase() }}</div>
      </button>
    </div>
  </div>

  <!-- Operational roles see a personal insight; Super Admin sees the company
       view. Both are intentionally preview-only until the backend owns the
       scoring policy and historical snapshots. -->
  <EffectivenessSnapshot v-if="state.currentUser?.id" :scope="isSuperAdmin ? 'organisation' : 'personal'" class="mt-5" />

  <CameraCheckInModal />

  <!-- Ticket detail, shared by the Employee and Team Lead dashboards. -->
  <TicketDetailModal :ticket="dashboardTicket" @close="closeDashboardTicket" />
  </div>
</template>

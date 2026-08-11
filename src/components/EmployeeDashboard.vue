<script setup>
import { computed } from 'vue'
import StatCard from './StatCard.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'
import { statusMeta } from '../config/ticketStatus.js'

// Employee: their own work only — tickets, hours, claims, leave, and the
// self check-in widget. No team or company-wide data.
const {
  state,
  todayKey,
  ticketSecondsToday,
  formatDuration,
  formatCurrency,
  employeeName,
  formatDate,
  formatCompanyDate,
  statusBadgeClass,
  projectName,
  projectSecondsToday,
  goToRoute,
  projectDetailRoute,
  moduleDashboardRoute,
  timesheetTitle,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  myTickets,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera
} = useDashboard()

const emit = defineEmits(['open-ticket'])

function openDashboardTicket(ticket) {
  emit('open-ticket', ticket)
}

const myOpenTickets = computed(() => myTickets.value.filter(ticket => ticket.status !== 'done'))
const myCompletedTasks = computed(() => myTickets.value.filter(ticket => ticket.status === 'done'))
const myAttendance = computed(() =>
  state.attendance.find(row => row.employeeId === state.currentUser.id && row.date === todayKey())
)
const myTotalHours = computed(() =>
  myTickets.value.reduce((sum, ticket) => sum + ticketSecondsToday(ticket), 0)
)
const myApprovedClaims = computed(() =>
  state.medicalClaims.filter(claim => claim.employeeId === state.currentUser.id && claim.status === 'approved')
)
const myApprovedClaimsTotal = computed(() =>
  myApprovedClaims.value.reduce((total, claim) => total + Number(claim.amount || 0), 0)
)
const myLeaveRequests = computed(() =>
  state.leaveRequests.filter(request => request.employeeId === state.currentUser.id)
)
const myProjects = computed(() => {
  const projectIds = new Set(myTickets.value.map(ticket => ticket.projectId))
  return state.projects.filter(project =>
    projectIds.has(project.id)
    || (project.teamMembers || []).includes(state.currentUser.id)
    || (project.modules || []).some(module => (module.teamMembers || []).includes(state.currentUser.id))
  )
})
const myModules = computed(() =>
  state.projects.flatMap(project =>
    (project.modules || [])
      .filter(module =>
        (module.teamMembers || []).includes(state.currentUser.id)
        || myTickets.value.some(ticket => ticket.moduleId === module.id)
      )
      .map(module => ({
        ...module,
        projectName: project.name,
        ticketCount: myTickets.value.filter(ticket => ticket.moduleId === module.id).length,
        openTicketCount: myOpenTickets.value.filter(ticket => ticket.moduleId === module.id).length
      }))
  )
)
const upcomingDeadlines = computed(() =>
  myOpenTickets.value
    .filter(ticket => ticket.deadline)
    .sort((a, b) => new Date(a.deadline) - new Date(b.deadline))
    .slice(0, 3)
)

// Scope labels describe which modules a "view all" link would open.
function moduleLabelFor(modules, emptyLabel) {
  if (!modules.length) return emptyLabel
  if (modules.length === 1) return modules[0].name
  return `${modules.length} modules`
}

function assignedModulesRoute() {
  return { name: 'projects', query: { view: 'modules', filter: 'assigned' } }
}

function assignedModulesScope(modules) {
  return moduleLabelFor(modules || [], 'No modules assigned')
}

function employeeTicketScope(filter) {
  const tickets = filter === 'completed' ? myCompletedTasks.value : myOpenTickets.value
  const moduleIds = new Set(tickets.map(ticket => ticket.moduleId).filter(Boolean))
  const modules = myModules.value.filter(module => moduleIds.has(module.id))
  return moduleLabelFor(modules, filter === 'completed' ? 'No completed tickets' : 'No open tickets')
}
</script>

<template>
  <div class="space-y-6 dashboard-inline-attendance">
    <div class="space-y-6">
      <!-- Header -->
      <div class="hidden">
        <div class="flex items-center justify-between">
          <div>
            <h1 class="text-3xl font-bold text-slate-900">Welcome, {{ state.currentUser.name }}! ðŸ‘‹</h1>
            <!-- <p class="text-slate-600 mt-1">Here's your work overview for today</p> -->
          </div>
          <div class="text-right">
            <div class="text-2xl font-bold text-slate-900">{{ formatCompanyDate(new Date(), { weekday: 'long', month: 'long', day: 'numeric' }) }}</div>
            <div v-if="myAttendance" class="text-emerald-600 font-medium text-sm mt-1">Checked in at {{ myAttendance.checkIn }}</div>
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <div class="text-sm font-medium text-slate-900 mb-1">Today attendance</div>
          <div class="text-xs text-slate-600" v-if="dashboardCheckedIn">Checked in at <span class="font-mono text-emerald-700">{{ dashboardCheckInTime }}</span></div>
          <div class="text-xs text-slate-600" v-else>Check in from your dashboard before starting work</div>
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

      <!-- Key Metrics. Full width: the tiles are their own row now, so the
           timesheet below is free to sit in a 50/50 split with the deadlines
           panel rather than being dragged out to three-quarter width. -->
      <!-- Five tiles, five columns — a 4-column grid always wrapped the fifth
           onto its own row. The claims total is capped under 1,000,000, so
           "PKR 999,999" is the widest value these narrower columns must hold;
           .kpi-value scales down to keep it on one line. -->
      <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3 mb-4">
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Completed Tasks</div>
          <div class="kpi-value text-slate-900">{{ myCompletedTasks.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">{{ employeeTicketScope('completed') }}</div>
        </div>
        
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Hours This Week</div>
          <div class="kpi-value text-slate-900">{{ formatDuration(myTotalHours) }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Out of 45 hours</div>
        </div>
        
        <div role="button" tabindex="0" @click="goToRoute(assignedModulesRoute(myModules))" @keydown.enter="goToRoute(assignedModulesRoute(myModules))" @keydown.space.prevent="goToRoute(assignedModulesRoute(myModules))" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Assigned Modules</div>
          <div class="kpi-value text-slate-900">{{ myModules.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">{{ assignedModulesScope(myModules) }}</div>
        </div>

        <div role="button" tabindex="0" @click="goToRoute({ name: 'medical-claims', query: { filter: 'approved' } })" @keydown.enter="goToRoute({ name: 'medical-claims', query: { filter: 'approved' } })" @keydown.space.prevent="goToRoute({ name: 'medical-claims', query: { filter: 'approved' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Claims</div>
          <div class="kpi-value text-slate-900">{{ formatCurrency(myApprovedClaimsTotal) }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">{{ myApprovedClaims.length }} approved claims</div>
        </div>

        <div role="button" tabindex="0" @click="goToRoute({ name: 'projects' })" @keydown.enter="goToRoute({ name: 'projects' })" @keydown.space.prevent="goToRoute({ name: 'projects' })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">My Projects</div>
          <div class="kpi-value text-slate-900">{{ myProjects.length }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">Active projects</div>
        </div>
      </div>

      <!-- Timesheet and deadlines share the row 60/40 — five columns with the
           chart taking three. The chart needs the extra width for seven
           day-groups; the deadlines list is a narrow stack and does not. -->
      <div class="grid grid-cols-1 xl:grid-cols-5 gap-4 mb-8 items-stretch">
        <!-- Timesheet Chart -->
        <div class="xl:col-span-3 bg-white rounded-xl p-5 border border-slate-200 shadow-sm min-w-0">
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
        </div>

        <!-- "My Projects" lives in the stat row above rather than here. -->
        <!-- Upcoming Deadlines -->
        <div class="xl:col-span-2 bg-white rounded-xl p-5 border border-slate-200 shadow-sm min-w-0 h-full">
          <h2 class="text-lg font-bold text-slate-900 mb-4">Upcoming Deadlines</h2>
          <div v-if="upcomingDeadlines.length === 0" class="text-slate-600 text-sm italic py-4">No upcoming deadlines</div>
          <div v-else class="space-y-3">
            <button
              v-for="t in upcomingDeadlines"
              :key="t.id"
              @click="openDashboardTicket(t)"
              class="w-full text-left p-3 bg-gradient-to-br from-orange-50 to-red-50 rounded-lg border border-orange-200 hover:border-orange-300 hover:shadow-sm transition-all"
            >
              <div class="font-medium text-sm text-slate-900">{{ t.title }}</div>
              <div class="text-xs text-slate-600 mt-1">{{ formatCompanyDate(t.deadline, { month: 'short', day: 'numeric' }) }}</div>
              <div class="mt-2">
                <span class="text-[11px] px-2 py-1 rounded" :class="[statusMeta[t.status].bg, statusMeta[t.status].text]">
                  {{ statusMeta[t.status].label }}
                </span>
              </div>
            </button>
          </div>
        </div>
      </div>

      <!-- Tickets and Projects Section -->
      <div class="grid grid-cols-1 gap-6">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
          <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between gap-3 mb-4">
              <h2 class="text-lg font-bold text-slate-900">Assigned Projects</h2>
              <span class="company-hub-badge company-hub-badge-info">{{ myProjects.length }} projects</span>
            </div>
            <div v-if="myProjects.length === 0" class="text-slate-600 text-sm italic py-4">No projects assigned</div>
            <div v-else class="grid grid-cols-1 gap-4">
              <button
                v-for="p in myProjects"
                :key="p.id"
                @click="goToRoute(projectDetailRoute(p))"
                class="w-full text-left p-4 bg-gradient-to-br from-emerald-50 to-teal-50 rounded-xl border border-emerald-200 shadow-sm hover:border-emerald-300 hover:shadow-md transition-all"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="font-semibold text-slate-900">{{ p.name }}</div>
                    <div class="text-sm text-slate-600 mt-1 leading-relaxed">{{ p.description || 'Project category assigned to you.' }}</div>
                  </div>
                  <div class="shrink-0 text-right">
                    <div class="text-[11px] uppercase tracking-wide text-emerald-700 font-semibold">Today</div>
                    <div class="font-mono text-sm font-semibold text-emerald-700">{{ formatDuration(projectSecondsToday(p.id)) }}</div>
                  </div>
                </div>
                <div class="mt-4 flex items-center justify-between text-xs text-slate-600">
                  <span>{{ state.tickets.filter(t => t.projectId === p.id && t.status !== 'done').length }} open tasks</span>
                  <span>{{ (p.modules || []).filter(module => (module.teamMembers || []).includes(state.currentUser.id)).length }} modules</span>
                </div>
              </button>
            </div>
          </div>

          <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between gap-3 mb-4">
              <h2 class="text-lg font-bold text-slate-900">Assigned Modules</h2>
              <span class="company-hub-badge company-hub-badge-info">{{ myModules.length }} modules</span>
            </div>
            <div v-if="myModules.length === 0" class="text-slate-600 text-sm italic py-4">No modules assigned</div>
            <div v-else class="grid grid-cols-1 gap-4">
              <button
                v-for="module in myModules"
                :key="module.id"
                @click="goToRoute(moduleDashboardRoute(module))"
                class="w-full text-left p-4 bg-white rounded-xl border border-slate-200 shadow-sm hover:border-emerald-300 hover:shadow-md transition-all"
              >
              <div class="flex items-start justify-between gap-3">
                <div>
                  <div class="font-semibold text-slate-900">{{ module.name }}</div>
                  <div class="text-sm text-slate-600 mt-1 leading-relaxed">{{ module.projectName }}</div>
                </div>
                <div class="shrink-0 text-right">
                  <div class="company-hub-badge" :class="module.status === 'delivered' ? 'company-hub-badge-success' : 'company-hub-badge-muted'">
                    {{ module.status || 'active' }}
                  </div>
                </div>
              </div>
              <div class="mt-4 flex items-center justify-between text-xs text-slate-600">
                <span>{{ module.openTicketCount }} open tasks</span>
                <span>{{ module.ticketCount }} assigned tickets</span>
              </div>
              </button>
            </div>
          </div>

          <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
            <div class="flex items-center justify-between gap-3 mb-4">
              <h2 class="text-lg font-bold text-slate-900">Leave History</h2>
              <span class="company-hub-badge company-hub-badge-info">{{ myLeaveRequests.length }} requests</span>
            </div>
            <div v-if="myLeaveRequests.length === 0" class="text-slate-600 text-sm italic py-4">No leave requests yet</div>
            <div v-else class="space-y-3">
              <button
                v-for="leave in myLeaveRequests.slice(0, 5)"
                :key="leave.id"
                @click="goToRoute({ name: 'leave-requests', query: { filter: 'mine' } })"
                class="w-full text-left p-4 bg-white rounded-xl border border-slate-200 shadow-sm hover:border-emerald-300 hover:shadow-md transition-all"
              >
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <div class="font-semibold text-slate-900">{{ leave.type }}</div>
                    <div class="text-sm text-slate-600 mt-1">{{ formatDate(leave.from) }} to {{ formatDate(leave.to) }}</div>
                  </div>
                  <span class="company-hub-badge capitalize" :class="statusBadgeClass(leave.status)">{{ leave.status }}</span>
                </div>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import PunchLedger from './PunchLedger.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'

// Manager: portfolio view of the projects they own, plus their own check-in.
// Scoped to projects where managerId matches — never company-wide.
const {
  state,
  formatDuration,
  goToRoute,
  projectDetailRoute,
  timesheetTitle,
  timesheetPeriod,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  ledgerRowsFor,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera
} = useDashboard()

const managerProjects = computed(() =>
  state.projects.filter(project => project.managerId === state.currentUser.id)
)
const managerProjectIds = computed(() => managerProjects.value.map(project => project.id))
const managerTickets = computed(() =>
  state.tickets.filter(ticket => managerProjectIds.value.includes(ticket.projectId))
)
const managerTeamMembers = computed(() => {
  const ids = new Set()
  managerProjects.value.forEach(project => {
    ;(project.teamMembers || []).forEach(id => ids.add(id))
    ;(project.modules || []).forEach(module => (module.teamMembers || []).forEach(id => ids.add(id)))
  })
  return ids.size
})
const managerOpenTickets = computed(() => managerTickets.value.filter(ticket => ticket.status !== 'done').length)
const managerCompletedTickets = computed(() => managerTickets.value.filter(ticket => ticket.status === 'done').length)

// The punch ledger shows the manager plus everyone on their projects.
const ledgerRows = computed(() => {
  const ids = new Set([state.currentUser.id])
  managerProjects.value.forEach(project => {
    ;(project.teamMembers || []).forEach(id => ids.add(id))
    ;(project.modules || []).forEach(module => (module.teamMembers || []).forEach(id => ids.add(id)))
  })
  return ledgerRowsFor(ids)
})

function managerOpenTicketRoute() {
  return { name: 'projects', query: { view: 'modules', filter: 'open' } }
}

function managerTicketScope() {
  if (!managerProjects.value.length) return 'No projects assigned'
  if (managerProjects.value.length === 1) return managerProjects.value[0].name
  return `${managerProjects.value.length} project sections`
}
</script>

<template>
  <div class="space-y-6 dashboard-inline-attendance">
    <div class="space-y-6">
      <!-- Header -->
      <div class="mb-8">
        <!-- <h2 class="text-3xl font-bold text-slate-900">Manager Dashboard ðŸ“Š</h2>
        <p class="text-slate-600 mt-1">Team performance and operations overview</p> -->
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <div class="text-sm font-medium text-slate-900 mb-1">Today attendance</div>
          <div class="text-xs text-slate-600" v-if="dashboardCheckedIn">Checked in at <span class="font-mono text-emerald-700">{{ dashboardCheckInTime }}</span></div>
          <div class="text-xs text-slate-600" v-else>Check in from your dashboard before reviewing team work</div>
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

      <!-- Key Metrics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
        <div role="button" tabindex="0" @click="goToRoute({ name: 'employees', query: { filter: 'team' } })" @keydown.enter="goToRoute({ name: 'employees', query: { filter: 'team' } })" @keydown.space.prevent="goToRoute({ name: 'employees', query: { filter: 'team' } })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Team Members</div>
          <div class="kpi-value text-slate-900">{{ managerTeamMembers }}</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute({ name: 'projects' })" @keydown.enter="goToRoute({ name: 'projects' })" @keydown.space.prevent="goToRoute({ name: 'projects' })" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Projects</div>
          <div class="kpi-value text-slate-900">{{ managerProjects.length }}</div>
        </div>
        <div class="dashboard-stat-card bg-white rounded-xl p-3 border border-slate-200 shadow-sm min-h-[84px]">
          <div class="text-slate-600 text-xs font-medium mb-1">Completed Tasks</div>
          <div class="text-2xl font-bold text-emerald-600 leading-none">{{ managerCompletedTickets }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">{{ managerTicketScope('completed') }}</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute(managerOpenTicketRoute())" @keydown.enter="goToRoute(managerOpenTicketRoute())" @keydown.space.prevent="goToRoute(managerOpenTicketRoute())" class="dashboard-click-card kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-slate-600 text-xs font-medium mb-1">Open Tickets</div>
          <div class="text-2xl font-bold text-blue-600 leading-none">{{ managerOpenTickets }}</div>
          <div class="text-emerald-600 text-[11px] mt-1">{{ managerTicketScope('open') }}</div>
        </div>
      </div>

      <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1.65fr)_minmax(340px,0.85fr)] gap-4 mb-8 items-stretch">
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm min-w-0">
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
        </div>

        <!-- Punch Ledger -->
        <div class="bg-white rounded-xl p-5 border border-slate-200 shadow-sm min-w-0">
          <div class="flex items-start justify-between gap-3 mb-4">
            <div>
              <h2 class="text-lg font-bold text-slate-900">Today's Punch Ledger</h2>
              <div class="text-xs text-slate-600 mt-1">Team check-in and checkout activity.</div>
            </div>
            <span class="company-hub-badge company-hub-badge-muted">Today</span>
          </div>
          <PunchLedger :rows="ledgerRows" />
        </div>
      </div>

      <!-- Projects Overview -->
      <div class="bg-white rounded-xl p-6 border border-slate-200 shadow-sm">
        <h2 class="text-lg font-bold text-slate-900 mb-4">Projects Overview</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <button
            v-for="p in managerProjects"
            :key="p.id"
            @click="goToRoute(projectDetailRoute(p))"
            class="w-full text-left p-4 bg-gradient-to-br from-slate-50 to-slate-100 rounded-lg border border-slate-200 hover:border-emerald-300 hover:shadow-md transition-all"
          >
            <div class="font-semibold text-slate-900">{{ p.name }}</div>
            <div class="text-sm text-slate-600 mt-1">{{ p.description }}</div>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import CardEmptyState from './CardEmptyState.vue'
import TicketTimer from './TicketTimer.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'

// An intern is an employee with someone responsible for them. The page is their
// own work plus a plain answer to "who do I ask" — which is otherwise buried in
// a project page they may not think to open.
const {
  state,
  formatDuration,
  timesheetTitle,
  timesheetPeriod,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  goToRoute,
  myTicketsRoute,
  employeeSecondsToday,
  mySupervisors,
  myModules,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera,
  attendanceError,
  checkingOut
} = useDashboard()

const emit = defineEmits(['open-ticket'])

/** Opens the shared ticket modal that Dashboard.vue renders for every role. */
function openTicket(ticket) {
  emit('open-ticket', ticket)
}

const me = computed(() => state.currentUser.employeeId ?? state.currentUser.id)

const myTickets = computed(() =>
  state.tickets.filter(ticket => ticket.assigneeId === me.value && ticket.status !== 'done')
)

// Handed over and out of their hands — worth separating, because an intern
// chasing a ticket that is already with QA is wasted worry.
const awaitingReview = computed(() =>
  state.tickets.filter(ticket => ticket.assigneeId === me.value && ticket.status === 'review')
)

const supervisors = computed(() => mySupervisors())
const modules = computed(() => myModules())
const loggedToday = computed(() => employeeSecondsToday(me.value))
</script>

<template>
  <!-- dashboard-inline-attendance: the shared CSS that grids the header
       and the punch control together and compacts the card to 260px. The
       other dashboards use it; without it the attendance panel renders
       full width and dwarfs everything under it. Child order matters —
       header first, attendance second, everything else from third. -->
  <div class="orbit-dashboard role-dashboard intern-dashboard space-y-5 dashboard-inline-attendance">
    <div class="space-y-6">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div class="text-2xl font-bold text-slate-900">Internship Dashboard</div>
          <div class="text-sm text-slate-600 mt-1">
            Your tasks and who to ask, {{ state.currentUser.name.split(' ')[0] }}.
          </div>
        </div>
      </div>

      <div class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <div class="text-sm font-medium text-slate-900 mb-1">Today attendance</div>
          <div class="text-xs text-slate-600" v-if="dashboardCheckedIn">Checked in at <span class="font-mono text-emerald-700">{{ dashboardCheckInTime }}</span></div>
          <div class="text-xs text-slate-600" v-else>Check in to start your day</div>
          <div v-if="attendanceError" class="text-xs text-rose-600 font-semibold mt-1">{{ attendanceError }}</div>
        </div>
        <button v-if="dashboardCheckedIn" @click="dashboardCheckOut" :disabled="checkingOut" class="bg-rose-100 text-rose-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-rose-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
          {{ checkingOut ? 'Checking out...' : 'Check out' }}
        </button>
        <button v-else @click="openCamera" class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors">
          Check in
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute('open'))" @keydown.enter="goToRoute(myTicketsRoute('open'))" @keydown.space.prevent="goToRoute(myTicketsRoute('open'))" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">My open tasks</div>
          <div class="kpi-value text-slate-900 mt-2">{{ myTickets.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Assigned to you</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute('review'))" @keydown.enter="goToRoute(myTicketsRoute('review'))" @keydown.space.prevent="goToRoute(myTicketsRoute('review'))" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Awaiting review</div>
          <div class="kpi-value text-slate-900 mt-2">{{ awaitingReview.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Handed over, with QA</div>
        </div>
        <!-- Moved out of the header, where it sat against the punch control and
             crowded it. Same tile QA has. -->
        <div class="orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Logged today</div>
          <div class="kpi-value text-slate-900 mt-2">{{ formatDuration(loggedToday) }}</div>
          <div class="text-xs text-slate-600 mt-1">Your own time</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute())" @keydown.enter="goToRoute(myTicketsRoute())" @keydown.space.prevent="goToRoute(myTicketsRoute())" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Your modules</div>
          <div class="kpi-value text-slate-900 mt-2">{{ modules.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Where your work sits</div>
        </div>
      </div>


      <!-- Timesheet beside "who to ask" rather than spanning the page: a
           full-width chart of one person's week leaves the row half empty and
           pushes their task list below the fold. -->
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 bg-white rounded-xl p-5 border border-slate-200 shadow-sm">
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

        <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-col">
          <div class="px-5 py-4 border-b border-slate-200 flex-shrink-0">
            <div class="text-lg font-bold text-slate-900">Who to ask</div>
            <div class="text-sm text-slate-600">The lead of each module you are on.</div>
          </div>
          <CardEmptyState
            v-if="supervisors.length === 0"
            compact
            variant="people"
            title="No module lead assigned yet"
            description="Your HR or Super Admin will add you to a module."
          />
          <div class="flex-1 overflow-y-auto">
            <div v-for="supervisor in supervisors" :key="supervisor.id" class="px-5 py-3 border-b border-slate-100 last:border-0 flex items-center justify-between gap-4">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900">{{ supervisor.name }}</div>
                <div class="text-xs text-slate-600 mt-0.5">{{ supervisor.projectName }} / {{ supervisor.moduleName }}</div>
              </div>
              <button @click="goToRoute({ name: 'messages' })" class="text-sm font-medium rounded-lg px-3 py-1.5 border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors shrink-0">
                Message
              </button>
            </div>
          </div>
        </div>
      </div>


      <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <div class="text-lg font-bold text-slate-900">My tasks</div>
          <span class="text-sm font-semibold text-slate-500">{{ myTickets.length }}</span>
        </div>
        <CardEmptyState
          v-if="myTickets.length === 0"
          compact
          variant="projects"
          title="No tasks assigned yet"
          description="Tasks assigned to you will appear here."
        />
        <div v-for="ticket in myTickets" :key="ticket.id" class="px-5 py-4 border-b border-slate-100 last:border-0 flex items-start justify-between gap-4 flex-wrap">
          <div class="min-w-0">
            <div v-if="ticket.reference" class="text-[10px] font-mono text-slate-500">{{ ticket.reference }}</div>
            <button @click="openTicket(ticket)" class="text-sm font-semibold text-slate-900 hover:text-emerald-700 transition-colors text-left">
              {{ ticket.title }}
            </button>
            <div class="text-xs text-slate-600 mt-0.5 capitalize">
              {{ ticket.status.replace('_', ' ') }}
              <span v-if="ticket.deadline"> &middot; due {{ ticket.deadline }}</span>
            </div>
          </div>
          <TicketTimer :ticket="ticket" class="shrink-0" />
        </div>
      </div>
    </div>
  </div>
</template>

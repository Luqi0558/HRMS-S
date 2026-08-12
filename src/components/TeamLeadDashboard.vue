<script setup>
import { computed } from 'vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'

// Team Lead: a working engineer who also runs a squad. The top half is their
// own IC work; everything below covers the modules they lead (module.leadId).
const {
  state,
  formatDuration,
  employeeName,
  ticketAssigneeName,
  goToRoute,
  timesheetTitle,
  timesheetPeriod,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera,
  attendanceError,
  checkingOut,
  ledModules,
  squadCapacityToday,
  squadReviewQueue,
  squadOverdueTickets,
  squadLeaveAwaitingLead,
  logActivity,
  pushNotification
} = useDashboard()

// The ticket detail modal lives in the parent so all roles share one instance.
const emit = defineEmits(['open-ticket'])

const leadModules = computed(() => ledModules())
const leadSquad = computed(() => squadCapacityToday())
const leadReviewQueue = computed(() => squadReviewQueue())
const leadOverdue = computed(() => squadOverdueTickets())
const leadMyTickets = computed(() =>
  // employeeId, not currentUser.id: tickets reference the employees table.
  state.tickets.filter(ticket => ticket.assigneeId === state.currentUser.employeeId && ticket.status !== 'done')
)
const leadMyOpenCount = computed(() => leadMyTickets.value.length)
const leadSquadLoggedToday = computed(() =>
  leadSquad.value.reduce((total, member) => total + member.seconds, 0)
)
const leadSquadCheckedIn = computed(() =>
  leadSquad.value.filter(member => member.checkIn).length
)
// Squad leave still waiting on this lead's stage-one approval.
const leadLeaveToApprove = computed(() => squadLeaveAwaitingLead())

function openDashboardTicket(ticket) {
  emit('open-ticket', ticket)
}

function approveSquadLeave(request) {
  request.managerApproval = {
    status: 'approved',
    byId: state.currentUser.id,
    byName: state.currentUser.name,
    at: new Date().toISOString()
  }
  if (!request.timeline) request.timeline = []
  request.timeline.unshift({
    status: 'team lead approved',
    note: '',
    by: state.currentUser.name,
    role: state.currentUser.role,
    at: new Date().toISOString()
  })
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
    message: `${employeeName(request.employeeId)}'s ${request.type} needs HR sign-off.`,
    type: 'leave',
    roles: ['hr', 'super_admin'],
    route: { name: 'leave-requests' }
  })
}
</script>

<template>
  <!-- dashboard-inline-attendance: the shared CSS that grids the header
       and the punch control together and compacts the card to 260px. The
       other dashboards use it; without it the attendance panel renders
       full width and dwarfs everything under it. Child order matters —
       header first, attendance second, everything else from third. -->
  <div class="space-y-6 dashboard-inline-attendance">
    <div class="space-y-6">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div class="text-2xl font-bold text-slate-900">Team Lead Dashboard</div>
          <div class="text-sm text-slate-600 mt-1">Your work and your squad, {{ state.currentUser.name.split(' ')[0] }}.</div>
        </div>
        <div class="text-right">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Squad logged today</div>
          <div class="text-lg font-extrabold text-slate-900">{{ formatDuration(leadSquadLoggedToday) }}</div>
        </div>
      </div>

      <!-- A team lead is a working engineer who comes to the office like anybody
           else. This block was simply never written for this role, so they had
           nowhere to punch in — the composable allowed it, the markup did not. -->
      <div class="bg-white border border-slate-200 rounded-xl p-5 flex items-center justify-between shadow-sm">
        <div>
          <div class="text-sm font-medium text-slate-900 mb-1">Today attendance</div>
          <div class="text-xs text-slate-600" v-if="dashboardCheckedIn">Checked in at <span class="font-mono text-emerald-700">{{ dashboardCheckInTime }}</span></div>
          <div class="text-xs text-slate-600" v-else>Check in to start your day</div>
          <div v-if="attendanceError" class="text-xs text-rose-600 font-semibold mt-1">{{ attendanceError }}</div>
        </div>
        <button
          v-if="dashboardCheckedIn"
          @click="dashboardCheckOut"
          :disabled="checkingOut"
          class="bg-rose-100 text-rose-700 text-sm font-medium rounded-lg px-4 py-2 hover:bg-rose-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
        >
          {{ checkingOut ? 'Checking out...' : 'Check out' }}
        </button>
        <button
          v-else
          @click="openCamera"
          class="bg-emerald-600 text-white text-sm font-medium rounded-lg px-4 py-2 hover:bg-emerald-700 transition-colors"
        >
          Check in
        </button>
      </div>

      <!-- My own work -->
      <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">My open tickets</div>
          <div class="kpi-value text-slate-900 mt-2">{{ leadMyOpenCount }}</div>
          <div class="text-xs text-slate-600 mt-1">Assigned to you</div>
        </div>
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Awaiting my review</div>
          <div class="kpi-value text-slate-900 mt-2">{{ leadReviewQueue.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Squad tickets in review</div>
        </div>
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Overdue</div>
          <div class="text-2xl font-extrabold mt-2" :class="leadOverdue.length ? 'text-rose-600' : 'text-slate-900'">{{ leadOverdue.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Across your modules</div>
        </div>
        <div class="kpi-tile bg-white rounded-xl border border-slate-200 shadow-sm">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Squad checked in</div>
          <div class="kpi-value text-slate-900 mt-2">{{ leadSquadCheckedIn }} / {{ leadSquad.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Today</div>
        </div>
      </div>

      <!-- Timesheet (the lead's own IC hours) sits beside the overdue list so
           "my week" and "what is slipping" read together. -->
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

        <!-- Overdue tickets. flex-col + flex-1 makes the list fill the card so
             it matches the timesheet's height instead of leaving dead space. -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-2 flex-shrink-0">
            <div class="text-sm font-bold text-slate-900">Overdue tickets</div>
            <span v-if="leadOverdue.length" class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-700">
              {{ leadOverdue.length }}
            </span>
          </div>
          <div class="flex-1 min-h-[24rem] overflow-y-auto divide-y divide-slate-200">
            <button
              v-for="ticket in leadOverdue"
              :key="ticket.id"
              @click="openDashboardTicket(ticket)"
              class="w-full text-left px-4 py-3 hover:bg-slate-50 transition-colors"
            >
              <div class="text-sm font-semibold text-slate-900">{{ ticket.title }}</div>
              <div class="text-xs text-rose-600 font-semibold mt-0.5">{{ ticketAssigneeName(ticket) }} · was due {{ ticket.deadline }}</div>
            </button>
            <div v-if="leadOverdue.length === 0" class="flex h-full items-center justify-center px-4 py-6 text-center text-sm font-semibold text-slate-500">
              Nothing overdue.
            </div>
          </div>
        </div>
      </div>

      <!-- Squad board + leave approval side by side. Both lists are sized to
           show ~6 rows before scrolling, so the two cards stay level. -->
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-2 flex-shrink-0">
            <div>
              <div class="text-sm font-bold text-slate-900">Squad today</div>
              <div class="text-xs text-slate-600 mt-1">Check-in status, live timers, and hours logged.</div>
            </div>
            <span v-if="leadSquad.length" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700 flex-shrink-0">
              {{ leadSquadCheckedIn }}/{{ leadSquad.length }}
            </span>
          </div>
          <div class="flex-1 min-h-0 h-[24rem] overflow-y-auto divide-y divide-slate-200">
            <div v-for="member in leadSquad" :key="member.id" class="flex items-center justify-between gap-3 px-4 py-3">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900 truncate">{{ member.name }}</div>
                <div class="text-xs text-slate-600 mt-0.5">
                  <span v-if="member.checkIn">Checked in {{ member.checkIn }}</span>
                  <span v-else class="text-rose-600 font-semibold">Not checked in</span>
                  <span v-if="member.activeTicket"> · Working on {{ member.activeTicket.title }}</span>
                </div>
              </div>
              <div class="text-sm font-bold text-slate-900 flex-shrink-0">{{ formatDuration(member.seconds) }}</div>
            </div>
            <div v-if="leadSquad.length === 0" class="flex h-full items-center justify-center px-4 py-6 text-center text-sm font-semibold text-slate-500">
              No squad members assigned yet.
            </div>
          </div>
        </div>

        <!-- Stage-one leave approval. The lead decides here; HR signs off after. -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          <div class="px-4 py-3 border-b border-slate-200 flex items-start justify-between gap-2 flex-shrink-0">
            <div>
              <div class="text-sm font-bold text-slate-900">Squad leave awaiting your approval</div>
              <div class="text-xs text-slate-600 mt-1">You approve first, then HR gives final sign-off.</div>
            </div>
            <span v-if="leadLeaveToApprove.length" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700 flex-shrink-0">
              {{ leadLeaveToApprove.length }}
            </span>
          </div>
          <div class="flex-1 min-h-0 h-[24rem] overflow-y-auto divide-y divide-slate-200">
            <div v-for="request in leadLeaveToApprove" :key="request.id" class="flex items-center justify-between gap-3 px-4 py-3 flex-wrap">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900">{{ employeeName(request.employeeId) }}</div>
                <div class="text-xs text-slate-600 mt-0.5">{{ request.type }} · {{ request.from }} to {{ request.to }}</div>
              </div>
              <div class="flex gap-2 flex-shrink-0">
                <button @click="approveSquadLeave(request)" class="text-xs font-bold rounded-lg px-3 py-2 bg-emerald-600 text-white hover:bg-emerald-700 transition-colors">
                  Approve
                </button>
                <button @click="goToRoute({ name: 'leave-requests' })" class="text-xs font-bold rounded-lg px-3 py-2 border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors">
                  Review
                </button>
              </div>
            </div>
            <div v-if="leadLeaveToApprove.length === 0" class="flex h-full items-center justify-center px-4 py-6 text-center text-sm font-semibold text-slate-500">
              No leave awaiting your approval.
            </div>
          </div>
        </div>
      </div>

      <!-- Review queue + modules led, side by side. Same 24rem list height as
           the pair above so all four cards line up. -->
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-2 flex-shrink-0">
            <div class="text-sm font-bold text-slate-900">Waiting for your review</div>
            <span v-if="leadReviewQueue.length" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
              {{ leadReviewQueue.length }}
            </span>
          </div>
          <div class="flex-1 min-h-0 h-[24rem] overflow-y-auto divide-y divide-slate-200">
            <button
              v-for="ticket in leadReviewQueue"
              :key="ticket.id"
              @click="openDashboardTicket(ticket)"
              class="w-full text-left px-4 py-3 hover:bg-slate-50 transition-colors"
            >
              <div class="text-sm font-semibold text-slate-900">{{ ticket.title }}</div>
              <div class="text-xs text-slate-600 mt-0.5">{{ ticketAssigneeName(ticket) }} · due {{ ticket.deadline || '—' }}</div>
            </button>
            <div v-if="leadReviewQueue.length === 0" class="flex h-full items-center justify-center px-4 py-6 text-center text-sm font-semibold text-slate-500">
              Nothing waiting on you.
            </div>
          </div>
        </div>

        <!-- Modules I lead -->
        <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden flex flex-col">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-2 flex-shrink-0">
            <div class="text-sm font-bold text-slate-900">Modules you lead</div>
            <span v-if="leadModules.length" class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700">
              {{ leadModules.length }}
            </span>
          </div>
          <div class="flex-1 min-h-0 h-[24rem] overflow-y-auto p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <button
                v-for="module in leadModules"
                :key="module.id"
                @click="goToRoute({ name: 'projects' })"
                class="text-left rounded-xl border border-slate-200 p-4 hover:border-emerald-300 hover:shadow-md transition-all"
              >
                <div class="text-sm font-semibold text-slate-900">{{ module.name }}</div>
                <div class="text-xs text-slate-600 mt-1">{{ module.projectName }}</div>
                <div class="text-xs text-slate-500 mt-3">{{ (module.teamMembers || []).length }} members</div>
              </button>
            </div>
            <div v-if="leadModules.length === 0" class="flex h-full items-center justify-center text-center text-sm font-semibold text-slate-500">
              You are not leading any modules yet.
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

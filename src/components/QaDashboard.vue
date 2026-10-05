<script setup>
import { computed, ref } from 'vue'
import CardEmptyState from './CardEmptyState.vue'
import TimesheetChart from './TimesheetChart.vue'
import { useDashboard } from '../composables/useDashboard.js'
import { ticketService } from '../services/ticketService.js'
import { ApiError } from '../services/apiClient.js'

// QA owns the Review column. Developers move work there and it waits until it
// is either passed to Done or sent back to In progress — this page is that
// queue and nothing else, so the decision is one click from the ticket.
const {
  state,
  formatDuration,
  timesheetTitle,
  timesheetPeriod,
  dailyTimesheet,
  weeklyTimesheetMaxHours,
  goToRoute,
  myTicketsRoute,
  ticketAssigneeName,
  reviewQueue,
  employeeSecondsToday,
  daysInReview,
  myModules,
  dashboardCheckedIn,
  dashboardCheckInTime,
  dashboardCheckOut,
  openCamera,
  attendanceError,
  checkingOut,
  upsertTicket,
  logActivity
} = useDashboard()

const emit = defineEmits(['open-ticket'])

/** Opens the shared ticket modal that Dashboard.vue renders for every role. */
function openTicket(ticket) {
  emit('open-ticket', ticket)
}

const queue = computed(() => reviewQueue())
const modules = computed(() => myModules())

// Overdue while still unverified: the deadline has passed and it has not been
// signed off, which is the pile that actually needs chasing.
const overdueInReview = computed(() => queue.value.filter(ticket => daysInReview(ticket) > 0))

// Their own logged time, from the same helper every other dashboard uses.
const myLoggedToday = computed(() =>
  employeeSecondsToday(state.currentUser.employeeId ?? state.currentUser.id)
)

const passedToday = computed(() =>
  state.tickets.filter(ticket =>
    ticket.status === 'done'
    && ticket.completedAt
    && new Date(ticket.completedAt).toDateString() === new Date().toDateString()
  )
)

// Which ticket is mid-decision, so its buttons can disable without freezing the
// whole queue.
const deciding = ref(null)
const decisionError = ref('')

async function decide(ticket, status, verb) {
  if (deciding.value) return

  deciding.value = ticket.id
  decisionError.value = ''

  try {
    // The status is the whole decision — the assignee is untouched, so sending
    // work back returns it to whoever wrote it.
    upsertTicket(await ticketService.update(ticket.id, { status }))
  } catch (error) {
    decisionError.value = error instanceof ApiError
      ? error.message
      : `Could not ${verb} this ticket. Please try again.`
    return
  } finally {
    deciding.value = null
  }

  logActivity({
    module: 'ticket',
    action: status === 'done' ? 'ticket verified' : 'ticket sent back',
    details: `${state.currentUser.name} ${verb} "${ticket.title}".`,
    targetId: ticket.id,
    targetType: 'ticket'
  })

}
</script>

<template>
  <!-- dashboard-inline-attendance: the shared CSS that grids the header
       and the punch control together and compacts the card to 260px. The
       other dashboards use it; without it the attendance panel renders
       full width and dwarfs everything under it. Child order matters —
       header first, attendance second, everything else from third. -->
  <div class="orbit-dashboard role-dashboard qa-dashboard space-y-5 dashboard-inline-attendance">
    <div class="space-y-6">
      <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div class="text-2xl font-bold text-slate-900">QA Dashboard</div>
          <div class="text-sm text-slate-600 mt-1">
            Work waiting on your verification, {{ state.currentUser.name.split(' ')[0] }}.
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
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute('review'))" @keydown.enter="goToRoute(myTicketsRoute('review'))" @keydown.space.prevent="goToRoute(myTicketsRoute('review'))" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Awaiting verification</div>
          <div class="kpi-value text-slate-900 mt-2">{{ queue.length }}</div>
          <div class="text-xs text-slate-600 mt-1">In the review column</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute('overdue'))" @keydown.enter="goToRoute(myTicketsRoute('overdue'))" @keydown.space.prevent="goToRoute(myTicketsRoute('overdue'))" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Overdue in review</div>
          <div class="text-2xl font-extrabold mt-2" :class="overdueInReview.length ? 'text-rose-600' : 'text-slate-900'">{{ overdueInReview.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Past their deadline</div>
        </div>
        <div role="button" tabindex="0" @click="goToRoute(myTicketsRoute('completed'))" @keydown.enter="goToRoute(myTicketsRoute('completed'))" @keydown.space.prevent="goToRoute(myTicketsRoute('completed'))" class="dashboard-click-card orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Passed today</div>
          <div class="kpi-value text-slate-900 mt-2">{{ passedToday.length }}</div>
          <div class="text-xs text-slate-600 mt-1">Signed off</div>
        </div>
        <div class="orbit-kpi kpi-tile">
          <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Logged today</div>
          <div class="kpi-value text-slate-900 mt-2">{{ formatDuration(myLoggedToday) }}</div>
          <div class="text-xs text-slate-600 mt-1">Your own time</div>
        </div>
      </div>


      <!-- Timesheet beside the modules list rather than spanning the page: a
           full-width chart of one person's week leaves the row half empty and
           pushes the review queue below the fold. -->
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
            <div class="text-lg font-bold text-slate-900">Modules you cover</div>
          </div>
          <CardEmptyState
            v-if="modules.length === 0"
            compact
            variant="projects"
            title="No modules assigned yet"
            description="Ask your Super Admin to add you to a module."
          />
          <div class="flex-1 overflow-y-auto">
            <button
              v-for="module in modules"
              :key="module.id"
              @click="goToRoute({ name: 'projects' })"
              class="w-full text-left px-5 py-3 border-b border-slate-100 last:border-0 hover:bg-slate-50 transition-colors"
            >
              <div class="text-sm font-semibold text-slate-900">{{ module.name }}</div>
              <div class="text-xs text-slate-600 mt-0.5">{{ module.projectName }}</div>
            </button>
          </div>
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex items-center justify-between">
          <div>
            <div class="text-lg font-bold text-slate-900">Review queue</div>
            <div class="text-sm text-slate-600">Pass it to done, or send it back with the work still assigned.</div>
          </div>
          <span class="text-sm font-semibold text-slate-500">{{ queue.length }}</span>
        </div>

        <div v-if="decisionError" class="px-5 py-3 bg-rose-50 border-b border-rose-200 text-sm font-semibold text-rose-700">
          {{ decisionError }}
        </div>

        <CardEmptyState
          v-if="queue.length === 0"
          compact
          variant="review"
          title="Nothing waiting for review"
          description="Work appears here when a developer moves it to review."
        />

        <div v-for="ticket in queue" :key="ticket.id" class="px-5 py-4 border-b border-slate-100 last:border-0 flex items-start justify-between gap-4 flex-wrap">
          <div class="min-w-0">
            <div v-if="ticket.reference" class="text-[10px] font-mono text-slate-500">{{ ticket.reference }}</div>
            <button @click="openTicket(ticket)" class="text-sm font-semibold text-slate-900 hover:text-emerald-700 transition-colors text-left">
              {{ ticket.title }}
            </button>
            <div class="text-xs text-slate-600 mt-0.5">
              {{ ticketAssigneeName(ticket) }}
              <span v-if="daysInReview(ticket) > 0" class="text-rose-600 font-semibold">
                &middot; {{ daysInReview(ticket) }} {{ daysInReview(ticket) === 1 ? 'day' : 'days' }} past due
              </span>
            </div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button
              @click="decide(ticket, 'in_progress', 'sent back')"
              :disabled="deciding === ticket.id"
              class="text-sm font-medium rounded-lg px-3 py-1.5 bg-amber-100 text-amber-800 hover:bg-amber-200 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              Send back
            </button>
            <button
              @click="decide(ticket, 'done', 'passed')"
              :disabled="deciding === ticket.id"
              class="text-sm font-medium rounded-lg px-3 py-1.5 bg-emerald-600 text-white hover:bg-emerald-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed"
            >
              Pass
            </button>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { leaveService } from '../services/leaveService.js'
import { employeeService } from '../services/employeeService.js'
import { useStore } from '../store/index.js'

const {
  state, can, formatDate: formatCompanyDate,
  setLeaveRequests, setEmployees
} = useStore()
const eventType = ref('all')
const currentYear = new Date().getFullYear()
const canAccess = computed(() => can('Calendar', 'view'))

const eventTypes = [
  { id: 'all', label: 'All events' },
  { id: 'birthday', label: 'Birthdays' },
  { id: 'anniversary', label: 'Anniversaries' },
  { id: 'leave', label: 'Leaves' },
  { id: 'ticket', label: 'Deadlines' }
]

const events = computed(() => {
  const birthdayEvents = state.employees
    .filter(employee => employee.status === 'active' && employee.birthday)
    .map(employee => ({
      id: `birthday-${employee.id}`,
      title: `${employee.name}'s birthday`,
      date: yearlyDate(employee.birthday),
      type: 'birthday',
      label: 'Birthday',
      meta: `${employee.department} - plan wishes or a small celebration`,
      person: employee.name
    }))

  const anniversaryEvents = state.employees
    .filter(employee => employee.status === 'active' && employee.joiningDate)
    .map(employee => ({
      id: `anniversary-${employee.id}`,
      title: `${employee.name}'s work anniversary`,
      date: yearlyDate(employee.joiningDate),
      type: 'anniversary',
      label: 'Anniversary',
      meta: `${yearsCompleted(employee.joiningDate)} year milestone - ${employee.department}`,
      person: employee.name
    }))

  const leaveEvents = state.leaveRequests.map(request => ({
    id: `leave-${request.id}`,
    title: `${employeeName(request.employeeId)} leave`,
    date: request.from,
    type: 'leave',
    label: 'Leave',
    meta: `${request.duration || 'full'} day - ${request.status || 'pending'}`
  }))

  const ticketEvents = state.tickets
    .filter(ticket => ticket.deadline)
    .map(ticket => ({
      id: `ticket-${ticket.id}`,
      title: ticket.title,
      date: ticket.deadline,
      type: 'ticket',
      label: 'Deadline',
      meta: `${ticket.status.replace('_', ' ')} - ${ticket.assignee?.name ?? employeeName(ticket.assigneeId)}`
    }))

  return [...birthdayEvents, ...anniversaryEvents, ...leaveEvents, ...ticketEvents]
    .filter(event => eventType.value === 'all' || event.type === eventType.value)
    .sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime())
})

const celebrationEvents = computed(() =>
  events.value.filter(event => ['birthday', 'anniversary'].includes(event.type))
)

const eventSummary = computed(() => ({
  birthdays: events.value.filter(event => event.type === 'birthday').length,
  anniversaries: events.value.filter(event => event.type === 'anniversary').length,
  leaves: events.value.filter(event => event.type === 'leave').length,
  deadlines: events.value.filter(event => event.type === 'ticket').length
}))

function yearlyDate(value) {
  const [, month, day] = value.split('-')
  return `${currentYear}-${month}-${day}`
}

function yearsCompleted(value) {
  const startYear = Number(value.slice(0, 4))
  const years = Math.max(1, currentYear - startYear)
  return `${years}${years === 1 ? 'st' : years === 2 ? 'nd' : years === 3 ? 'rd' : 'th'}`
}

function eventClasses(type) {
  const classes = {
    birthday: 'bg-pink-100 text-pink-700',
    anniversary: 'bg-amber-100 text-amber-700',
    leave: 'bg-blue-100 text-blue-700',
    ticket: 'bg-slate-100 text-slate-700'
  }
  return classes[type] || classes.ticket
}

function eventDotClasses(type) {
  const classes = {
    birthday: 'bg-pink-500',
    anniversary: 'bg-amber-500',
    leave: 'bg-blue-500',
    ticket: 'bg-slate-500'
  }
  return classes[type] || classes.ticket
}

function celebrationHint(event) {
  if (event.type === 'birthday') return 'Cake, team wishes, and a short birthday shoutout.'
  if (event.type === 'anniversary') return 'Recognize contribution in standup or company announcement.'
  return ''
}

function isToday(value) {
  return value === new Date().toISOString().slice(0, 10)
}

function employeeName(id) {
  return state.employees.find(employee => employee.id === Number(id))?.name || 'Unassigned'
}

function formatDate(value) {
  return formatCompanyDate(value, { weekday: 'short', month: 'short', day: 'numeric' })
}
/**
 * The calendar plots approved leave, so it has to load it. It previously read
 * whatever was already in the store — seeded mock leave, or nothing at all if
 * the user came straight here.
 */
onMounted(async () => {
  const [leave, employees] = await Promise.allSettled([
    leaveService.list(),
    employeeService.list()
  ])

  if (leave.status === 'fulfilled') setLeaveRequests(leave.value)
  if (employees.status === 'fulfilled') setEmployees(employees.value.data)
})
</script>

<template>
  <div v-if="canAccess" class="space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Birthdays, anniversaries, leaves, and ticket deadlines.</div>
      </div>
      <select v-model="eventType" class="table-filter-input min-w-[220px]">
        <option v-for="type in eventTypes" :key="type.id" :value="type.id">{{ type.label }}</option>
      </select>
    </div>

    <section class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Birthdays</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ eventSummary.birthdays }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Anniversaries</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ eventSummary.anniversaries }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Leaves</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ eventSummary.leaves }}</div>
      </div>
      <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Deadlines</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-2">{{ eventSummary.deadlines }}</div>
      </div>
    </section>

    <section v-if="celebrationEvents.length" class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
      <div class="text-sm font-bold text-slate-900">Celebration reminders</div>
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-4">
        <div v-for="event in celebrationEvents.slice(0, 4)" :key="`celebrate-${event.id}`" class="rounded-xl border border-slate-200 p-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <div class="text-sm font-bold text-slate-900">{{ event.title }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ formatDate(event.date) }}</div>
            </div>
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-bold" :class="eventClasses(event.type)">{{ event.label }}</span>
          </div>
          <div class="text-xs text-slate-600 mt-3">{{ celebrationHint(event) }}</div>
        </div>
      </div>
    </section>

    <section class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
      <div class="px-4 py-3 border-b border-slate-200">
        <div class="text-sm font-bold text-slate-900">Upcoming schedule</div>
        <div class="text-xs text-slate-600 mt-1">{{ events.length }} events found.</div>
      </div>
      <div class="divide-y divide-slate-200">
        <div v-for="event in events" :key="event.id" class="grid grid-cols-1 md:grid-cols-[160px_1fr_auto] gap-3 px-4 py-4 hover:bg-slate-50/60 transition-colors">
          <div class="flex items-center gap-2">
            <span class="h-2.5 w-2.5 rounded-full" :class="eventDotClasses(event.type)"></span>
            <div>
              <div class="text-sm font-bold text-slate-900">{{ formatDate(event.date) }}</div>
              <div v-if="isToday(event.date)" class="text-[11px] font-bold text-emerald-700 mt-0.5">Today</div>
            </div>
          </div>
          <div>
            <div class="text-sm font-bold text-slate-900">{{ event.title }}</div>
            <div class="text-xs text-slate-500 mt-1 capitalize">{{ event.meta }}</div>
          </div>
          <span class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold" :class="eventClasses(event.type)">{{ event.label }}</span>
        </div>
        <div v-if="events.length === 0" class="px-4 py-10 text-center text-sm text-slate-500">No upcoming events.</div>
      </div>
    </section>
  </div>

  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="text-lg font-semibold text-slate-900">Calendar is available for HR and Super Admin only</div>
  </div>
</template>

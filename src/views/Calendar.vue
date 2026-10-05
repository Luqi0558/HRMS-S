<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { calendarService } from '../services/calendarService.js'
import { useStore } from '../store/index.js'

const { can, formatDate: formatCompanyDate } = useStore()
const canAccess = computed(() => can('Calendar', 'view'))
const selectedType = ref('all')
const loading = ref(false)
const loadError = ref('')
const events = ref([])
const currentMonth = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1))

const eventTypes = [
  { id: 'all', label: 'All events' },
  { id: 'birthday', label: 'Birthdays' },
  { id: 'anniversary', label: 'Anniversaries' },
  { id: 'leave', label: 'Approved leave' }
]

const monthLabel = computed(() =>
  currentMonth.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' })
)

const gridStart = computed(() => {
  const date = new Date(currentMonth.value)
  date.setDate(1 - ((date.getDay() + 6) % 7))
  return date
})

const gridEnd = computed(() => {
  const date = new Date(gridStart.value)
  date.setDate(date.getDate() + 41)
  return date
})

const calendarDays = computed(() =>
  Array.from({ length: 42 }, (_, index) => {
    const date = new Date(gridStart.value)
    date.setDate(date.getDate() + index)
    return {
      date,
      key: dateKey(date),
      inCurrentMonth: date.getMonth() === currentMonth.value.getMonth()
    }
  })
)

const visibleEvents = computed(() =>
  events.value.filter(event => selectedType.value === 'all' || event.type === selectedType.value)
)

const eventSummary = computed(() => ({
  birthdays: visibleEvents.value.filter(event => event.type === 'birthday').length,
  anniversaries: visibleEvents.value.filter(event => event.type === 'anniversary').length,
  leaves: visibleEvents.value.filter(event => event.type === 'leave').length
}))

const upcomingEvents = computed(() => {
  const today = dateKey(new Date())
  return visibleEvents.value
    .filter(event => event.endDate >= today)
    .slice()
    .sort((a, b) => a.startDate.localeCompare(b.startDate) || a.title.localeCompare(b.title))
    .slice(0, 8)
})

function dateKey(date) {
  return [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0')
  ].join('-')
}

function eventsForDay(key) {
  return visibleEvents.value.filter(event => event.startDate <= key && event.endDate >= key)
}

function eventClasses(type) {
  return {
    birthday: 'bg-pink-100 text-pink-700 border-pink-200',
    anniversary: 'bg-amber-100 text-amber-700 border-amber-200',
    leave: 'bg-sky-100 text-sky-700 border-sky-200'
  }[type] || 'bg-slate-100 text-slate-700 border-slate-200'
}

function eventDotClasses(type) {
  return {
    birthday: 'bg-pink-500',
    anniversary: 'bg-amber-500',
    leave: 'bg-sky-500'
  }[type] || 'bg-slate-500'
}

function eventLabel(type) {
  return {
    birthday: 'Birthday',
    anniversary: 'Anniversary',
    leave: 'Leave'
  }[type] || type
}

function isToday(key) {
  return key === dateKey(new Date())
}

function formatDate(value) {
  return formatCompanyDate(value, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
}

function eventDateLabel(event) {
  return event.startDate === event.endDate
    ? formatDate(event.startDate)
    : formatDate(event.startDate) + ' – ' + formatDate(event.endDate)
}

function changeMonth(offset) {
  currentMonth.value = new Date(
    currentMonth.value.getFullYear(),
    currentMonth.value.getMonth() + offset,
    1
  )
}

function goToToday() {
  const today = new Date()
  currentMonth.value = new Date(today.getFullYear(), today.getMonth(), 1)
}

async function loadCalendar() {
  if (!canAccess.value) return

  loading.value = true
  loadError.value = ''
  try {
    const types = selectedType.value === 'all' ? [] : [selectedType.value]
    const data = await calendarService.events({
      from: dateKey(gridStart.value),
      to: dateKey(gridEnd.value),
      types
    })
    events.value = data.events || []
  } catch (error) {
    events.value = []
    loadError.value = error?.message || 'Unable to load the company calendar.'
  } finally {
    loading.value = false
  }
}

watch([currentMonth, selectedType], loadCalendar)
onMounted(loadCalendar)
</script>

<template>
  <div v-if="canAccess" class="orbit-page calendar-page space-y-4">
    <div class="orbit-page-header flex flex-col lg:flex-row lg:items-center justify-between gap-4">
      <div>
        <div class="text-sm text-slate-600 mt-1">Approved absences and people milestones in one company schedule.</div>
      </div>
      <div class="calendar-controls">
        <button @click="changeMonth(-1)" class="table-filter-input font-bold hover:bg-slate-50" aria-label="Previous month">‹</button>
        <button @click="goToToday" class="table-filter-input font-semibold hover:bg-slate-50">Today</button>
        <button @click="changeMonth(1)" class="table-filter-input font-bold hover:bg-slate-50" aria-label="Next month">›</button>
        <select v-model="selectedType" class="table-filter-input min-w-[180px]">
          <option v-for="type in eventTypes" :key="type.id" :value="type.id">{{ type.label }}</option>
        </select>
      </div>
    </div>

    <section class="calendar-period-row flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-extrabold text-slate-900">{{ monthLabel }}</h2>
        <p class="text-xs text-slate-500 mt-1">{{ visibleEvents.length }} events in the visible calendar range.</p>
      </div>
      <div class="flex flex-wrap items-center gap-3 text-xs font-semibold text-slate-600">
        <span v-for="type in eventTypes.slice(1)" :key="type.id" class="inline-flex items-center gap-1.5">
          <span class="h-2 w-2 rounded-full" :class="eventDotClasses(type.id)"></span>{{ type.label }}
        </span>
      </div>
    </section>

    <div v-if="loadError" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
      <div class="flex items-center justify-between gap-3">
        <span>{{ loadError }}</span>
        <button @click="loadCalendar" class="font-bold underline">Retry</button>
      </div>
    </div>

    <section class="calendar-summary-grid grid grid-cols-2 lg:grid-cols-3 gap-3">
      <div class="calendar-summary-card"><div><div class="calendar-summary-label">Birthdays</div><div class="calendar-summary-help">This month</div></div><div class="calendar-summary-value calendar-summary-value--birthday">{{ eventSummary.birthdays }}</div></div>
      <div class="calendar-summary-card"><div><div class="calendar-summary-label">Anniversaries</div><div class="calendar-summary-help">This month</div></div><div class="calendar-summary-value calendar-summary-value--anniversary">{{ eventSummary.anniversaries }}</div></div>
      <div class="calendar-summary-card"><div><div class="calendar-summary-label">Approved leave</div><div class="calendar-summary-help">Scheduled days</div></div><div class="calendar-summary-value calendar-summary-value--leave">{{ eventSummary.leaves }}</div></div>
    </section>

    <div class="calendar-workspace-grid">
      <section class="orbit-card calendar-month-shell overflow-hidden">
      <div class="calendar-weekdays border-b border-slate-200 bg-slate-50">
        <div v-for="day in ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']" :key="day" class="px-2 py-2.5 text-center text-[11px] font-bold uppercase tracking-wide text-slate-500">{{ day }}</div>
      </div>
      <div v-if="loading" class="px-4 py-20 text-center text-sm text-slate-500">Loading calendar…</div>
      <div v-else class="calendar-month-grid">
        <div v-for="day in calendarDays" :key="day.key" class="calendar-day border-b border-r border-slate-100 p-2 last:border-r-0" :class="day.inCurrentMonth ? 'bg-white' : 'bg-slate-50/70'">
          <div class="flex justify-end"><span class="inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold" :class="isToday(day.key) ? 'bg-emerald-600 text-white' : day.inCurrentMonth ? 'text-slate-700' : 'text-slate-400'">{{ day.date.getDate() }}</span></div>
          <div class="mt-1.5 space-y-1">
            <div v-for="event in eventsForDay(day.key).slice(0, 1)" :key="event.id" class="truncate rounded border px-1.5 py-0.5 text-[11px] font-semibold" :class="eventClasses(event.type)" :title="event.title + ' · ' + event.detail">{{ event.title }}</div>
            <div v-if="eventsForDay(day.key).length > 1" class="px-1 text-[11px] font-semibold text-slate-500">+{{ eventsForDay(day.key).length - 1 }} more</div>
          </div>
        </div>
      </div>
    </section>

      <section class="orbit-card calendar-upcoming-panel overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-200"><div class="text-sm font-bold text-slate-900">Upcoming schedule</div><div class="text-xs text-slate-600 mt-1">The next items that need your attention.</div></div>
        <div class="calendar-upcoming-list divide-y divide-slate-200">
          <div v-for="event in upcomingEvents" :key="event.id" class="calendar-upcoming-event px-4 py-3 hover:bg-slate-50/60 transition-colors">
            <div class="flex items-center justify-between gap-3"><div class="flex min-w-0 items-center gap-2"><span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="eventDotClasses(event.type)"></span><div class="truncate text-sm font-bold text-slate-900">{{ event.title }}</div></div><span class="inline-flex shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-bold" :class="eventClasses(event.type)">{{ eventLabel(event.type) }}</span></div>
            <div class="mt-2 flex items-start justify-between gap-3"><div class="min-w-0 text-xs text-slate-500">{{ event.detail }}</div><div class="shrink-0 text-[11px] font-semibold text-slate-600">{{ eventDateLabel(event) }}</div></div>
          </div>
          <div v-if="!loading && upcomingEvents.length === 0" class="calendar-upcoming-empty px-4 py-10 text-center text-sm text-slate-500">No upcoming events in this calendar range.</div>
        </div>
      </section>
    </div>
  </div>
  <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><div class="text-lg font-semibold text-slate-900">Calendar is not available for this role</div></div>
</template>

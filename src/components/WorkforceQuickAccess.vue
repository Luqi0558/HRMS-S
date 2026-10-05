<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

const router = useRouter()
const { state, todayKey } = useStore()
const launcher = ref(null)
const isOpen = ref(false)
const activeTab = ref('active')

const canUseQuickAccess = computed(() => ['hr', 'super_admin'].includes(state.currentUser?.role))
const todayAttendance = computed(() =>
  state.attendance.filter(row => row.date === todayKey() && row.checkIn)
)

// "Active" means currently checked in from the office. WFH is deliberately a
// separate state, rather than being counted as an indistinct active attendee.
const activeOffice = computed(() =>
  todayAttendance.value.filter(row =>
    !row.checkOut && row.workMode !== 'remote' && row.dayWorkMode !== 'mixed'
  )
)

const workingFromHome = computed(() =>
  todayAttendance.value.filter(row =>
    !row.checkOut && (row.workMode === 'remote' || row.dayWorkMode === 'mixed')
  )
)

const absentEmployees = computed(() => {
  const checkedInIds = new Set(todayAttendance.value.map(row => row.employeeId))
  return state.employees.filter(employee =>
    employee.status === 'active'
    && employee.role !== 'super_admin'
    && !checkedInIds.has(employee.id)
  )
})

const tabs = computed(() => [
  { id: 'active', label: 'Active', count: activeOffice.value.length, helper: 'Currently in office' },
  { id: 'wfh', label: 'WFH', count: workingFromHome.value.length, helper: 'Currently working remotely' },
  { id: 'absent', label: 'Absent', count: absentEmployees.value.length, helper: 'No check-in today' }
])

const visiblePeople = computed(() => {
  if (activeTab.value === 'absent') return absentEmployees.value.map(employee => ({ ...employee, type: 'employee' }))
  const rows = activeTab.value === 'wfh' ? workingFromHome.value : activeOffice.value
  return rows.map(row => ({
    ...row,
    type: 'attendance',
    employee: state.employees.find(employee => employee.id === row.employeeId)
  }))
})

const selectedTab = computed(() => tabs.value.find(tab => tab.id === activeTab.value))

function personName(item) {
  return item.employee?.name || item.employeeName || item.name || 'Unknown employee'
}

function personDepartment(item) {
  return item.employee?.department || item.department || 'No department'
}

function initials(item) {
  return personName(item).split(' ').filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase()
}

function openPerson(item) {
  isOpen.value = false
  if (item.type === 'attendance') {
    router.push({ name: 'attendance', query: { date: 'today', employeeId: item.employeeId } })
    return
  }
  router.push({ name: 'employees', query: { search: personName(item) } })
}

function openFullList() {
  isOpen.value = false
  if (activeTab.value === 'absent') {
    router.push({ name: 'employees' })
    return
  }
  router.push({
    name: 'attendance',
    query: activeTab.value === 'wfh'
      ? { date: 'today', workMode: 'remote', presence: 'active' }
      : { date: 'today', workMode: 'office', presence: 'active' }
  })
}

function closeWhenClickedOutside(event) {
  if (isOpen.value && launcher.value && !launcher.value.contains(event.target)) isOpen.value = false
}

function closeOnEscape(event) {
  if (event.key === 'Escape') isOpen.value = false
}

onMounted(() => {
  document.addEventListener('click', closeWhenClickedOutside)
  document.addEventListener('keydown', closeOnEscape)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', closeWhenClickedOutside)
  document.removeEventListener('keydown', closeOnEscape)
})
</script>

<template>
  <div v-if="canUseQuickAccess" ref="launcher" class="workforce-quick-access fixed right-5 bottom-6 z-40">
    <Transition name="workforce-quick-panel">
      <section v-if="isOpen" id="workforce-quick-panel" class="workforce-quick-panel absolute right-0 bottom-16 w-80 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" aria-label="Workforce quick access">
        <div class="border-b border-slate-200 px-4 py-3">
          <div class="flex items-center justify-between gap-3">
            <div>
              <h2 class="text-sm font-extrabold text-slate-900">Workforce now</h2>
              <p class="text-[11px] text-slate-600 mt-0.5">Today’s live check-in view</p>
            </div>
            <span class="h-2.5 w-2.5 rounded-full bg-emerald-500" aria-label="Live"></span>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-1 border-b border-slate-200 p-2">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="rounded-lg px-2 py-2 text-center transition-colors"
            :class="activeTab === tab.id ? 'bg-slate-950 text-white' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
            :aria-pressed="activeTab === tab.id"
            @click="activeTab = tab.id"
          >
            <span class="block text-sm font-extrabold leading-none">{{ tab.count }}</span>
            <span class="mt-1 block text-[10px] font-bold">{{ tab.label }}</span>
          </button>
        </div>

        <div class="px-4 py-3">
          <div class="mb-2 text-[11px] font-semibold text-slate-600">{{ selectedTab.helper }}</div>
          <div v-if="visiblePeople.length" class="max-h-64 space-y-1 overflow-y-auto pr-1">
            <button
              v-for="person in visiblePeople"
              :key="person.id"
              type="button"
              class="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left transition-colors hover:bg-slate-50"
              @click="openPerson(person)"
            >
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-extrabold text-slate-700">{{ initials(person) }}</span>
              <span class="min-w-0 flex-1">
                <span class="block truncate text-xs font-bold text-slate-900">{{ personName(person) }}</span>
                <span class="block truncate text-[10px] text-slate-600">{{ personDepartment(person) }}</span>
              </span>
              <span v-if="activeTab === 'wfh' && person.dayWorkMode === 'mixed'" class="text-[10px] font-bold text-amber-700">Mixed</span>
              <span v-else-if="activeTab === 'wfh'" class="text-[10px] font-bold text-violet-700">WFH</span>
            </button>
          </div>
          <div v-else class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-5 text-center text-xs text-slate-600">
            No employees in this group right now.
          </div>
        </div>

        <button type="button" class="workforce-quick-open-list w-full border-t border-slate-200 px-4 py-3 text-left text-xs font-bold" @click="openFullList">
          Open full list
        </button>
      </section>
    </Transition>

    <button
      type="button"
      class="workforce-quick-trigger flex h-12 w-12 items-center justify-center rounded-full shadow-lg transition-transform hover:scale-105 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:ring-offset-2"
      :aria-expanded="isOpen"
      aria-controls="workforce-quick-panel"
      aria-label="Open workforce quick access"
      @click="isOpen = !isOpen"
    >
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="9" cy="8" r="3"></circle><path d="M3.5 20a5.5 5.5 0 0 1 11 0"></path><path d="M16 11a2.5 2.5 0 1 0-1.9-4.1"></path><path d="M16 14.5a4.8 4.8 0 0 1 4.5 3.2"></path>
      </svg>
    </button>
  </div>
</template>

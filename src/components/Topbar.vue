<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

const route = useRoute()
const router = useRouter()
const {
  state,
  ticketSeconds,
  projectSecondsToday,
  formatDuration,
  formatDateTime,
  visibleNotifications,
  unreadNotifications,
  markNotificationRead,
  markAllNotificationsRead
} = useStore()
const props = defineProps({
  isNightMode: { type: Boolean, default: false }
})
const emit = defineEmits(['toggleNightMode'])
const now = ref(new Date())
const showNotifications = ref(false)
const notificationFilter = ref('all')
let clockTimer = null

const currentProject = computed(() => {
  if (route.name !== 'project-detail' && route.name !== 'project-module-detail') return null
  return state.projects.find(project => project.slug === route.params.projectSlug) || null
})

const currentProjectManager = computed(() => {
  if (!currentProject.value) return null
  return state.employees.find(employee => employee.id === currentProject.value.managerId) || null
})

const currentProjectTotal = computed(() => {
  if (!currentProject.value) return 0
  return state.tickets
    .filter(ticket => ticket.projectId === currentProject.value.id)
    .reduce((total, ticket) => total + ticketSeconds(ticket), 0)
})

const isDashboard = computed(() => route.name === 'dashboard')

// Title for the current page, shown in the topbar so each view does not have
// to repeat it in its own body.
const ROUTE_TITLES = {
  employees: 'Employees',
  attendance: 'Attendance',
  'medical-claims': 'Medical Claims',
  'leave-requests': 'Leave Requests',
  payroll: 'Payroll',
  projects: 'Projects',
  'project-detail': 'Projects',
  'project-module-detail': 'Projects',
  finance: 'Projects & Revenue',
  'petty-cash': 'Petty Cash',
  assets: 'Assets',
  calendar: 'Calendar',
  messages: 'Messages',
  'company-hub': 'Company Hub',
  profile: 'My Profile',
  'access-control': 'Access Control',
  'activity-log': 'Activity Log',
  reports: 'Reports',
  settings: 'Settings'
}

const pageTitle = computed(() => ROUTE_TITLES[route.name] || '')
// Clock follows the company timezone, not the browser's.
const headerDate = computed(() => formatDateTime(now.value, {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
  hour: 'numeric',
  minute: '2-digit'
}))

onMounted(() => {
  clockTimer = window.setInterval(() => {
    now.value = new Date()
  }, 60000)
})

onBeforeUnmount(() => {
  if (clockTimer) window.clearInterval(clockTimer)
})

function notificationTime(createdAt) {
  return formatDateTime(createdAt)
}

function openNotifications() {
  showNotifications.value = !showNotifications.value
}

function openNotification(notification) {
  markNotificationRead(notification.id)
  showNotifications.value = false
  if (notification.route) router.push(notification.route)
}

const filteredNotifications = computed(() => {
  if (notificationFilter.value === 'unread') {
    return visibleNotifications.value.filter(notification => !(notification.readBy || []).includes(state.currentUser.id))
  }
  if (notificationFilter.value === 'read') {
    return visibleNotifications.value.filter(notification => (notification.readBy || []).includes(state.currentUser.id))
  }
  return visibleNotifications.value
})

const notificationTypeCounts = computed(() => {
  return visibleNotifications.value.reduce((groups, notification) => {
    const type = notification.type || 'general'
    groups[type] = (groups[type] || 0) + 1
    return groups
  }, {})
})
</script>

<template>
  <header class="h-16 flex items-center justify-between px-5 bg-[#e6eef8]/92 backdrop-blur-xl border-b border-white shadow-[0_10px_32px_rgba(31,41,55,0.09)] flex-shrink-0">
    <div v-if="currentProject" class="topbar-project-meta">
      <div class="topbar-meta-label text-[10px] uppercase tracking-[0.08em] text-slate-500 font-bold leading-none">Project Manager</div>
      <div class="topbar-meta-value text-sm font-bold text-slate-950 leading-none mt-1">{{ currentProjectManager?.name || '-' }}</div>
    </div>
    <div v-else-if="isDashboard" class="topbar-dashboard-title">
      <div class="text-lg font-extrabold text-slate-950 leading-tight">Welcome, {{ state.currentUser.name }}!</div>
      <!-- <div class="text-xs font-medium text-slate-500 mt-0.5">Here's your work overview for today</div> -->
    </div>
    <!-- Every other page showed an empty topbar while repeating its title in
         the body. Surfacing it here removes that duplication and gives the
         page content the vertical space back. -->
    <div v-else class="topbar-dashboard-title">
      <div class="text-lg font-extrabold text-slate-950 leading-tight">{{ pageTitle }}</div>
    </div>
    <div class="flex items-center gap-5">
      <div v-if="currentProject" class="topbar-project-stats flex items-center gap-6 text-right">
        <div>
          <div class="topbar-meta-label text-[10px] uppercase tracking-[0.08em] text-slate-500 font-bold leading-none">Logged Today</div>
          <div class="topbar-meta-value text-[15px] text-slate-950 font-extrabold leading-tight mt-1">{{ formatDuration(projectSecondsToday(currentProject.id)) }}</div>
        </div>
        <div>
          <div class="topbar-meta-label text-[10px] uppercase tracking-[0.08em] text-slate-500 font-bold leading-none">Logged Total</div>
          <div class="topbar-meta-value text-[15px] text-slate-950 font-extrabold leading-tight mt-1">{{ formatDuration(currentProjectTotal) }}</div>
        </div>
      </div>
      <div v-else-if="isDashboard" class="topbar-dashboard-date text-right">
        <div class="topbar-meta-value text-base font-extrabold text-slate-950">{{ headerDate }}</div>
      </div>
      <button
        @click="emit('toggleNightMode')"
        :title="props.isNightMode ? 'Switch to day mode' : 'Switch to night mode'"
        class="theme-toggle"
      >
        <span class="theme-toggle-track" :class="{ active: props.isNightMode }">
          <span class="theme-toggle-thumb">
            <svg v-if="!props.isNightMode" viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="4" />
              <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
            </svg>
            <svg v-else viewBox="0 0 24 24" class="w-3.5 h-3.5" fill="currentColor">
              <path d="M21 14.6A8.5 8.5 0 0 1 9.4 3a7 7 0 1 0 11.6 11.6Z" />
            </svg>
          </span>
        </span>
      </button>
      <div class="relative">
        <button
          @click="openNotifications"
          title="Notifications"
          class="relative w-9 h-9 rounded-full border border-slate-200 bg-white text-slate-700 flex items-center justify-center hover:bg-slate-50 transition-colors shadow-sm"
        >
          <svg viewBox="0 0 24 24" class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          <span v-if="unreadNotifications.length" class="absolute -top-1 -right-1 min-w-5 h-5 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center">
            {{ unreadNotifications.length > 9 ? '9+' : unreadNotifications.length }}
          </span>
        </button>

        <div v-if="showNotifications" class="absolute right-0 top-12 z-50 w-[400px] max-w-[calc(100vw-2rem)] rounded-xl border border-slate-200 bg-white shadow-2xl overflow-hidden">
          <div class="px-4 py-3 border-b border-slate-200 flex items-center justify-between gap-3">
            <div>
              <div class="text-sm font-bold text-slate-900">Notifications</div>
              <div class="text-xs text-slate-500">{{ unreadNotifications.length }} unread</div>
            </div>
            <button @click="markAllNotificationsRead" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800">
              Mark all read
            </button>
          </div>
          <div class="px-4 py-3 border-b border-slate-200">
            <div class="grid grid-cols-3 gap-2">
              <button
                v-for="filter in [{ id: 'all', label: 'All' }, { id: 'unread', label: 'Unread' }, { id: 'read', label: 'Read' }]"
                :key="filter.id"
                type="button"
                @click="notificationFilter = filter.id"
                class="rounded-lg border px-3 py-2 text-xs font-bold transition-colors"
                :class="notificationFilter === filter.id ? 'bg-slate-950 text-white border-slate-950' : 'border-slate-200 text-slate-700 hover:bg-slate-50'"
              >
                {{ filter.label }}
              </button>
            </div>
            <div v-if="Object.keys(notificationTypeCounts).length" class="mt-3 flex flex-wrap gap-2">
              <span v-for="(count, type) in notificationTypeCounts" :key="type" class="company-hub-badge company-hub-badge-muted capitalize">
                {{ type }} {{ count }}
              </span>
            </div>
          </div>
          <div class="max-h-96 overflow-y-auto divide-y divide-slate-200">
            <button
              v-for="notification in filteredNotifications"
              :key="notification.id"
              @click="openNotification(notification)"
              class="w-full text-left px-4 py-3 hover:bg-slate-50 transition-colors"
            >
              <div class="flex items-start gap-3">
                <span class="mt-1 h-2 w-2 rounded-full shrink-0" :class="notification.readBy?.includes(state.currentUser.id) ? 'bg-slate-300' : 'bg-emerald-500'"></span>
                <div class="min-w-0">
                  <div class="flex items-start justify-between gap-2">
                    <div class="text-sm font-bold text-slate-900">{{ notification.title }}</div>
                    <span class="company-hub-badge company-hub-badge-info capitalize">{{ notification.type || 'general' }}</span>
                  </div>
                  <div class="text-xs text-slate-600 mt-1 leading-relaxed">{{ notification.message }}</div>
                  <div class="text-[11px] text-slate-400 mt-2">{{ notificationTime(notification.createdAt) }}</div>
                </div>
              </div>
            </button>
            <div v-if="filteredNotifications.length === 0" class="app-empty-state m-3">
              <div class="app-empty-title">No notifications here</div>
              <div class="app-empty-copy">Try another filter or wait for new activity.</div>
            </div>
          </div>
        </div>
      </div>
      <div class="w-9 h-9 rounded-full bg-slate-950 text-white flex items-center justify-center text-sm font-bold shadow-sm">
        {{ state.currentUser.name.split(' ').map(n => n[0]).join('').toUpperCase() }}
      </div>
    </div>
  </header>
</template>

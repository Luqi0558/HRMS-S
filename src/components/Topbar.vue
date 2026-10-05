<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'

import { presenceService } from '../services/presenceService.js'

const route = useRoute()
const router = useRouter()
const {
  state,
  formatDateTime,
  visibleNotifications,
  unreadNotificationCount,
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

  // The project payload already embeds its manager (eager-loaded as
  // `manager:id,name`). Resolving through state.employees instead meant this
  // read "-" for every ordinary employee, since /employees 403s for them and
  // the roster is therefore empty. The lookup remains as a fallback.
  return currentProject.value.manager
    ?? state.employees.find(employee => employee.id === currentProject.value.managerId)
    ?? null
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
  day: 'numeric'
}))
const headerTime = computed(() => formatDateTime(now.value, {
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

function notificationTypeLabel(type) {
  const labels = {
    leave: 'Leave',
    medical: 'Medical',
    assets: 'Assets',
    payroll: 'Payroll',
    tickets: 'Tasks',
    projects: 'Projects',
    attendance: 'Attendance'
  }
  return labels[type] || 'Update'
}

function notificationTypeInitial(type) {
  return notificationTypeLabel(type).slice(0, 1)
}

function isNotificationUnread(notification) {
  return !(notification.readBy || []).includes(state.currentUser.id)
}

function openNotifications() {
  showNotifications.value = !showNotifications.value
}

function toggleSidebar() {
  window.dispatchEvent(new CustomEvent('orbit:toggle-sidebar'))
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

// Your own status, shown as a dot on your avatar. Clicking toggles it — two
// states is all an HRMS needs, and 'Active' is stored as no override at all so
// it keeps following the connection.
const isAway = computed(() =>
  state.presence.chosen[state.currentUser.employeeId]?.status === 'away'
)

const myPresenceLabel = computed(() =>
  isAway.value ? 'Away — click to go active' : 'Active — click to set away'
)

async function toggleMyPresence() {
  const next = isAway.value ? null : 'away'

  // Applied locally first so the dot responds immediately; the socket tells
  // everyone else.
  state.presence.chosen[state.currentUser.employeeId] = {
    status: next ?? 'available',
    message: null
  }

  try {
    await presenceService.set(next)
  } catch {
    // Presence is cosmetic — a failed write is not worth interrupting anyone
    // over, and the next page load reads the real value.
  }
}
</script>

<template>
  <header class="orbit-topbar h-16 flex items-center justify-between px-5 flex-shrink-0">
    <div class="topbar-title-area">
      <button
        type="button"
        class="topbar-menu-button"
        aria-label="Toggle sidebar"
        @click="toggleSidebar"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
          <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
      </button>
      <div v-if="currentProject" class="topbar-project-meta">
      <div class="topbar-meta-label text-[10px] uppercase tracking-[0.08em] text-slate-500 font-bold leading-none">Project Manager</div>
      <div class="topbar-meta-value text-sm font-bold text-slate-950 leading-none mt-1">{{ currentProjectManager?.name || '-' }}</div>
    </div>
      <div v-else-if="isDashboard" class="topbar-dashboard-title">
      <div class="text-lg font-extrabold text-slate-950 leading-tight">Welcome back, {{ state.currentUser.name }}! <span aria-hidden="true">👋</span></div>
      <div class="topbar-dashboard-subtitle">Here's what's happening in Orbit HRMS today.</div>
    </div>
    <!-- Every other page showed an empty topbar while repeating its title in
         the body. Surfacing it here removes that duplication and gives the
         page content the vertical space back. -->
      <div v-else class="topbar-dashboard-title">
      <div class="text-lg font-extrabold text-slate-950 leading-tight">{{ pageTitle }}</div>
      </div>
    </div>
    <div class="flex items-center gap-4">
      <div v-if="isDashboard" class="topbar-dashboard-date">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <rect x="4" y="5" width="16" height="15" rx="2" />
          <path d="M8 3v4M16 3v4M4 10h16" />
        </svg>
        <div>
          <div class="topbar-date-label">{{ headerDate }}</div>
          <div class="topbar-time-label">at {{ headerTime }}</div>
        </div>
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
          class="orbit-icon-button relative w-9 h-9 flex items-center justify-center transition-colors"
        >
          <svg viewBox="0 0 24 24" class="w-4.5 h-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M13.73 21a2 2 0 0 1-3.46 0" />
          </svg>
          <span v-if="unreadNotificationCount" class="absolute -top-1 -right-1 min-w-5 h-5 px-1 rounded-full bg-rose-600 text-white text-[10px] font-bold flex items-center justify-center">
            {{ unreadNotificationCount > 9 ? '9+' : unreadNotificationCount }}
          </span>
        </button>

        <div v-if="showNotifications" class="notification-popover absolute right-0 top-12 z-50 w-[400px] max-w-[calc(100vw-2rem)]" role="dialog" aria-label="Notifications">
          <div class="notification-popover-header">
            <div class="notification-heading">
              <span class="notification-heading-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
                  <path d="M13.73 21a2 2 0 0 1-3.46 0" />
                </svg>
              </span>
              <div>
                <div class="notification-title">Notifications</div>
                <div class="notification-subtitle">{{ unreadNotificationCount ? `${unreadNotificationCount} unread` : 'You are all caught up' }}</div>
              </div>
            </div>
            <button
              @click="markAllNotificationsRead"
              :disabled="unreadNotificationCount === 0"
              class="notification-mark-read"
            >
              Mark all read
            </button>
          </div>
          <div class="notification-toolbar">
            <div class="notification-filter-tabs" role="tablist" aria-label="Notification state">
              <button
                v-for="filter in [{ id: 'all', label: 'All' }, { id: 'unread', label: 'Unread' }, { id: 'read', label: 'Read' }]"
                :key="filter.id"
                type="button"
                @click="notificationFilter = filter.id"
                class="notification-filter-tab"
                :class="{ 'is-active': notificationFilter === filter.id }"
              >
                {{ filter.label }}
              </button>
            </div>
          </div>
          <div class="notification-list">
            <button
              v-for="notification in filteredNotifications"
              :key="notification.id"
              @click="openNotification(notification)"
              class="notification-item"
              :class="{ 'is-unread': isNotificationUnread(notification) }"
            >
              <span class="notification-type-icon" :class="`notification-type-icon--${notification.type || 'general'}`" aria-hidden="true">
                {{ notificationTypeInitial(notification.type) }}
              </span>
              <span class="notification-item-copy">
                <span class="notification-item-topline">
                  <span class="notification-item-title">{{ notification.title }}</span>
                  <span class="notification-item-type">{{ notificationTypeLabel(notification.type) }}</span>
                </span>
                <span class="notification-item-message">{{ notification.message }}</span>
                <span class="notification-item-time">{{ notificationTime(notification.createdAt) }}</span>
              </span>
              <span v-if="isNotificationUnread(notification)" class="notification-unread-dot" aria-label="Unread"></span>
            </button>
            <div v-if="filteredNotifications.length === 0" class="app-empty-state notification-empty-state">
              <div class="app-empty-title">No notifications here</div>
              <div class="app-empty-copy">Try another filter or wait for new activity.</div>
            </div>
          </div>
        </div>
      </div>
      <!-- A stalled feed, made visible. Without this, a poll that has stopped
           working looks exactly like a quiet office — which is the failure
           this whole design exists to prevent. Only shown when the POLL is
           struggling; a dropped socket is not a problem, it just costs a few
           seconds of latency. -->
      <div
        v-if="state.syncStatus === 'reconnecting'"
        class="flex items-center gap-1.5 rounded-full bg-amber-50 border border-amber-200 px-2.5 py-1 text-[11px] font-semibold text-amber-700"
        title="Cannot reach the server — retrying. New messages may be delayed."
      >
        <span class="h-1.5 w-1.5 rounded-full bg-amber-500 animate-pulse"></span>
        Reconnecting
      </div>

      <!-- Your own presence. Two states only: Active, or Away. Active is the
           absence of an override, so it follows the connection and goes away
           by itself after five minutes idle. -->
      <button
        @click="toggleMyPresence"
        :title="myPresenceLabel"
        class="relative w-9 h-9 rounded-full bg-slate-950 text-white flex items-center justify-center text-sm font-bold shadow-sm transition-opacity hover:opacity-90"
      >
        {{ state.currentUser.name.split(' ').map(n => n[0]).join('').toUpperCase() }}
        <span
          class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white"
          :class="isAway ? 'bg-amber-400' : 'bg-emerald-500'"
        ></span>
      </button>
    </div>
  </header>
</template>

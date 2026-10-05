<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { authService } from '../services/authService.js'

const route = useRoute()
const router = useRouter()
const { state, can, scopeFor, clearCurrentUser } = useStore()

async function signOut() {
  // Revokes this device's token server-side, then clears local state. The
  // service swallows a failed call — an already-expired token is the likely
  // cause and the user must still end up signed out locally.
  await authService.logout()
  clearCurrentUser()
  router.replace({ name: 'welcome' })
}
const collapseStorageKey = 'orbit-sidebar-collapsed'
const isCollapsed = ref(false)

onMounted(() => {
  const savedValue = window.localStorage.getItem(collapseStorageKey)
  if (savedValue !== null) {
    isCollapsed.value = savedValue === 'true'
  }
  window.addEventListener('orbit:toggle-sidebar', toggleSidebar)
})

onBeforeUnmount(() => {
  window.removeEventListener('orbit:toggle-sidebar', toggleSidebar)
})

function toggleSidebar() {
  isCollapsed.value = !isCollapsed.value
}

watch(isCollapsed, value => {
  window.localStorage.setItem(collapseStorageKey, String(value))
})

// Each link declares the permission module it belongs to; visibility comes
// from state.rolePermissions via can(), so the sidebar can never drift out of
// sync with what the pages themselves allow.
const navSections = [
  {
    label: '',
    links: [
      { name: 'dashboard', label: 'Dashboard', module: 'Dashboard' }
    ]
  },
  {
    // Day-to-day work and personal requests. Leave and claims live here rather
    // than under Finance because, for the person filing them, they are
    // requests — not accounting. Sections hide when empty, so an employee sees
    // only what applies to them.
    label: 'Work',
    links: [
      { name: 'projects', label: 'Projects', module: 'Projects' },
      { name: 'leave-requests', label: 'Leave Requests', module: 'Leave Request' },
      { name: 'medical-claims', label: 'Medical Claims', module: 'Medical Request' },
      { name: 'payroll', label: 'Payroll', module: 'Payroll' }
    ]
  },
  {
    label: 'People',
    links: [
      { name: 'employees', label: 'Employees', module: 'Employees' },
      // The ledger is company-wide; leads keep their narrower Attendance
      // permission for dashboard squad status, not this navigation route.
      { name: 'attendance', label: 'Attendance', module: 'Attendance', scope: 'all' },
      { name: 'assets', label: 'Assets', module: 'Assets' }
    ]
  },
  {
    // Company money in one place. Finance (client revenue) and Petty Cash
    // (office float) remain separate modules with their own permissions and
    // scopes — this only groups them in the nav, so the super admin no longer
    // looks under two different headings for the company's money.
    label: 'Finance',
    links: [
      { name: 'finance', label: 'Projects & Revenue', module: 'Finance', action: 'finance' },
      { name: 'petty-cash', label: 'Petty Cash', module: 'Petty Cash' }
    ]
  },
  {
    label: 'Communication',
    links: [
      { name: 'calendar', label: 'Calendar', module: 'Calendar' },
      { name: 'messages', label: 'Messages', module: 'Messages' },
      { name: 'company-hub', label: 'Company Hub', module: 'Company Hub' }
    ]
  },
  {
    label: 'System',
    links: [
      { name: 'profile', label: 'My Profile' },
      { name: 'reports', label: 'Reports', module: 'Reports' },
      { name: 'activity-log', label: 'Activity Log', module: 'Activity Log' },
      { name: 'access-control', label: 'Access Control', module: 'Access Control' },
      { name: 'settings', label: 'Settings', module: 'Settings' }
    ]
  }
]

const iconPaths = {
  dashboard: '<path d="M4 11.5 12 4l8 7.5V20a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z"/>',
  employees: '<path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm-7 8a7 7 0 0 1 14 0"/>',
  attendance: '<path d="M12 2a7 7 0 1 0 7 7 7 7 0 0 0-7-7Zm0 4v4l3 2"/>',
  'medical-claims': '<path d="M12 5v14"/><path d="M5 12h14"/><path d="M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/>',
  'leave-requests': '<path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1z"/>',
  payroll: '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>',
  projects: '<path d="M4 6h16v12H4z"/><path d="M8 6V4h8v2"/><path d="M4 10h16"/>',
  finance: '<path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7H14a3.5 3.5 0 0 1 0 7H6"/>',
  'petty-cash': '<path d="M3 8h18a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a1 1 0 0 1 1-1z"/><path d="M7 8V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/><circle cx="12" cy="14" r="2.5"/>',
  assets: '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  calendar: '<path d="M7 3v4"/><path d="M17 3v4"/><path d="M4 8h16"/><path d="M5 5h14a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1z"/><path d="M8 12h3"/><path d="M13 16h3"/>',
  messages: '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8"/><path d="M8 13h5"/>',
  'company-hub': '<path d="M4 19.5V5a2 2 0 0 1 2-2h11"/><path d="M6 17h13"/><path d="M6 21h13"/><path d="M19 3v18"/>',
  profile: '<path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4z"/><path d="M5 21a7 7 0 0 1 14 0"/><path d="M17 3.5l1.5 1.5L15 8.5l-1.5.5.5-1.5z"/>',
  'activity-log': '<path d="M4 5h16"/><path d="M4 12h10"/><path d="M4 19h16"/><path d="M18 9v6"/><path d="M15 12h6"/>',
  reports: '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16v-5"/><path d="M12 16V8"/><path d="M16 16v-8"/>',
  'access-control': '<path d="M12 3 5 6v5c0 4.2 2.7 8.1 7 10 4.3-1.9 7-5.8 7-10V6z"/><path d="M9 12l2 2 4-5"/>',
  settings: '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.8 1.8 0 0 0-1.98-.36 1.8 1.8 0 0 0-1.08 1.65V21a2 2 0 0 1-4 0v-.09A1.8 1.8 0 0 0 8.8 19.26a1.8 1.8 0 0 0-1.98.36l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.8 1.8 0 0 0 4.35 15a1.8 1.8 0 0 0-1.65-1.08H2.6a2 2 0 0 1 0-4h.09A1.8 1.8 0 0 0 4.35 8a1.8 1.8 0 0 0-.36-1.98l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.8 1.8 0 0 0 8.8 4.74a1.8 1.8 0 0 0 1.08-1.65V3a2 2 0 0 1 4 0v.09a1.8 1.8 0 0 0 1.08 1.65 1.8 1.8 0 0 0 1.98-.36l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.8 1.8 0 0 0 19.4 8a1.8 1.8 0 0 0 1.65 1.08h.09a2 2 0 0 1 0 4h-.09A1.8 1.8 0 0 0 19.4 15z"/>'
}

function iconSvg(name) {
  const path = iconPaths[name] || iconPaths.dashboard
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5">${path}</svg>`
}

function canSeeLink(link) {
  // Links without a module (My Profile) are always visible.
  if (!link.module) return true

  // Match the route guard exactly. A narrower module permission can power a
  // dashboard widget (for example a lead's own squad attendance) without
  // granting access to the company-wide ledger linked from this sidebar.
  if (!can(link.module, link.action || 'view')) return false
  return link.scope !== 'all' || scopeFor(link.module) === 'all'
}

/**
 * Unread work waiting on this person, shown as a count on the nav row.
 *
 * Read through a computed rather than stamped onto navSections, which is a
 * plain constant evaluated once at module load — a count written there would
 * never update. The store keeps state.unreadCounts current: /sync recounts
 * whenever it returns a body, and opening a thread decrements it locally so
 * the badge clears without waiting for the next poll.
 */
const badgeCounts = computed(() => ({
  messages: state.unreadCounts.messages || 0
}))

function badgeFor(link) {
  return badgeCounts.value[link.name] || 0
}

/** Capped like the Topbar bell, so a long backlog cannot widen the row. */
function badgeLabel(link) {
  const count = badgeFor(link)
  return count > 9 ? '9+' : String(count)
}

/**
 * Collapsed rows show only an icon, so the count needs saying out loud —
 * otherwise the red dot is the only clue and it does not say how many.
 */
function linkTitle(link) {
  const count = badgeFor(link)

  if (!isCollapsed.value) return ''

  return count > 0 ? `${link.label} (${count} unread)` : link.label
}

const visibleSections = computed(() =>
  navSections
    .map(section => ({
      ...section,
      links: section.links.filter(canSeeLink)
    }))
    .filter(section => section.links.length > 0)
)

function isActive(name) {
  if (name === 'projects') return route.name === 'projects' || route.name === 'project-detail' || route.name === 'project-module-detail'
  return route.name === name
}
</script>

<template>
  <aside :class="isCollapsed ? 'w-20' : 'w-52'" class="orbit-sidebar flex-shrink-0 h-screen overflow-hidden flex flex-col transition-all duration-300 relative">
    <div :class="isCollapsed ? 'px-3 pt-4 pb-3' : 'px-4 pt-4 pb-3'" class="transition-all duration-300">
      <div class="sidebar-brand flex items-center gap-3">
        <!-- The mark is drawn locally so it retains its identity on the deep
             navigation rail without introducing an image dependency. -->
        <div class="orbit-brand-mark flex items-center justify-center text-white flex-shrink-0">
          <svg viewBox="0 0 32 32" class="w-7 h-7" fill="none" aria-hidden="true">
            <circle cx="16" cy="16" r="10" stroke="currentColor" stroke-width="5" stroke-linecap="round" stroke-dasharray="29 34" transform="rotate(-42 16 16)" opacity=".95" />
            <circle cx="16" cy="16" r="10" stroke="#5eead4" stroke-width="5" stroke-linecap="round" stroke-dasharray="14 49" transform="rotate(137 16 16)" />
          </svg>
        </div>
        <div v-if="!isCollapsed" class="min-w-0 transition-all duration-300">
          <div class="sidebar-brand-name font-display font-bold text-base leading-tight">Orbit</div>
          <div class="sidebar-brand-subtitle text-[11px] font-semibold uppercase tracking-[0.18em]">HRMS</div>
        </div>
      </div>
    </div>

    <div class="orbit-sidebar-divider" :class="isCollapsed ? 'mx-3' : 'mx-5'"></div>

    <nav :class="isCollapsed ? 'px-3 pt-5' : 'px-2 pt-5'" class="flex-1 min-h-0 overflow-y-auto transition-all duration-300">
      <div class="sidebar-nav-groups w-full space-y-5">
        <div v-for="section in visibleSections" :key="section.label || 'main'" class="space-y-2">
          <div
            v-if="section.label && !isCollapsed"
            class="px-3 nav-section-label"
          >
            {{ section.label }}
          </div>
          <div v-if="section.label && isCollapsed" class="mx-auto my-2 h-px w-8 bg-slate-200/80"></div>
          <div v-for="link in section.links" :key="link.name">
            <button
              @click="router.push({ name: link.name })"
              :class="{
                'nav-item-active text-white': isActive(link.name),
                'orbit-nav-item-idle': !isActive(link.name),
                'justify-center px-0': isCollapsed
              }"
              :title="linkTitle(link)"
              class="nav-item orbit-nav-item group w-full flex items-center gap-3 px-3 py-2.5 text-sm font-semibold transition-all duration-200"
            >
              <!-- Wrapper carries the colour and the badge anchor; the inner
                   span owns v-html, which would otherwise overwrite any
                   sibling markup placed inside it. -->
              <span
                class="relative flex-shrink-0 transition-colors duration-200"
                :class="isActive(link.name) ? 'text-white' : 'text-slate-400 group-hover:text-emerald-600'"
              >
                <span v-html="iconSvg(link.name)"></span>
                <!-- Collapsed: the label is gone, so the count rides the icon. -->
                <span
                  v-if="isCollapsed && badgeFor(link)"
                  class="absolute -top-1.5 -right-1.5 min-w-[16px] h-4 px-1 rounded-full bg-rose-600 text-white text-[9px] font-bold leading-none flex items-center justify-center shadow-sm"
                >{{ badgeLabel(link) }}</span>
              </span>
              <!-- Long labels stay one line during expansion, without taking
                   over the remaining flex space or changing their alignment. -->
              <span v-if="!isCollapsed" class="whitespace-nowrap">{{ link.label }}</span>
              <span
                v-if="!isCollapsed && badgeFor(link)"
                class="ml-auto min-w-[20px] h-5 px-1.5 rounded-full bg-rose-600 text-white text-[10px] font-bold leading-none flex items-center justify-center flex-shrink-0 shadow-sm"
              >{{ badgeLabel(link) }}</span>
            </button>
          </div>
        </div>
      </div>
    </nav>

    <!-- Keep account actions visually separate from navigation. The user's
         identity is intentionally shown only in the topbar to avoid a second,
         redundant profile block in the sidebar. -->
    <div :class="isCollapsed ? 'mx-3' : 'mx-4'" class="orbit-sidebar-divider"></div>

    <div :class="isCollapsed ? 'px-3 pt-3 pb-5' : 'px-4 pt-3 pb-5'" class="transition-all duration-300">
      <button
        @click="signOut"
        :title="isCollapsed ? 'Sign out' : ''"
        class="sidebar-signout w-full flex items-center gap-3 rounded-xl transition-all duration-200"
        :class="isCollapsed ? 'justify-center p-2' : 'p-2'"
      >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5 flex-shrink-0">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9" />
        </svg>
        <span v-if="!isCollapsed" class="text-sm font-semibold">Sign out</span>
      </button>
    </div>
  </aside>
</template>

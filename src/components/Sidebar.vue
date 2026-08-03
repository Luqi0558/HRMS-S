<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useStore } from '../store/index.js'
import { authService } from '../services/authService.js'

const route = useRoute()
const router = useRouter()
const { state, can, roleLabel, clearCurrentUser } = useStore()

// Guarded: the guest user has an empty name, and split(' ')[0] on '' yields
// undefined, which rendered "undefined" in the avatar.
const userInitials = computed(() => {
  const name = state.currentUser.name || ''
  const initials = name
    .split(' ')
    .filter(Boolean)
    .map(part => part[0])
    .join('')
  return initials.toUpperCase() || '?'
})

async function signOut() {
  // Revokes this device's token server-side, then clears local state. The
  // service swallows a failed call — an already-expired token is the likely
  // cause and the user must still end up signed out locally.
  await authService.logout()
  clearCurrentUser()
  router.push({ name: 'login' })
}
const collapseStorageKey = 'orbit-sidebar-collapsed'
const isCollapsed = ref(false)

onMounted(() => {
  const savedValue = window.localStorage.getItem(collapseStorageKey)
  if (savedValue !== null) {
    isCollapsed.value = savedValue === 'true'
  }
})

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
      { name: 'attendance', label: 'Attendance', module: 'Attendance' },
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
  return can(link.module, link.action || 'view')
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
  <aside :class="isCollapsed ? 'w-20' : 'w-64'" class="flex-shrink-0 h-screen overflow-hidden bg-[#e6eef8]/95 border-r border-white flex flex-col transition-all duration-300 relative shadow-[12px_0_38px_rgba(31,41,55,0.12)] backdrop-blur-xl">
    <button
      @click="isCollapsed = !isCollapsed"
      class="absolute -right-3 top-6 w-6 h-6 bg-white text-slate-600 border border-slate-200 rounded-full flex items-center justify-center hover:bg-emerald-600 hover:text-white hover:border-emerald-600 transition-all shadow-md hover:shadow-lg z-50"
      :title="isCollapsed ? 'Expand' : 'Collapse'"
    >
      <span class="text-xs font-bold">{{ isCollapsed ? '>' : '<' }}</span>
    </button>

    <div :class="isCollapsed ? 'px-3 pt-5 pb-4' : 'px-5 pt-5 pb-4'" class="transition-all duration-300">
      <div class="flex items-center gap-3">
        <!-- Brand mark in Orbit teal rather than near-black, and the wordmark
             is shown again (it was v-if="false", leaving a bare "O"). -->
        <div class="w-10 h-10 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-700 flex items-center justify-center text-white font-display font-bold text-lg flex-shrink-0 shadow-[0_6px_16px_rgba(15,139,118,0.35)]">
          O
        </div>
        <div v-if="!isCollapsed" class="min-w-0 transition-all duration-300">
          <div class="font-display font-bold text-slate-900 text-base leading-tight">Orbit</div>
          <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-emerald-700">HRMS</div>
        </div>
      </div>
    </div>

    <div class="mx-5 h-px bg-slate-300/50" :class="isCollapsed ? 'mx-3' : 'mx-5'"></div>

    <nav :class="isCollapsed ? 'px-3 pt-10' : 'px-4 pt-10'" class="flex-1 min-h-0 overflow-y-auto transition-all duration-300">
      <div class="w-full space-y-5">
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
                'text-slate-600 hover:text-emerald-800 hover:bg-white': !isActive(link.name),
                'justify-center px-0': isCollapsed
              }"
              :title="isCollapsed ? link.label : ''"
              class="nav-item group w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-all duration-200"
            >
              <span
                class="flex-shrink-0 transition-colors duration-200"
                :class="isActive(link.name) ? 'text-white' : 'text-slate-400 group-hover:text-emerald-600'"
                v-html="iconSvg(link.name)"
              ></span>
              <span v-if="!isCollapsed" class="transition-all duration-300">{{ link.label }}</span>
              <!-- Trailing dot marks the active row, as before. -->
              <span
                v-if="isActive(link.name) && !isCollapsed"
                class="ml-auto w-1.5 h-1.5 rounded-full bg-white/90 flex-shrink-0"
              ></span>
            </button>
          </div>
        </div>
      </div>
    </nav>

    <!-- Divider separates the profile from the nav list, which previously ran
         straight into it. -->
    <div :class="isCollapsed ? 'mx-3' : 'mx-4'" class="h-px bg-slate-300/50"></div>

    <div :class="isCollapsed ? 'px-3 pt-3 pb-5' : 'px-4 pt-3 pb-5'" class="transition-all duration-300">
      <button
        @click="router.push({ name: 'profile' })"
        :title="isCollapsed ? 'My Profile' : ''"
        class="sidebar-profile w-full flex items-center gap-3 rounded-xl transition-all duration-200 hover:bg-white"
        :class="isCollapsed ? 'justify-center p-0' : 'p-2'"
      >
        <div class="w-10 h-10 rounded-xl bg-white border border-slate-200 flex items-center justify-center text-emerald-700 font-display font-bold text-sm flex-shrink-0 shadow-sm">
          {{ userInitials }}
        </div>
        <div v-if="!isCollapsed" class="min-w-0 flex-1 text-left transition-all duration-300">
          <div class="text-sm font-semibold text-slate-900 truncate">{{ state.currentUser.name }}</div>
          <div class="text-[10px] text-emerald-700 font-bold uppercase tracking-[0.14em] truncate">
            {{ roleLabel(state.currentUser.role) }}
          </div>
        </div>
      </button>

      <button
        @click="signOut"
        :title="isCollapsed ? 'Sign out' : ''"
        class="sidebar-signout mt-2 w-full flex items-center gap-3 rounded-xl transition-all duration-200"
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

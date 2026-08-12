import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../store/index.js'
import { authService } from '../services/authService.js'
import { setUnauthenticatedHandler } from '../services/apiClient.js'

/**
 * Resolves a legacy numeric project URL against the LIVE store.
 *
 * These routes only exist for bookmarks from before projects moved to slugs.
 * They used to look the id up in mock data, which meant they could never match
 * a real project — every old link silently bounced to the list. Reading the
 * store at least resolves correctly whenever projects are already loaded, and
 * falls back to the list when they are not.
 */
function projectById(id) {
  const { state } = useStore()

  return state.projects.find(project => project.id === Number(id))
}

function moduleById(project, moduleId) {
  return project?.modules?.find(module => module.id === Number(moduleId))
}

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', name: 'login', component: () => import('../views/Login.vue'), meta: { blank: true } },
  { path: '/accept-invite', name: 'accept-invite', component: () => import('../views/AcceptInvite.vue'), meta: { blank: true } },
  // meta.module / meta.action mirror the gate inside each view, so a typed URL
  // can never reach a page the sidebar would have hidden. Routes with no
  // module (profile) are open to every signed-in user.
  { path: '/dashboard', name: 'dashboard', component: () => import('../views/Dashboard.vue'), meta: { module: 'Dashboard' } },
  { path: '/employees', name: 'employees', component: () => import('../views/Employees.vue'), meta: { module: 'Employees' } },
  // Oversight page: the company's punch ledger and its export. A manager or
  // lead may read their own people's attendance through the API, but this page
  // is not the way they do it.
  { path: '/attendance', name: 'attendance', component: () => import('../views/Attendance.vue'), meta: { module: 'Attendance', scope: 'all' } },
  // Employees reach this page to file a claim; reviewers to approve one.
  { path: '/medical-claims', name: 'medical-claims', component: () => import('../views/MedicalClaims.vue'), meta: { module: 'Medical Request' } },
  { path: '/leave-requests', name: 'leave-requests', component: () => import('../views/LeaveRequests.vue'), meta: { module: 'Leave Request' } },
  { path: '/payroll', name: 'payroll', component: () => import('../views/SalarySlips.vue'), meta: { module: 'Payroll' } },
  { path: '/salary-slips', redirect: { name: 'payroll' } },
  { path: '/projects', name: 'projects', component: () => import('../views/Projects.vue'), meta: { module: 'Projects' } },
  { path: '/finance', name: 'finance', component: () => import('../views/Finance.vue'), meta: { module: 'Finance', action: 'finance' } },
  { path: '/assets', name: 'assets', component: () => import('../views/Assets.vue'), meta: { module: 'Assets' } },
  { path: '/petty-cash', name: 'petty-cash', component: () => import('../views/PettyCash.vue'), meta: { module: 'Petty Cash' } },
  { path: '/calendar', name: 'calendar', component: () => import('../views/Calendar.vue'), meta: { module: 'Calendar' } },
  { path: '/messages', name: 'messages', component: () => import('../views/Messages.vue'), meta: { module: 'Messages' } },
  { path: '/company-hub', name: 'company-hub', component: () => import('../views/CompanyHub.vue'), meta: { module: 'Company Hub' } },
  { path: '/profile', name: 'profile', component: () => import('../views/Profile.vue') },
  { path: '/access-control', name: 'access-control', component: () => import('../views/AccessControl.vue'), meta: { module: 'Access Control' } },
  { path: '/activity-log', name: 'activity-log', component: () => import('../views/ActivityLog.vue'), meta: { module: 'Activity Log' } },
  { path: '/reports', name: 'reports', component: () => import('../views/Reports.vue'), meta: { module: 'Reports' } },
  { path: '/settings', name: 'settings', component: () => import('../views/Settings.vue'), meta: { module: 'Settings' } },
  {
    path: '/projects/:id(\\d+)/modules/:moduleId(\\d+)',
    redirect: to => {
      const project = projectById(to.params.id)
      const module = moduleById(project, to.params.moduleId)
      return project && module
        ? { name: 'project-module-detail', params: { projectSlug: project.slug, moduleSlug: module.slug } }
        : { name: 'projects' }
    }
  },
  {
    path: '/projects/:id(\\d+)',
    redirect: to => {
      const project = projectById(to.params.id)
      return project ? { name: 'project-detail', params: { projectSlug: project.slug } } : { name: 'projects' }
    }
  },
  { path: '/projects/:projectSlug/:moduleSlug', name: 'project-module-detail', component: () => import('../views/ProjectDetail.vue'), meta: { module: 'Projects' } },
  { path: '/projects/:projectSlug', name: 'project-detail', component: () => import('../views/ProjectDetail.vue'), meta: { module: 'Projects' } }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Global auth + permission guard.
//
// NOTE: this is UX only — it runs in the browser and can be bypassed by anyone
// editing localStorage. The Laravel API enforces the same rules server-side;
// this exists so the UI does not show a page the API would refuse.
router.beforeEach(to => {
  const { can, isAuthenticated, scopeFor, permissionsLoaded } = useStore()

  /** Whether this role holds the module across the whole company. */
  const hasCompanyWideScope = module => scopeFor(module) === 'all'

  /**
   * Somewhere they are allowed to be, rather than a dead end.
   *
   * Returning `true` when the destination IS the fallback is what stops the
   * loop: a role that may not open Profile would otherwise be redirected to
   * Profile, refused again, and redirected again until the router gave up.
   */
  const fallbackFrom = to => {
    if (can('Dashboard', 'view')) {
      return to.name === 'dashboard' ? true : { name: 'dashboard' }
    }
    return to.name === 'profile' ? true : { name: 'profile' }
  }

  // Blank routes (login, accept-invite) are the way IN, so they must stay
  // reachable while signed out — but a signed-in user landing on /login has no
  // reason to see it again.
  if (to.meta.blank) {
    if (to.name === 'login' && isAuthenticated() && authService.isAuthenticated()) {
      return { name: 'dashboard' }
    }
    return true
  }

  // Authentication before permissions. This check did not exist: the store
  // defaulted to a hardcoded manager, so typing /dashboard in a fresh browser
  // signed you in as someone else entirely.
  //
  // Both halves must agree — a stored user without a token cannot call the API,
  // and a token without a stored user leaves the UI with no name or role.
  if (!isAuthenticated() || !authService.isAuthenticated()) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  const module = to.meta.module
  if (!module) return true

  // Until the API's grid arrives, can() is answering from the seeded fallback.
  // Refusing a page on a guess is bad enough; the guess also decides where to
  // send them instead, so a role the seed does not cover was bounced to a page
  // the same guess refused — a redirect loop the router then aborted.
  //
  // Letting navigation through here costs nothing: every endpoint behind the
  // page enforces the real permissions, and the guard re-runs once the grid
  // loads.
  if (!permissionsLoaded.value) return true

  // Some pages are company-wide oversight rather than a view of your own
  // corner: /attendance is the whole punch ledger with an export. Holding the
  // module at a narrower scope is enough to read your own team through the
  // API, but not enough to be handed the register for everybody — so those
  // routes ask for scope 'all' explicitly.
  if (to.meta.scope === 'all' && !hasCompanyWideScope(module)) {
    return fallbackFrom(to)
  }

  if (!can(module, to.meta.action || 'view')) {
    return fallbackFrom(to)
  }
  return true
})

// When the API rejects our token mid-session (expired, or revoked from another
// device), drop the local session and return to login rather than leaving the
// user on a page whose data will never load. Registered here rather than in the
// client to keep apiClient free of a router import.
setUnauthenticatedHandler(() => {
  const { clearCurrentUser } = useStore()
  clearCurrentUser()

  if (router.currentRoute.value.name !== 'login') {
    router.push({
      name: 'login',
      query: { redirect: router.currentRoute.value.fullPath, expired: '1' }
    })
  }
})

export default router

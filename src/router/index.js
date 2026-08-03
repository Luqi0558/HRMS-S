import { createRouter, createWebHistory } from 'vue-router'
import { projects } from '../data/mock.js'
import { useStore } from '../store/index.js'

function projectById(id) {
  return projects.find(project => project.id === Number(id))
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
  { path: '/attendance', name: 'attendance', component: () => import('../views/Attendance.vue'), meta: { module: 'Attendance' } },
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

// Global permission guard. Reads the same rolePermissions table as the pages
// and the sidebar, so a typed URL cannot bypass a hidden nav link.
//
// NOTE: this is UX only — it runs in the browser and can be bypassed by anyone
// editing localStorage. The Laravel API must enforce the same rules.
router.beforeEach(to => {
  if (to.meta.blank) return true

  const { can } = useStore()
  const module = to.meta.module
  if (!module) return true

  if (!can(module, to.meta.action || 'view')) {
    // Send them somewhere they are allowed to be rather than a dead end.
    return can('Dashboard', 'view') ? { name: 'dashboard' } : { name: 'profile' }
  }
  return true
})

export default router

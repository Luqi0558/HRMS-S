import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'

/**
 * Every view mounts and renders without throwing.
 *
 * This exists because `vite build` does not catch the one class of bug that
 * has actually reached the browser here: a template referencing something the
 * script never defines. The template compiles, the build passes, and the page
 * throws on render. That shipped once — /messages referenced a `threadItems`
 * computed that a later edit had removed — and a user found it, not the build.
 *
 * Deliberately mounted against EMPTY API responses. The store no longer seeds
 * any mock data, so an empty database is the state a fresh install is in, and
 * a view that only renders when rows happen to exist is broken for every new
 * customer.
 */

const VIEWS = import.meta.glob('../src/views/*.vue')

/** A router with every real route, so route-dependent views resolve. */
function testRouter() {
  return createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', name: 'dashboard', component: { template: '<div />' } },
      { path: '/projects', name: 'projects', component: { template: '<div />' } },
      { path: '/projects/:projectSlug', name: 'project-detail', component: { template: '<div />' } },
      {
        path: '/projects/:projectSlug/modules/:moduleSlug',
        name: 'project-module-detail',
        component: { template: '<div />' }
      },
      { path: '/attendance', name: 'attendance', component: { template: '<div />' } },
      { path: '/leave-requests', name: 'leave-requests', component: { template: '<div />' } },
      { path: '/medical-claims', name: 'medical-claims', component: { template: '<div />' } },
      { path: '/payroll', name: 'payroll', component: { template: '<div />' } },
      { path: '/petty-cash', name: 'petty-cash', component: { template: '<div />' } },
      { path: '/messages', name: 'messages', component: { template: '<div />' } },
      { path: '/company-hub', name: 'company-hub', component: { template: '<div />' } },
      { path: '/assets', name: 'assets', component: { template: '<div />' } },
      { path: '/calendar', name: 'calendar', component: { template: '<div />' } },
      { path: '/reports', name: 'reports', component: { template: '<div />' } },
      { path: '/settings', name: 'settings', component: { template: '<div />' } },
      { path: '/activity-log', name: 'activity-log', component: { template: '<div />' } },
      { path: '/employees', name: 'employees', component: { template: '<div />' } },
      { path: '/profile', name: 'profile', component: { template: '<div />' } },
      { path: '/finance', name: 'finance', component: { template: '<div />' } },
      { path: '/login', name: 'login', component: { template: '<div />' } },
      { path: '/accept-invite', name: 'accept-invite', component: { template: '<div />' } }
    ]
  })
}

/**
 * Signed in as a super admin.
 *
 * The role with the widest reach: it renders the most branches, so it
 * exercises the most template. A view broken only for one narrower role would
 * still slip through, which is what the per-role test below is for.
 */
function signIn(role = 'super_admin') {
  const { state, setCurrentUser } = useStore()
  setCurrentUser(1, 'Test User', role, 'test@example.com', 1)
  state.rolePermissions = state.rolePermissions.map(row => ({ ...row }))
}

describe('every view renders', () => {
  beforeEach(() => {
    // Vue warns rather than throws for an undefined template property, so the
    // warning has to be promoted to a failure or the exact bug this test
    // exists to catch would pass silently.
    vi.spyOn(console, 'warn').mockImplementation(message => {
      if (typeof message === 'string' && message.includes('not defined on instance')) {
        throw new Error(message)
      }
    })
    signIn()
  })

  for (const [path, loader] of Object.entries(VIEWS)) {
    const name = path.split('/').pop().replace('.vue', '')

    it(`${name} mounts without throwing`, async () => {
      const module = await loader()
      const router = testRouter()

      // Views read route params; push before mounting so they are present.
      router.push('/')
      await router.isReady()

      const wrapper = mount(module.default, {
        global: {
          plugins: [router],
          // Child components are not under test here — each has its own entry
          // in this suite via the glob if it lives in views/.
          stubs: { teleport: true }
        }
      })

      // Let onMounted fetches resolve and their .then handlers run: a render
      // that only throws AFTER data arrives is the interesting failure.
      await new Promise(resolve => setTimeout(resolve, 0))
      await wrapper.vm.$nextTick()

      expect(wrapper.exists()).toBe(true)
      wrapper.unmount()
    })
  }
})

describe('views render for every role', () => {
  // A view can be correct for super admin and broken for an employee: the two
  // take different template branches, and employees are the majority of users.
  const ROLES = ['employee', 'manager', 'team_lead', 'hr']

  // The pages most roles actually open day to day.
  const EVERYDAY = ['Dashboard', 'Messages', 'LeaveRequests', 'MedicalClaims', 'Profile', 'Attendance']

  for (const role of ROLES) {
    for (const name of EVERYDAY) {
      it(`${name} mounts as ${role}`, async () => {
        vi.spyOn(console, 'warn').mockImplementation(message => {
          if (typeof message === 'string' && message.includes('not defined on instance')) {
            throw new Error(message)
          }
        })

        signIn(role)

        const loader = VIEWS[`../src/views/${name}.vue`]
        expect(loader, `${name}.vue not found`).toBeTruthy()

        const module = await loader()
        const router = testRouter()
        router.push('/')
        await router.isReady()

        const wrapper = mount(module.default, {
          global: { plugins: [router], stubs: { teleport: true } }
        })

        await new Promise(resolve => setTimeout(resolve, 0))
        await wrapper.vm.$nextTick()

        expect(wrapper.exists()).toBe(true)
        wrapper.unmount()
      })
    }
  }
})

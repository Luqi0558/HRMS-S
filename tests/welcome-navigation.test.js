import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import router from '../src/router/index.js'
import Welcome from '../src/views/Welcome.vue'
import Sidebar from '../src/components/Sidebar.vue'
import { useStore } from '../src/store/index.js'
import { authService } from '../src/services/authService.js'

vi.mock('../src/services/authService.js', () => ({
  authService: {
    isAuthenticated: vi.fn(() => false),
    logout: vi.fn(async () => { authService.isAuthenticated.mockReturnValue(false) })
  }
}))

describe('welcome and login navigation', () => {
  beforeEach(async () => {
    useStore().clearCurrentUser()
    authService.isAuthenticated.mockReturnValue(false)
    await router.replace('/')
    await router.isReady()
  })

  it('opens the public welcome page on a first visit', () => {
    expect(router.currentRoute.value.name).toBe('welcome')
    const wrapper = mount(Welcome, { global: { plugins: [router] } })
    expect(wrapper.get('[role="status"]').text()).toContain('BETA')
    expect(wrapper.get('.welcome-primary').attributes('href')).toBe('/login')
    wrapper.unmount()
  })

  it('carries a protected destination through the welcome login link', async () => {
    await router.push('/attendance?date=2026-10-01')
    expect(router.currentRoute.value.name).toBe('welcome')
    expect(router.currentRoute.value.query.redirect).toBe('/attendance?date=2026-10-01')
    const wrapper = mount(Welcome, { global: { plugins: [router] } })
    await wrapper.get('.welcome-primary').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('login'))
    expect(router.currentRoute.value.query.redirect).toBe('/attendance?date=2026-10-01')
    wrapper.unmount()
  })

  it('offers authenticated visitors their dashboard and keeps login redirect working', async () => {
    useStore().setCurrentUser(1, 'Test Admin', 'super_admin', 'admin@test.example', 1)
    authService.isAuthenticated.mockReturnValue(true)
    const wrapper = mount(Welcome, { global: { plugins: [router] } })
    expect(wrapper.get('.welcome-primary').attributes('href')).toBe('/dashboard')
    await router.push('/login')
    expect(router.currentRoute.value.name).toBe('dashboard')
    wrapper.unmount()
  })

  it('renders the saved dark theme and allows the user to switch it', async () => {
    const wrapper = mount(Welcome, { props: { isNightMode: true }, global: { plugins: [router] } })
    expect(wrapper.classes()).toContain('welcome-page--dark')
    await wrapper.get('.welcome-theme-toggle').trigger('click')
    expect(wrapper.emitted('toggle-night-mode')).toHaveLength(1)
    wrapper.unmount()
  })

  it('returns to the welcome page and clears the user after clicking Sign out', async () => {
    useStore().setCurrentUser(1, 'Test Admin', 'super_admin', 'admin@test.example', 1)
    authService.isAuthenticated.mockReturnValue(true)
    await router.push('/dashboard')
    const wrapper = mount(Sidebar, { global: { plugins: [router] } })
    await wrapper.get('.sidebar-signout').trigger('click')
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('welcome'))
    expect(useStore().isAuthenticated()).toBe(false)
    expect(authService.logout).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps an external redirect out of the login link', async () => {
    await router.push('/?redirect=https://example.com')
    const wrapper = mount(Welcome, { global: { plugins: [router] } })
    expect(wrapper.get('.welcome-primary').attributes('href')).toBe('/login')
    wrapper.unmount()
  })
})

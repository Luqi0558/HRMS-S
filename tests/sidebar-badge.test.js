import './setup.js'

import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import Sidebar from '../src/components/Sidebar.vue'

/**
 * The unread count on the Messages nav row.
 *
 * Worth testing rather than eyeballing because the number comes from
 * state.unreadCounts, which /sync overwrites wholesale — a badge wired to the
 * wrong field renders 0 forever and looks exactly like "no new messages".
 */
describe('sidebar unread badge', () => {
  function testRouter() {
    return createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: { template: '<div />' } },
        { path: '/messages', name: 'messages', component: { template: '<div />' } },
        { path: '/profile', name: 'profile', component: { template: '<div />' } },
        { path: '/login', name: 'login', component: { template: '<div />' } }
      ]
    })
  }

  async function mountSidebar({ collapsed = false } = {}) {
    window.localStorage.setItem('orbit-sidebar-collapsed', String(collapsed))

    const router = testRouter()
    router.push('/')
    await router.isReady()

    const wrapper = mount(Sidebar, { global: { plugins: [router] } })
    await wrapper.vm.$nextTick()

    return wrapper
  }

  /** The Messages row, found by its label rather than by position. */
  function messagesRow(wrapper) {
    return wrapper.findAll('button').find(button => button.text().includes('Messages'))
  }

  beforeEach(() => {
    const { state, setCurrentUser } = useStore()
    setCurrentUser(1, 'Test User', 'super_admin', 'test@example.com', 1)
    state.unreadCounts = { messages: 0, notifications: 0 }
  })

  it('shows no badge when nothing is unread', async () => {
    const wrapper = await mountSidebar()

    expect(messagesRow(wrapper).find('.bg-rose-600').exists()).toBe(false)
    wrapper.unmount()
  })

  it('shows the count when messages are unread', async () => {
    const { state } = useStore()
    state.unreadCounts.messages = 3

    const wrapper = await mountSidebar()
    const badge = messagesRow(wrapper).find('.bg-rose-600')

    expect(badge.exists()).toBe(true)
    expect(badge.text()).toBe('3')
    wrapper.unmount()
  })

  it('caps a long backlog so the row cannot widen', async () => {
    const { state } = useStore()
    state.unreadCounts.messages = 42

    const wrapper = await mountSidebar()

    expect(messagesRow(wrapper).find('.bg-rose-600').text()).toBe('9+')
    wrapper.unmount()
  })

  it('clears as messages are read, without a reload', async () => {
    const { state } = useStore()
    state.unreadCounts.messages = 1

    const wrapper = await mountSidebar()
    expect(messagesRow(wrapper).find('.bg-rose-600').exists()).toBe(true)

    // What opening the thread does: the store decrements, and the badge must
    // follow without another /sync.
    state.unreadCounts.messages = 0
    await wrapper.vm.$nextTick()

    expect(messagesRow(wrapper).find('.bg-rose-600').exists()).toBe(false)
    wrapper.unmount()
  })

  it('still shows the count when the sidebar is collapsed', async () => {
    const { state } = useStore()
    state.unreadCounts.messages = 2

    const wrapper = await mountSidebar({ collapsed: true })

    // Collapsed rows have no label, so the row is found by its route instead.
    const badges = wrapper.findAll('.bg-rose-600')
    expect(badges.length).toBe(1)
    expect(badges[0].text()).toBe('2')
    wrapper.unmount()
  })

  it('does not badge a nav row that has no unread concept', async () => {
    const { state } = useStore()
    state.unreadCounts.messages = 5

    const wrapper = await mountSidebar()
    const dashboard = wrapper.findAll('button').find(button => button.text().includes('Dashboard'))

    expect(dashboard.find('.bg-rose-600').exists()).toBe(false)
    wrapper.unmount()
  })
})

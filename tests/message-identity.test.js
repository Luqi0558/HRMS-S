import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'
import Messages from '../src/views/Messages.vue'

/**
 * Messages are addressed by EMPLOYEE id, never user id.
 *
 * message_recipients.employee_id is what the API delivers against, so every
 * client-side idea of "me" in a conversation has to be the same value. The
 * store used to write user ids into readBy and onto optimistic rows while the
 * view compared employee ids.
 *
 * Every test here signs in with the two deliberately DIFFERENT, because in the
 * seeded demo data every employee happens to share its user's id — which is
 * the only reason these bugs were invisible. The first real onboarding, where
 * a user exists without an employee row or vice versa, separates them.
 */
describe('message identity uses employee ids', () => {
  const USER_ID = 99
  const MY_EMPLOYEE_ID = 1
  const AYESHA = 2

  function signIn() {
    const { state, setCurrentUser } = useStore()
    setCurrentUser(USER_ID, 'Me', 'employee', 'me@test.com', MY_EMPLOYEE_ID)
    state.messages = []
    state.unreadCounts = { messages: 0, notifications: 0 }
    return useStore()
  }

  /** A message from Ayesha to me, already read on the server. */
  function readMessage() {
    return {
      id: 10,
      senderId: AYESHA,
      senderName: 'Ayesha Khan',
      audience: 'Direct message',
      body: 'Already read',
      createdAt: '2026-08-10T09:00:00+00:00',
      recipientIds: [MY_EMPLOYEE_ID],
      isRead: true
    }
  }

  beforeEach(() => {
    vi.mocked(apiClient.get).mockImplementation(path => {
      if (path.includes('audiences')) return Promise.resolve({ audiences: [] })
      if (path.startsWith('/messages')) {
        return Promise.resolve({
          data: [readMessage()],
          contacts: [{ id: AYESHA, name: 'Ayesha Khan', role: 'employee' }],
          hasMore: false,
          oldestId: 10
        })
      }
      return Promise.resolve({ data: [], meta: {} })
    })
  })

  it('records a read message against the employee id, not the user id', async () => {
    const { state, setMessages } = signIn()
    setMessages([readMessage()])

    expect(state.messages[0].readBy).toContain(MY_EMPLOYEE_ID)
    expect(state.messages[0].readBy).not.toContain(USER_ID)
  })

  it('stamps an optimistic message with the employee id', async () => {
    const { state, addOptimisticMessage } = signIn()

    addOptimisticMessage({
      body: 'Typed just now',
      subject: 'Direct message',
      audience: 'Direct message',
      recipientIds: [AYESHA]
    })

    // The thread selects rows by `senderId === my employee id`; a user id here
    // left the message out of the conversation it was typed into.
    expect(state.messages[0].senderId).toBe(MY_EMPLOYEE_ID)
  })

  it('decrements the unread count when a message is marked read', async () => {
    const { state, setMessages, markMessageReadLocally } = signIn()

    setMessages([{ ...readMessage(), isRead: false }])
    state.unreadCounts.messages = 1

    markMessageReadLocally(10)

    expect(state.unreadCounts.messages).toBe(0)
  })

  it('does not decrement twice for the same message', async () => {
    const { state, setMessages, markMessageReadLocally } = signIn()

    setMessages([{ ...readMessage(), isRead: false }])
    state.unreadCounts.messages = 2

    markMessageReadLocally(10)
    markMessageReadLocally(10)

    expect(state.unreadCounts.messages).toBe(1)
  })

  it('does not show an already-read message as unread in the list', async () => {
    signIn()

    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: { template: '<div />' } },
        { path: '/messages', name: 'messages', component: { template: '<div />' } }
      ]
    })
    router.push('/')
    await router.isReady()

    const wrapper = mount(Messages, { global: { plugins: [router] } })
    await new Promise(resolve => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()

    // The green pill on a conversation row is the unread count. Matched as a
    // div: the "new chat" button carries the same background colour, so a bare
    // class selector finds it and the assertion passes for the wrong reason.
    expect(
      wrapper.findAll('div.bg-emerald-600').length,
      'a read message must not be counted unread',
    ).toBe(0)
    wrapper.unmount()
  })
})

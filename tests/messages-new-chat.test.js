import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'
import Messages from '../src/views/Messages.vue'

/**
 * The "new chat" panel that replaced the two compose dropdowns.
 *
 * A dropdown showed one contact at a time and could not be searched, and the
 * same control both started a conversation and decided who an open thread was
 * addressed to — which is how a broadcast reply once went to whoever happened
 * to be selected in it. These assertions pin the replacement's behaviour.
 */
describe('messages new chat panel', () => {
  const ME = 1
  const AYESHA = 2
  const FATIMA = 4

  const INBOX = {
    data: [
      {
        id: 10,
        senderId: AYESHA,
        senderName: 'Ayesha Khan',
        audience: 'Direct message',
        body: 'Morning',
        createdAt: '2026-08-10T09:00:00+00:00',
        recipientIds: [ME],
        isRead: true
      }
    ],
    contacts: [
      { id: AYESHA, name: 'Ayesha Khan', role: 'employee' },
      { id: FATIMA, name: 'Fatima Iqbal', role: 'qa' }
    ],
    hasMore: false,
    oldestId: 10
  }

  let audiences = []

  function testRouter() {
    return createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: { template: '<div />' } },
        { path: '/messages', name: 'messages', component: { template: '<div />' } }
      ]
    })
  }

  async function mountMessages() {
    const router = testRouter()
    router.push('/')
    await router.isReady()

    const wrapper = mount(Messages, { global: { plugins: [router] } })
    await new Promise(resolve => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()

    return wrapper
  }

  async function openPanel(wrapper) {
    await wrapper.find('button[title="New chat"]').trigger('click')
    await wrapper.vm.$nextTick()
  }

  /** The panel's own filter, distinct from the conversation search. */
  async function filterPanel(wrapper, term) {
    await wrapper.find('input[placeholder="Search name or role"]').setValue(term)
    await wrapper.vm.$nextTick()
  }

  function panelIsOpen(wrapper) {
    return wrapper.find('input[placeholder="Search name or role"]').exists()
  }

  /**
   * The panel's own text, not the page's.
   *
   * The panel covers the conversation list rather than replacing it, so the
   * names behind it are still in the DOM — asserting against the whole page
   * would pass whatever the filter did.
   */
  function panelText(wrapper) {
    return wrapper.find('.z-20').text()
  }

  beforeEach(() => {
    audiences = []

    const { state, setCurrentUser } = useStore()
    setCurrentUser(99, 'Me', 'employee', 'me@test.com', ME)
    state.messages = []

    vi.mocked(apiClient.get).mockImplementation(path => {
      if (path.includes('audiences')) return Promise.resolve({ audiences })
      if (path.startsWith('/messages')) return Promise.resolve(INBOX)
      return Promise.resolve({ data: [], meta: {} })
    })
  })

  it('stays closed until the button is pressed', async () => {
    const wrapper = await mountMessages()

    expect(panelIsOpen(wrapper)).toBe(false)
    wrapper.unmount()
  })

  it('lists everyone reachable when opened', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)

    expect(panelIsOpen(wrapper)).toBe(true)
    expect(panelText(wrapper)).toContain('Ayesha Khan')
    expect(panelText(wrapper)).toContain('Fatima Iqbal')
    wrapper.unmount()
  })

  it('filters by role, so a name is not needed to find the QA', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)
    await filterPanel(wrapper, 'qa')

    expect(panelText(wrapper)).toContain('Fatima Iqbal')
    expect(panelText(wrapper)).not.toContain('Ayesha Khan')
    wrapper.unmount()
  })

  it('says when nobody matches instead of showing an empty panel', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)
    await filterPanel(wrapper, 'nobodyhere')

    expect(panelText(wrapper)).toContain('No one matches')
    wrapper.unmount()
  })

  it('closes and opens the chat when a person is picked', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)
    await filterPanel(wrapper, 'Fatima')

    const row = wrapper.findAll('button').find(button => button.text().includes('Fatima Iqbal'))
    await row.trigger('click')
    await wrapper.vm.$nextTick()

    expect(panelIsOpen(wrapper), 'picking somebody should dismiss the panel').toBe(false)
    wrapper.unmount()
  })

  it('closes without choosing anybody', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)

    await wrapper.find('button[title="Back"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(panelIsOpen(wrapper)).toBe(false)
    wrapper.unmount()
  })

  it('offers broadcast audiences to someone who may send them', async () => {
    audiences = [{ id: 'employees', label: 'All employees', reaches: 12 }]

    const wrapper = await mountMessages()
    await openPanel(wrapper)

    expect(panelText(wrapper)).toContain('Broadcast to')
    expect(panelText(wrapper)).toContain('All employees')
    expect(panelText(wrapper)).toContain('Reaches 12')
    wrapper.unmount()
  })

  it('hides broadcast from someone who may not send one', async () => {
    const wrapper = await mountMessages()
    await openPanel(wrapper)

    expect(panelText(wrapper)).not.toContain('Broadcast to')
    wrapper.unmount()
  })

  it('drops the audience list once a name is being typed', async () => {
    audiences = [{ id: 'employees', label: 'All employees', reaches: 12 }]

    const wrapper = await mountMessages()
    await openPanel(wrapper)
    await filterPanel(wrapper, 'Fatima')

    // An audience is not a person; leaving it above the results buries them.
    expect(panelText(wrapper)).not.toContain('Broadcast to')
    expect(panelText(wrapper)).toContain('Fatima Iqbal')
    wrapper.unmount()
  })
})

import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'
import Messages from '../src/views/Messages.vue'

/**
 * Searching the conversation list.
 *
 * The filter is worth a test because it fails silently: a term that matches
 * nothing renders an empty list, which looks exactly like a working search
 * over an inbox with no matches. Only an assertion tells the two apart.
 */
describe('messages search', () => {
  const ME = 1
  const AYESHA = 2
  const BILAL = 3
  // Reachable, but nothing has ever been said to them.
  const FATIMA = 4

  const INBOX = {
    data: [
      {
        id: 10,
        senderId: AYESHA,
        senderName: 'Ayesha Khan',
        audience: 'Direct message',
        body: 'The payroll run is late again',
        createdAt: '2026-08-10T09:00:00+00:00',
        recipientIds: [ME],
        isRead: true
      },
      {
        id: 11,
        senderId: BILAL,
        senderName: 'Bilal Raza',
        audience: 'Direct message',
        body: 'Standup moved to ten',
        createdAt: '2026-08-11T09:00:00+00:00',
        recipientIds: [ME],
        isRead: true
      }
    ],
    contacts: [
      { id: AYESHA, name: 'Ayesha Khan', role: 'employee' },
      { id: BILAL, name: 'Bilal Raza', role: 'employee' },
      { id: FATIMA, name: 'Fatima Iqbal', role: 'qa' }
    ],
    hasMore: false,
    oldestId: 10
  }

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

    // Let the inbox load resolve.
    await new Promise(resolve => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()

    return wrapper
  }

  /** Conversation rows, by the name each one is with. */
  function listedNames(wrapper) {
    return wrapper.findAll('button')
      .map(button => button.text())
      .filter(text => /Ayesha|Bilal|Fatima/.test(text))
  }

  async function search(wrapper, term) {
    await wrapper.find('input[placeholder="Search or start a new chat"]').setValue(term)
    await wrapper.vm.$nextTick()
  }

  beforeEach(() => {
    const { state, setCurrentUser } = useStore()
    setCurrentUser(99, 'Me', 'employee', 'me@test.com', ME)
    state.messages = []

    vi.mocked(apiClient.get).mockImplementation(path => {
      if (path.startsWith('/messages') && !path.includes('audiences')) {
        return Promise.resolve(INBOX)
      }
      if (path.includes('audiences')) return Promise.resolve({ audiences: [] })
      return Promise.resolve({ data: [], meta: {} })
    })
  })

  it('lists every conversation when nothing is typed', async () => {
    const wrapper = await mountMessages()
    const names = listedNames(wrapper)

    expect(names.some(text => text.includes('Ayesha'))).toBe(true)
    expect(names.some(text => text.includes('Bilal'))).toBe(true)
    wrapper.unmount()
  })

  it('filters by what was said', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'payroll')

    const names = listedNames(wrapper)
    expect(names.some(text => text.includes('Ayesha'))).toBe(true)
    expect(names.some(text => text.includes('Bilal'))).toBe(false)
    wrapper.unmount()
  })

  it('filters by who the conversation is with', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'Bilal')

    const names = listedNames(wrapper)
    expect(names.some(text => text.includes('Bilal'))).toBe(true)
    expect(names.some(text => text.includes('Ayesha'))).toBe(false)
    wrapper.unmount()
  })

  it('offers a contact there is no conversation with yet', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'Fatima')

    // Nothing has been said to Fatima, so she is reachable only as a new chat.
    expect(wrapper.text()).toContain('Start a new chat')
    expect(wrapper.text()).toContain('Fatima Iqbal')
    wrapper.unmount()
  })

  it('says it is still looking before the API has answered', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'zzzznothing')

    // Asserted before the debounce fires: claiming "nothing matches" while the
    // request is still out would be a guess, and a wrong one often enough.
    expect(wrapper.text()).toContain('Searching…')
    wrapper.unmount()
  })

  it('says so when nothing matches, rather than looking empty', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'zzzznothing')

    // Past the debounce and the request, so the answer is settled.
    await new Promise(resolve => setTimeout(resolve, 350))
    await wrapper.vm.$nextTick()

    expect(listedNames(wrapper)).toHaveLength(0)
    expect(wrapper.text()).toContain('Nothing matches')
    wrapper.unmount()
  })

  it('asks the API for matches so history beyond the first page is covered', async () => {
    const wrapper = await mountMessages()
    apiClient.get.mockClear()

    await search(wrapper, 'payroll')
    // The debounce, then the request.
    await new Promise(resolve => setTimeout(resolve, 350))

    const searched = apiClient.get.mock.calls.map(call => call[0]).filter(path => path.includes('q='))
    expect(searched.length, 'a search should reach the API, not just filter locally').toBeGreaterThan(0)
    expect(searched[0]).toContain('q=payroll')
    wrapper.unmount()
  })

  it('restores the full list when the search is cleared', async () => {
    const wrapper = await mountMessages()
    await search(wrapper, 'payroll')
    expect(listedNames(wrapper).some(text => text.includes('Bilal'))).toBe(false)

    await search(wrapper, '')

    expect(listedNames(wrapper).some(text => text.includes('Bilal'))).toBe(true)
    wrapper.unmount()
  })
})

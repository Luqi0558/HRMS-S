import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'
import LeaveRequests from '../src/views/LeaveRequests.vue'

/**
 * Leave requests are an employee's, keyed by EMPLOYEE id.
 *
 * The page compared them with the signed-in USER id. In the seeded demo every
 * employee shares its user's id, which hid it; the first real account where
 * the two differ saw none of their own requests. Signed in here with the two
 * deliberately different.
 */
describe('own leave requests', () => {
  const USER_ID = 99
  const MY_EMPLOYEE_ID = 4

  const myPendingRequest = {
    id: 31, employeeId: MY_EMPLOYEE_ID, type: 'Annual leave', from: '2026-10-20', to: '2026-10-21',
    days: 2, reason: 'Family visit', status: 'pending', approvals: []
  }

  async function mountAsEmployee() {
    const { setCurrentUser } = useStore()
    setCurrentUser(USER_ID, 'Me', 'employee', 'me@test.com', MY_EMPLOYEE_ID)

    const router = createRouter({ history: createWebHistory(), routes: [{ path: '/:p(.*)*', component: { template: '<div />' } }] })
    router.push('/')
    await router.isReady()

    const wrapper = mount(LeaveRequests, { global: { plugins: [router], stubs: { teleport: true } } })
    await new Promise(resolve => setTimeout(resolve, 0))
    await wrapper.vm.$nextTick()
    return wrapper
  }

  beforeEach(() => {
    vi.mocked(apiClient.get).mockImplementation(path => {
      if (path.startsWith('/leave-requests/balance')) return Promise.resolve({ balance: {} })
      if (path.startsWith('/leave-requests')) return Promise.resolve({ data: [myPendingRequest], meta: {} })
      return Promise.resolve({ data: [], meta: {} })
    })
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.post).mockResolvedValue({ request: { ...myPendingRequest, status: 'cancelled' } })
  })

  it('shows an employee their own request when user and employee ids differ', async () => {
    const wrapper = await mountAsEmployee()

    expect(wrapper.text()).toContain('Family visit')
    wrapper.unmount()
  })

  it('withdraws a pending request through the API', async () => {
    window.confirm = vi.fn(() => true)
    const wrapper = await mountAsEmployee()

    const withdraw = wrapper.findAll('button').find(button => button.text() === 'Withdraw')
    expect(withdraw, 'Withdraw button').toBeTruthy()
    await withdraw.trigger('click')
    await new Promise(resolve => setTimeout(resolve, 0))

    expect(apiClient.post).toHaveBeenCalledWith('/leave-requests/31/cancel', {})
    expect(useStore().state.leaveRequests.find(item => item.id === 31).status).toBe('cancelled')
    wrapper.unmount()
  })
})

import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'
import HrDashboard from '../src/components/HrDashboard.vue'

/**
 * The HR attendance follow-up dialog.
 *
 * It used to show "Follow-up recorded" and store nothing. These check that it
 * loads the employee's earlier follow-ups, sends the internal note and the
 * optional employee message as separate fields, and renders without a single
 * template property missing — the dialog only renders once opened, so the
 * everyday mount tests never reach it.
 */
describe('attendance follow-up dialog', () => {
  const WORKER = { id: 7, name: 'Ayesha Khan', email: 'ayesha@test.com', department: 'Engineering', status: 'active', role: 'employee' }

  function router() {
    return createRouter({
      history: createWebHistory(),
      routes: [{ path: '/:pathMatch(.*)*', component: { template: '<div />' } }]
    })
  }

  async function mountDashboard() {
    const { state, setCurrentUser, todayKey } = useStore()
    setCurrentUser(1, 'HR User', 'hr', 'hr@test.com', 1)
    state.employees = [WORKER]
    state.attendance = [{ id: 55, employeeId: WORKER.id, date: todayKey(), checkIn: '10:40', checkOut: null, status: 'late' }]

    const testRouter = router()
    testRouter.push('/')
    await testRouter.isReady()

    const wrapper = mount(HrDashboard, { global: { plugins: [testRouter], stubs: { teleport: true } } })
    await new Promise(resolve => setTimeout(resolve, 0))
    return wrapper
  }

  beforeEach(() => {
    vi.spyOn(console, 'warn').mockImplementation(message => {
      if (typeof message === 'string' && message.includes('not defined on instance')) {
        throw new Error(message)
      }
    })
    vi.mocked(apiClient.get).mockImplementation(path => {
      if (path.startsWith('/attendance/follow-ups')) {
        return Promise.resolve({
          data: path.includes('employeeId')
            ? [{ id: 1, employeeId: WORKER.id, date: '2026-09-20', issue: 'late', action: 'called', note: 'Traffic.', status: 'resolved', createdByName: 'HR User', employeeMessaged: false }]
            : []
        })
      }
      return Promise.resolve({ data: [], meta: {} })
    })
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.post).mockImplementation((path, body) => Promise.resolve({
      followUp: { id: 2, employeeId: body.employeeId, date: body.date, issue: body.issue, action: body.action, note: body.note, status: 'open', employeeMessaged: Boolean(body.messageToEmployee) }
    }))
  })

  it('opens with history and saves the note and message as separate fields', async () => {
    const wrapper = await mountDashboard()
    const row = {
      id: 'late-55', issue: 'late', attendanceId: 55, date: useStore().todayKey(),
      employee: WORKER, employeeName: WORKER.name, department: WORKER.department,
      status: 'Late', checkIn: '10:40', checkOut: '-', action: 'Review'
    }

    await wrapper.vm.openAttendanceFollowUp(row)
    await wrapper.vm.$nextTick()

    expect(wrapper.text()).toContain('Previous follow-ups')
    expect(wrapper.text()).toContain('Traffic.')

    wrapper.vm.followUpForm.action = 'called'
    wrapper.vm.followUpForm.note = 'Called; will be on time tomorrow.'
    wrapper.vm.followUpForm.notifyEmployee = true
    wrapper.vm.followUpForm.message = 'Please check in by 9:15.'
    await wrapper.vm.sendAttendanceFollowUp()

    expect(apiClient.post).toHaveBeenCalledWith('/attendance/follow-ups', expect.objectContaining({
      employeeId: WORKER.id,
      attendanceId: 55,
      issue: 'late',
      action: 'called',
      note: 'Called; will be on time tomorrow.',
      messageToEmployee: 'Please check in by 9:15.'
    }))
    expect(wrapper.vm.followUpMessage).toContain('messaged')
    wrapper.unmount()
  })

  it('refuses to save without an internal note', async () => {
    const wrapper = await mountDashboard()
    await wrapper.vm.openAttendanceFollowUp({
      id: 'absent-7', issue: 'absent', attendanceId: null, date: useStore().todayKey(),
      employee: WORKER, employeeName: WORKER.name, department: WORKER.department, status: 'Absent', action: 'Follow up'
    })

    await wrapper.vm.sendAttendanceFollowUp()

    expect(apiClient.post).not.toHaveBeenCalled()
    expect(wrapper.vm.followUpError).toContain('internal note')
    wrapper.unmount()
  })
})

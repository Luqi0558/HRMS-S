import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'

/**
 * The ticket timer is optimistic in both directions, and must roll back in
 * both.
 *
 * Stopping used to swallow a failed request: the screen showed the timer
 * stopped while the server's session stayed open and kept counting into the
 * timesheet. Starting used to fire a separate, un-awaited stop for the
 * previous timer, which could land after the start and stop the new one.
 */
describe('ticket timer', () => {
  function signInWithTickets() {
    const store = useStore()
    store.setCurrentUser(1, 'Dev', 'employee', 'dev@test.com', 1)
    store.setRolePermissions?.([{ role: 'employee', module: 'Tickets', view: true, create: true, approve: false, finance: false, scope: 'team' }])
    store.state.tickets = [
      { id: 10, status: 'in_progress', timeLogs: [{ start: '2026-10-05T09:00:00Z', end: null, employeeId: 1 }], comments: [] },
      { id: 11, status: 'todo', timeLogs: [], comments: [] }
    ]
    store.state.activeTimer = { ticketId: 10 }
    return store
  }

  beforeEach(() => {
    vi.mocked(apiClient.post).mockReset()
  })

  it('keeps the timer running on screen when the stop fails', async () => {
    const { state, stopTicketTimer } = signInWithTickets()
    vi.mocked(apiClient.post).mockRejectedValue(new Error('Network down'))

    const result = await stopTicketTimer(10)

    expect(result.ok).toBe(false)
    expect(state.activeTimer).toEqual({ ticketId: 10 })
    expect(state.tickets[0].timeLogs[0].end).toBeNull()
  })

  it('starts a new timer with a single request, letting the server stop the old one', async () => {
    const { state, startTicketTimer, can } = signInWithTickets()
    expect(can('Tickets', 'create')).toBe(true)
    vi.mocked(apiClient.post).mockResolvedValue({ ticket: { id: 11, status: 'in_progress', timeLogs: [] } })

    const result = await startTicketTimer(11)

    expect(result.ok).toBe(true)
    expect(apiClient.post).toHaveBeenCalledTimes(1)
    expect(apiClient.post).toHaveBeenCalledWith('/tickets/11/start-timer')
    expect(state.tickets[0].timeLogs[0].end).not.toBeNull()
  })

  it('shows the previous timer running again when the start is refused', async () => {
    const { state, startTicketTimer, can } = signInWithTickets()
    expect(can('Tickets', 'create')).toBe(true)
    vi.mocked(apiClient.post).mockRejectedValue(new Error('Check in first'))

    const result = await startTicketTimer(11)

    expect(result.ok).toBe(false)
    expect(state.activeTimer).toEqual({ ticketId: 10 })
    expect(state.tickets[0].timeLogs[0].end).toBeNull()
  })
})

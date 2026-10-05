import './setup.js'

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useStore } from '../src/store/index.js'
import { apiClient } from '../src/services/apiClient.js'

/**
 * The bell counts what the server says is unread, not what is in memory.
 *
 * /sync replays at most the newest hundred notifications, so a badge counted
 * from the list capped out and drifted once anyone had older unread rows —
 * and "mark all read" looped one request per row it could see, leaving the
 * rest unread on the server for the badge to come back on next load.
 */
describe('notification badge', () => {
  const USER_ID = 7

  function signIn() {
    const { state, setCurrentUser } = useStore()
    setCurrentUser(USER_ID, 'Me', 'employee', 'me@test.com', 3)
    state.notifications = []
    state.unreadCounts = { messages: 0, notifications: 0 }
    return useStore()
  }

  /** Lets the dynamic import inside the store resolve and fire its request. */
  const flush = () => new Promise(resolve => setTimeout(resolve, 50))

  beforeEach(() => {
    vi.mocked(apiClient.post).mockReset()
    vi.mocked(apiClient.post).mockResolvedValue({ marked: 0 })
  })

  it('counts unread from the server, beyond the rows held in memory', () => {
    const { applySync, unreadNotificationCount } = signIn()

    applySync({
      notifications: [{ id: 1, title: 'Leave approved', isRead: false }],
      unread: { messages: 0, notifications: 140 }
    })

    expect(unreadNotificationCount.value).toBe(140)
  })

  it('adds local toasts, which have no server record', () => {
    const { state, pushNotification, unreadNotificationCount } = signIn()
    state.unreadCounts.notifications = 2

    pushNotification({ title: 'Could not move ticket', userIds: [USER_ID] })

    expect(unreadNotificationCount.value).toBe(3)
  })

  it('marks everything read in one request', async () => {
    const { applySync, markAllNotificationsRead, unreadNotificationCount } = signIn()
    applySync({
      notifications: [
        { id: 1, title: 'One', isRead: false },
        { id: 2, title: 'Two', isRead: false },
        { id: 3, title: 'Three', isRead: false }
      ],
      unread: { messages: 0, notifications: 140 }
    })

    markAllNotificationsRead()

    expect(unreadNotificationCount.value).toBe(0)
    await vi.waitFor(() => expect(apiClient.post).toHaveBeenCalled())
    await flush()
    expect(apiClient.post).toHaveBeenCalledTimes(1)
    expect(apiClient.post).toHaveBeenCalledWith('/notifications/read-all', {})
  })

  it('sends nothing when the server has nothing unread', async () => {
    const { state, pushNotification, markAllNotificationsRead, unreadNotificationCount } = signIn()
    pushNotification({ title: 'Local only', userIds: [USER_ID] })
    expect(state.notifications).toHaveLength(1)

    markAllNotificationsRead()
    await flush()

    expect(unreadNotificationCount.value).toBe(0)
    expect(apiClient.post).not.toHaveBeenCalled()
  })
})

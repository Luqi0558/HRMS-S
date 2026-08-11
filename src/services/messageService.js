import { apiClient } from './apiClient.js'

/**
 * Messages and notifications.
 *
 * New items arrive through syncService's poll, not from here — these are the
 * write actions and the initial inbox load.
 */
export const messageService = {
  /**
   * The Messages page in one call: conversation history plus the contacts
   * this user may write to.
   *
   * Contacts come from here rather than /employees because an employee has no
   * permission to read the roster — that call 403s for the most common role,
   * and would be the wrong list anyway.
   */
  async list(before = null) {
    const query = before ? `?before=${before}` : ''
    const data = await apiClient.get(`/messages${query}`)

    return {
      messages: data.data || [],
      contacts: data.contacts || [],
      // Keyset paging: hasMore says whether older history exists, oldestId is
      // where the next page starts from.
      hasMore: Boolean(data.hasMore),
      oldestId: data.oldestId || 0
    }
  },

  /**
   * Sends a message.
   *
   * `recipientIds` is a request, not a decision: the API intersects it with
   * whom the sender's Messages scope actually permits, so a longer list does
   * not reach further.
   *
   * @param {string} [audience] 'Direct message', 'role:<name>', or a
   *   company-wide label
   */
  async send({ body, subject, audience = 'Direct message', recipientIds = [] }) {
    const data = await apiClient.post('/messages', { body, subject, audience, recipientIds })

    return data.sent
  },

  async markRead(id) {
    return apiClient.post(`/messages/${id}/read`, {})
  },

  async markAllRead() {
    const data = await apiClient.post('/messages/read-all', {})

    return data.marked
  },

  async markNotificationRead(id) {
    return apiClient.post(`/notifications/${id}/read`, {})
  },

  async markAllNotificationsRead() {
    const data = await apiClient.post('/notifications/read-all', {})

    return data.marked
  }
}

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
  async list(before = null, search = null) {
    // Built rather than concatenated: a search term is user input and can
    // contain & or #, which would otherwise truncate the query string.
    const params = new URLSearchParams()
    if (before) params.set('before', before)
    if (search) params.set('q', search)

    const query = params.toString()
    const data = await apiClient.get(`/messages${query ? `?${query}` : ''}`)

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
  /**
   * Audiences this user may broadcast to, each with how many people it reaches.
   *
   * Fetched rather than hardcoded: the client used to keep its own list of
   * audiences AND its own idea of which roles may broadcast, both of which
   * drifted from the API the moment a role was added — "All employees" quietly
   * stopped reaching QA and interns, with no error anywhere.
   *
   * @returns {Promise<Array<{id: string, label: string, reaches: number}>>}
   */
  async audiences() {
    const data = await apiClient.get('/messages/audiences')

    return data.audiences || []
  },

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

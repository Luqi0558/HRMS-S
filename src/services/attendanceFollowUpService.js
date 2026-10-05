import { apiClient } from './apiClient.js'

/**
 * HR's internal follow-up notes on attendance exceptions.
 *
 * Visible only to people who supervise the employee's attendance — never to
 * the employee. A message to the employee is sent separately, through
 * `messageToEmployee`, and only when HR writes one.
 */
export const attendanceFollowUpService = {
  /**
   * @param {{employeeId?: number, from?: string, to?: string, status?: 'open'|'resolved'}} filters
   */
  async list(filters = {}) {
    const params = new URLSearchParams()
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') params.set(key, value)
    })
    const query = params.toString()
    const data = await apiClient.get(`/attendance/follow-ups${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /**
   * @param {{employeeId: number, attendanceId?: number|null, date?: string, issue: string,
   *   action?: string, note: string, messageToEmployee?: string|null}} followUp
   */
  async record(followUp) {
    const data = await apiClient.post('/attendance/follow-ups', followUp)

    return data.followUp
  },

  async resolve(id, resolution = null) {
    const data = await apiClient.patch(`/attendance/follow-ups/${id}/resolve`, { resolution })

    return data.followUp
  }
}

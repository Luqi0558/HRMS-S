import { apiClient } from './apiClient.js'

/**
 * Daily attendance: the check in / check out punch and its history.
 *
 * The API decides `status` (present vs late) from the company's start time and
 * grace period, and stores it on the row. The client never recomputes it —
 * changing the grace period later must not rewrite what already happened.
 */
export const attendanceService = {
  /**
   * Punch history, already scoped by the API: an employee receives only their
   * own rows, a manager their team's.
   *
   * @returns {Promise<Array>} attendance rows, newest first
   */
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/attendance${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /**
   * Today's row for the signed-in user, or null before their first punch.
   * Drives whether the button reads "Check in" or "Check out".
   */
  async today() {
    const data = await apiClient.get('/attendance/today')

    return data.attendance
  },

  /**
   * @param {object} options
   * @param {string} [options.device] how they punched in — 'camera', 'laptop'
   * @param {string} [options.photoPath] stored check-in photo, when required
   */
  async checkIn({ device = 'camera', photoPath } = {}) {
    const data = await apiClient.post('/attendance/check-in', { device, photoPath })

    return data.attendance
  },

  async checkOut() {
    const data = await apiClient.post('/attendance/check-out', {})

    return data.attendance
  }
}

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
   * @param {Blob} [options.photo] the captured JPEG, when the camera was used
   *
   * Sent as multipart when there is a photo: the API stores the file itself and
   * derives the path. It previously took a `photoPath` string, which the client
   * had no way to produce — the camera yields an image, not a storage location.
   */
  async checkIn({ device = 'camera', photo, workMode = 'office' } = {}) {
    if (!photo) {
      const data = await apiClient.post('/attendance/check-in', { device, workMode })

      return data.attendance
    }

    const form = new FormData()
    form.append('device', device)
    form.append('workMode', workMode)
    form.append('photo', photo, 'check-in.jpg')

    const data = await apiClient.post('/attendance/check-in', form)

    return data.attendance
  },

  /**
   * An object URL for a check-in photo, or null if there is none.
   *
   * Fetched rather than linked: the file sits on a private disk behind the same
   * permission and scope checks as the attendance row, so a plain <img src>
   * would arrive without the bearer token and get a 401. Callers must
   * URL.revokeObjectURL() what they receive.
   */
  async photoUrl(attendanceId) {
    try {
      return URL.createObjectURL(await apiClient.blob(`/attendance/${attendanceId}/photo`))
    } catch {
      // 404 for a row without a photo, or one this user may not see. Either way
      // there is nothing to show, and it is not worth an error state.
      return null
    }
  },

  async checkOut() {
    const data = await apiClient.post('/attendance/check-out', {})

    return data.attendance
  }
}

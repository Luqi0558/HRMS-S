import { apiClient } from './apiClient.js'

/**
 * Leave requests and their two-stage approval.
 *
 * Approval runs in two stages when `settings.leave.twoStepApproval` is on: a
 * team lead signs off first, then HR. `decide` covers both — the API picks the
 * stage from the permission the caller holds ('Team Leaves' for the lead
 * stage, 'Leave Request' for HR's), so there is nothing for the client to
 * pass. A request stays `pending` until every required stage has approved; a
 * rejection at either stage is final.
 */
export const leaveService = {
  /** @returns {Promise<Array>} requests visible to the caller, newest first */
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/leave-requests${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /**
   * @param {object} request
   * @param {string} request.type one of the four leave types
   * @param {string} request.fromDate ISO date
   * @param {string} request.toDate ISO date
   * @param {number} request.days 0.5 steps; halves need halfDayEnabled
   */
  async create({ type, fromDate, toDate, days, reason }) {
    const data = await apiClient.post('/leave-requests', {
      type, fromDate, toDate, days, reason
    })

    return data.request
  },

  /** @param {'approved'|'rejected'} decision */
  async decide(id, decision, comment) {
    const data = await apiClient.post(`/leave-requests/${id}/decision`, { decision, comment })

    return data.request
  },

  /** Withdraws one's own request; only valid while it is still pending. */
  async cancel(id) {
    const data = await apiClient.post(`/leave-requests/${id}/cancel`, {})

    return data.request
  },

  /**
   * Days allowed, taken and remaining per leave type for the current year.
   * Unpaid leave reports `allowed: null` — it has no ceiling.
   */
  async balance() {
    const data = await apiClient.get('/leave-requests/balance')

    return data.balance || {}
  }
}

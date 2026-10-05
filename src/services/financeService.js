import { apiClient } from './apiClient.js'

/**
 * Client payments against project modules.
 *
 * The Finance page used to "log" a payment by pushing an object into browser
 * state; nothing reached the server, so payments — and every commission
 * figure derived from them — vanished on refresh.
 */
export const financeService = {
  /**
   * Payments the signed-in user may see: a manager's own projects, or every
   * project for a company-wide Finance grant. Newest first.
   */
  async payments(moduleId = null) {
    const query = moduleId ? `?moduleId=${encodeURIComponent(moduleId)}` : ''
    const data = await apiClient.get(`/finance/payments${query}`)

    return data.data || []
  },

  /**
   * @param {number} moduleId
   * @param {{amount: number, date: string, method: string, payer?: string, reference?: string, notes?: string}} payment
   */
  async logPayment(moduleId, payment) {
    const data = await apiClient.post(`/modules/${moduleId}/payments`, payment)

    return data.payment
  },

  /** Removes a payment logged in error. Requires the Finance approve right. */
  async removePayment(paymentId) {
    return apiClient.delete(`/finance/payments/${paymentId}`)
  }
}

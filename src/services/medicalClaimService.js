import { apiClient } from './apiClient.js'

/**
 * Medical claims against the annual allowance.
 *
 * Amounts cross the wire as strings. JSON numbers are doubles, and a payable
 * figure rendered through one can lose precision — callers should format them
 * rather than doing arithmetic, and let the API be the source of truth for
 * what a claim has consumed.
 */
export const medicalClaimService = {
  /** @returns {Promise<Array>} claims visible to the caller, newest first */
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/medical-claims${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /**
   * @param {object} claim
   * @param {number} claim.amount refused if it exceeds the remaining allowance
   * @param {string} [claim.incurredOn] when the cost was incurred — decides
   *   which year's allowance it consumes, not the filing date
   * @param {File} [claim.receipt] the receipt itself; required above
   *   settings.claims.receiptRequiredAbove. Sent as multipart so the API
   *   stores the file rather than a name it cannot open.
   */
  async create({ type, amount, description, incurredOn, receipt }) {
    const body = new FormData()
    body.append('type', type)
    body.append('amount', String(amount))
    if (description) body.append('description', description)
    if (incurredOn) body.append('incurredOn', incurredOn)
    if (receipt) body.append('receipt', receipt)

    const data = await apiClient.post('/medical-claims', body)

    return data.claim
  },

  /**
   * Approving with an `approvedAmount` settles less than was claimed; omitting
   * it authorises the claim in full. It may never exceed the claim.
   *
   * @param {'approved'|'rejected'} decision
   */
  async decide(id, decision, { approvedAmount, comment } = {}) {
    const data = await apiClient.post(`/medical-claims/${id}/decision`, {
      decision, approvedAmount, comment
    })

    return data.claim
  },

  /**
   * Records a review step short of a decision. Same right as deciding.
   *
   * @param {'view'|'processing'} stage
   */
  async advance(id, stage) {
    const data = await apiClient.post(`/medical-claims/${id}/stage`, { stage })

    return data.claim
  },

  /**
   * Downloads a claim's receipt.
   *
   * Fetched rather than linked: the file sits behind bearer auth and a tenant
   * header, so a plain <a href> would come back 401. The blob is handed to the
   * browser as a download instead.
   */
  async downloadReceipt(id) {
    const blob = await apiClient.blob(`/medical-claims/${id}/receipt`)
    const url = URL.createObjectURL(blob)

    const link = document.createElement('a')
    link.href = url
    link.download = `receipt-${id}`
    link.click()

    URL.revokeObjectURL(url)
  },

  /**
   * Limit, used and remaining for the current year. Counts approved and paid
   * claims, at the approved figure where one was set.
   */
  async usage() {
    const data = await apiClient.get('/medical-claims/usage')

    return data.usage || { limit: 0, used: 0, remaining: 0 }
  }
}

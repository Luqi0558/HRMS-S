import { apiClient } from './apiClient.js'

/**
 * The petty cash tin: float in, spend out, and periodic counts.
 *
 * The balance is the API's to compute — approved top-ups minus every expense
 * that was not rejected. Do not re-derive it in the browser: a second
 * calculation is a second chance to disagree with the ledger, and the tin's
 * whole purpose is that the two agree.
 *
 * Spend above settings.pettyCash.approvalThreshold comes back `pending`
 * rather than `recorded`. That decision is the server's; the form should show
 * the threshold, not enforce it.
 */
export const pettyCashService = {
  /**
   * Everything the page renders, in one call: the tin's position plus all
   * three ledgers.
   *
   * @returns {Promise<{summary: object, topUps: Array, expenses: Array, reconciliations: Array}>}
   */
  async load(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/petty-cash${query ? `?${query}` : ''}`)

    return {
      summary: data.summary || {},
      topUps: data.topUps || [],
      expenses: data.expenses || [],
      reconciliations: data.reconciliations || []
    }
  },

  /**
   * Adds float. Someone who can approve records it as approved outright —
   * they are the sign-off; anyone else raises a request.
   */
  async addTopUp({ amount, date, reference, note }) {
    const data = await apiClient.post('/petty-cash/top-ups', { amount, date, reference, note })

    return data.topUp
  },

  /** @param {'approved'|'rejected'} decision */
  async decideTopUp(id, decision) {
    const data = await apiClient.post(`/petty-cash/top-ups/${id}/decision`, { decision })

    return data.topUp
  },

  /**
   * Records money spent. Sent as multipart so a receipt is stored as a file
   * rather than a name the API cannot open.
   *
   * @param {File} [expense.receipt]
   */
  async recordExpense({ amount, date, category, payee, description, receipt }) {
    const body = new FormData()
    body.append('amount', String(amount))
    body.append('category', category)
    if (date) body.append('date', date)
    if (payee) body.append('payee', payee)
    if (description) body.append('description', description)
    if (receipt) body.append('receipt', receipt)

    const data = await apiClient.post('/petty-cash/expenses', body)

    return data.expense
  },

  async decideExpense(id, decision, comment) {
    const data = await apiClient.post(`/petty-cash/expenses/${id}/decision`, { decision, comment })

    return data.expense
  },

  /**
   * Records a physical count. The API captures the book balance at that
   * moment and stores the variance, so a later backdated expense cannot
   * rewrite what a past count found.
   */
  async reconcile({ countedAmount, date, note }) {
    const data = await apiClient.post('/petty-cash/reconciliations', { countedAmount, date, note })

    return data.reconciliation
  }
}

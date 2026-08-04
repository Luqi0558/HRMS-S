import { apiClient } from './apiClient.js'

/**
 * Salaries, slip requests and issued slips.
 *
 * Two things to know about the shapes here.
 *
 * Every money field arrives as a string. Pay is encrypted at rest, and JSON
 * numbers are doubles — a payable figure should not round in transit. Convert
 * where you need arithmetic, but let the API be the source of truth for what
 * a slip says.
 *
 * A slip moves generated -> approved -> paid. Releasing needs the 'Payroll
 * Approval' right, recording payment needs Payroll.create; they are
 * deliberately different people in most companies.
 */
export const payrollService = {
  /** Pay history for one employee, newest first. Self or HR only. */
  async salaries(employeeId) {
    const data = await apiClient.get(`/employees/${employeeId}/salaries`)

    return data.salaries || []
  },

  /**
   * Records a new rate. Never edits the previous one — a raise is a new row
   * so past slips stay explicable.
   */
  async setSalary(employeeId, { baseSalary, houseAllowance, medicalAllowance, effectiveFrom, reason }) {
    const data = await apiClient.post(`/employees/${employeeId}/salaries`, {
      baseSalary, houseAllowance, medicalAllowance, effectiveFrom, reason
    })

    return data.salary
  },

  async requests(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/salary-slip-requests${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /** @param {string} period any date in the month being requested */
  async requestSlip(period) {
    const data = await apiClient.post('/salary-slip-requests', { period })

    return data.request
  },

  /**
   * Approving issues the slip in the same call. Per-month adjustments — a
   * bonus, an advance deduction — go in `overrides` and affect only that
   * slip, never the standing salary.
   */
  async decideRequest(id, decision, overrides = {}) {
    const data = await apiClient.post(`/salary-slip-requests/${id}/decision`, {
      decision, ...overrides
    })

    return data.request
  },

  async slips(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/salary-slips${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /** Releases a generated slip for payment. Needs 'Payroll Approval'. */
  async approveSlip(id) {
    const data = await apiClient.post(`/salary-slips/${id}/approve`, {})

    return data.slip
  },

  /** Records that a released slip was actually settled. */
  async markPaid(id, { method, reference, paidDate, notes } = {}) {
    const data = await apiClient.post(`/salary-slips/${id}/payment`, {
      method, reference, paidDate, notes
    })

    return data.slip
  },

  /**
   * Returns a released slip to 'generated' for correction. A paid slip cannot
   * be reopened — that correction belongs in the next run.
   */
  async reopenSlip(id) {
    const data = await apiClient.post(`/salary-slips/${id}/reopen`, {})

    return data.slip
  },

  /** Company payroll cost for a month. HR and super admin only. */
  async cost(period) {
    const data = await apiClient.get(`/salary-slips/cost${period ? `?period=${period}` : ''}`)

    return data.cost
  }
}

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
  /** Company-level monthly payroll registers. HR prepares; Super Admin releases. */
  async runs() {
    const data = await apiClient.get('/payroll-runs')

    return data.runs || []
  },

  async openRun(period, notes) {
    const data = await apiClient.post('/payroll-runs', { period, notes })

    return data.run
  },

  async submitRun(id) {
    const data = await apiClient.post('/payroll-runs/' + id + '/submit', {})

    return data.run
  },

  async releaseRun(id) {
    const data = await apiClient.post('/payroll-runs/' + id + '/release', {})

    return data.run
  },

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

  /** HR prepares a selected employee's payroll record for a pay period. */
  async requestSlip(period, employeeId) {
    const data = await apiClient.post('/salary-slip-requests', { period, employeeId })

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

  /** Downloads the server-rendered, access-controlled payslip snapshot. */
  async downloadSlip(id) {
    const blob = await apiClient.blob(`/salary-slips/${id}/pdf`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `payslip-${id}.pdf`
    link.click()
    URL.revokeObjectURL(url)
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

  /**
   * Corrects the figures on a slip that is open for correction ('generated'
   * in a draft run). Returns the slip and the request it belongs to.
   */
  async amendSlip(id, { baseSalary, houseAllowance, medicalAllowance, bonus, taxDeduction, advanceDeduction, notes }) {
    const data = await apiClient.patch(`/salary-slips/${id}`, {
      baseSalary, houseAllowance, medicalAllowance, bonus, taxDeduction, advanceDeduction, notes
    })

    return { slip: data.slip, request: data.request }
  },

  /** Company payroll cost for a month. HR and super admin only. */
  async cost(period) {
    const data = await apiClient.get(`/salary-slips/cost${period ? `?period=${period}` : ''}`)

    return data.cost
  }
}

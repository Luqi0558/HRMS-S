import { apiClient } from './apiClient.js'

export const employeeService = {
  /**
   * Scoped by the API: HR receives everyone, a manager only their own people.
   *
   * @returns {Promise<{data: Array, meta: object}>}
   */
  async list(filters = {}, perPage = 100) {
    const params = new URLSearchParams(
      Object.entries({ ...filters, per_page: perPage })
        .filter(([, v]) => v !== '' && v != null)
    ).toString()

    return apiClient.get(`/employees?${params}`)
  },

  async find(id) {
    const data = await apiClient.get(`/employees/${id}`)

    return data.employee
  },

  async update(id, attributes) {
    const data = await apiClient.patch(`/employees/${id}`, attributes)

    return data.employee
  },

  /** Persists an access block and revokes the target's active sessions. */
  async setBlocked(id, blocked) {
    return apiClient.patch(`/employees/${id}/blocked`, { blocked })
  },

  /** Creates the employee server-side and issues their onboarding invitation. */
  async invite(attributes) {
    return apiClient.post('/employees/invite', attributes)
  },

  /** Invalidates the previous onboarding link and sends a fresh one. */
  async resendInvitation(id) {
    return apiClient.post(`/employees/${id}/resend-invite`)
  },

  async reviewDocuments(id, attributes) {
    return apiClient.post(`/employees/${id}/document-review`, attributes)
  },

  /**
   * Downloads one of an employee's uploaded documents. Fetched as a blob:
   * the file sits behind bearer auth and the tenant header, so a plain link
   * would come back 401.
   *
   * @param {string} key e.g. 'cv', 'contract', 'offerLetter'
   */
  async downloadDocument(id, key, filename) {
    const blob = await apiClient.blob(`/employees/${id}/documents/${key}`)
    const url = URL.createObjectURL(blob)

    const link = document.createElement('a')
    link.href = url
    link.download = filename || key
    link.click()

    URL.revokeObjectURL(url)
  }
}

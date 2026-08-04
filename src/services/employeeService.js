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
  }
}

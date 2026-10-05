import { apiClient } from './apiClient.js'

/**
 * Company-wide role-permission matrix. These calls are intentionally separate
 * from authService.permissions(), which returns only the signed-in user's
 * effective permissions and must remain safe for every authenticated client.
 */
export const accessControlService = {
  async list() {
    const data = await apiClient.get('/access-control/permissions')
    return data.permissions || []
  },

  async save(permissions) {
    const data = await apiClient.put('/access-control/permissions', { permissions })
    return data.permissions || []
  },

  async people(filters = {}, page = 1) {
    const params = new URLSearchParams(
      Object.entries({ ...filters, page, per_page: 25 })
        .filter(([, value]) => value !== '' && value != null)
    ).toString()
    return apiClient.get(`/access-control/people?${params}`)
  },

  async assignRole(employeeId, role, reason = '') {
    const data = await apiClient.patch(`/access-control/people/${employeeId}/role`, { role, reason })
    return data.employee
  }
}

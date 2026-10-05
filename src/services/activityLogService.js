import { apiClient } from './apiClient.js'

/**
 * The audit trail.
 *
 * Write-often, read-rarely: every module records decisions here and only an
 * administrator reads them back. Reading needs the 'Activity Log' permission,
 * which no role holds explicitly — so in practice super admin only.
 */
export const activityLogService = {
  /**
   * @param {object} filters module, actor_id, from, to
   * @returns {Promise<{logs: Array, modules: Array, meta: object}>}
   */
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/activity-logs${query ? `?${query}` : ''}`)

    return {
      logs: data.data || [],
      // Only the modules that actually appear, so the filter cannot offer an
      // option that matches nothing.
      modules: data.modules || [],
      meta: data.meta || {}
    }
  },

}

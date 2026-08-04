import { apiClient } from './apiClient.js'

/**
 * Projects, their modules, and team membership.
 *
 * Responses are already scoped by the API — a manager receives the projects
 * they run, a member those they work on. The client does not filter for
 * access, only for display.
 */
export const projectService = {
  /** @returns {Promise<Array>} projects with module and ticket counts */
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null)
    ).toString()

    const data = await apiClient.get(`/projects${query ? `?${query}` : ''}`)

    return data.data || []
  },

  /** Resolved by slug — that is what the router has in its URLs. */
  async find(slug) {
    const data = await apiClient.get(`/projects/${encodeURIComponent(slug)}`)

    return data.project
  },

  async create({ name, description, managerId }) {
    const data = await apiClient.post('/projects', { name, description, managerId })

    return data.project
  },

  async update(slug, attributes) {
    const data = await apiClient.patch(`/projects/${encodeURIComponent(slug)}`, attributes)

    return data.project
  },

  /**
   * Marks a module delivered, or reopens it.
   *
   * Requires `approve` on Projects rather than `create`, so a team lead can
   * sign off their own deliverables without holding project-edit rights.
   *
   * @returns {Promise<object>} the updated module
   */
  async setModuleDelivered(moduleId, delivered) {
    const data = await apiClient.patch(`/modules/${moduleId}/delivery`, { delivered })

    return data.module
  },

  /**
   * Replaces the member list with exactly these employees — the API syncs
   * rather than appends, so callers must send the complete set.
   */
  async syncMembers(slug, employeeIds) {
    const data = await apiClient.put(
      `/projects/${encodeURIComponent(slug)}/members`,
      { employeeIds }
    )

    return data.project
  }
}

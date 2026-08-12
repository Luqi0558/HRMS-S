import { apiClient } from './apiClient.js'

/**
 * Company policy.
 *
 * Only the three groups below are stored server-side; the rest of
 * `state.settings` (company, finance, pettyCash, designations, stacks) is
 * still local and belongs to later phases. Callers must merge rather than
 * replace, or those groups are lost.
 *
 * Readable by anyone signed in — the client needs the working day to render
 * attendance and the allowances to show a balance — but writing needs
 * Settings.create.
 */
export const SERVER_SETTING_GROUPS = ['attendance', 'leave', 'claims']

export const settingsService = {
  /** @returns {Promise<object>} { attendance, leave, claims } */
  async all() {
    const data = await apiClient.get('/settings')

    return data.settings || {}
  },

  /**
   * Replaces one group's values.
   *
   * @param {'attendance'|'leave'|'claims'|'pettyCash'} group
   * @returns {Promise<object>} the group as stored
   */
  /**
   * Outbound integration credentials.
   *
   * A separate call from all(), which every signed-in user may make: the Teams
   * webhook URL is a credential — whoever holds it can post into the channel —
   * so the API serves it only to those who may edit Settings.
   */
  async integrations() {
    const data = await apiClient.get('/settings/integrations')

    return data.integrations || {}
  },

  async putGroup(group, values) {
    const data = await apiClient.put(`/settings/${group}`, { values })

    return data.settings?.[group] || {}
  },

  /**
   * The dropdown options: job titles and technical stacks.
   *
   * These live in their own tables rather than the key/value settings, because
   * onboarding needs them before a user has any permissions at all — and
   * because removing one has to leave the employees who hold it untouched.
   */
  async lists() {
    const data = await apiClient.get('/settings/lists')

    return data.lists || { designations: [], stacks: [] }
  },

  /** @param {'designations'|'stacks'} type */
  async addListItem(type, name) {
    const data = await apiClient.post(`/settings/lists/${type}`, { name })

    return data.lists
  },

  /**
   * Deactivates an option so it stops appearing in dropdowns.
   *
   * Never deletes: a designation is recorded on every employee who holds it.
   * Returns how many still do, so the UI can say so.
   */
  async removeListItem(type, name) {
    const data = await apiClient.delete(`/settings/lists/${type}`, { body: { name } })

    return { lists: data.lists, stillAssigned: data.stillAssigned || 0 }
  }
}

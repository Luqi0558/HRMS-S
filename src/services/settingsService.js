import { apiClient } from './apiClient.js'

/**
 * Company policy.
 *
 * Policy groups are persisted server-side. The settings store still merges
 * responses per group because lists are arrays and integration credentials
 * are loaded through their protected endpoint.
 *
 * Readable by anyone signed in — the client needs the working day to render
 * attendance and the allowances to show a balance — but writing needs
 * Settings.create.
 */
export const SERVER_SETTING_GROUPS = ['company', 'attendance', 'leave', 'claims', 'finance', 'pettyCash', 'integrations']

export const settingsService = {
  /** @returns {Promise<object>} company policy groups, excluding integrations */
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

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
   * @param {'attendance'|'leave'|'claims'} group
   * @returns {Promise<object>} the group as stored
   */
  async putGroup(group, values) {
    const data = await apiClient.put(`/settings/${group}`, { values })

    return data.settings?.[group] || {}
  }
}

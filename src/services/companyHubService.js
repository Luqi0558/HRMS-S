import { apiClient } from './apiClient.js'

/**
 * Announcements and policies.
 *
 * Acknowledgements are per policy VERSION. Reissuing a policy at 2.0 makes
 * every prior acknowledgement stale — agreeing to the old text is not agreeing
 * to the new — so `acknowledgedByMe` reflects the CURRENT version only.
 */
export const companyHubService = {
  /** Both lists in one call: the page always renders them together. */
  async load() {
    const data = await apiClient.get('/company-hub')

    return {
      announcements: data.announcements || [],
      policies: data.policies || []
    }
  },

  async publish({ title, body, audience, priority }) {
    const data = await apiClient.post('/company-hub/announcements', {
      title, body, audience, priority
    })

    return data.announcement
  },

  /** Creates a policy, or updates one when an id is given. */
  async savePolicy({ id, title, category, version, summary, body, acknowledgmentRequired }) {
    const payload = { title, category, version, summary, body, acknowledgmentRequired }

    const data = id
      ? await apiClient.patch(`/company-hub/policies/${id}`, payload)
      : await apiClient.post('/company-hub/policies', payload)

    return data.policy
  },

  /** Records that the caller has read and agreed to the current version. */
  async acknowledge(policyId) {
    return apiClient.post(`/company-hub/policies/${policyId}/acknowledge`, {})
  },

  /** Who has and has not acknowledged. HR and super admin only. */
  async acknowledgements(policyId) {
    return apiClient.get(`/company-hub/policies/${policyId}/acknowledgements`)
  }
}

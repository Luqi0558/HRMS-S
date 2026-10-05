import { apiClient } from './apiClient.js'

/**
 * The signed-in employee's own record.
 *
 * Separate from employeeService because /employees is gated on the Employees
 * module: an ordinary employee gets 403 there and so could never load or save
 * their own details through it. These two routes resolve the subject from the
 * bearer token instead, and work for every role.
 */
export const profileService = {
  /**
   * The full own-profile record, including the onboarding detail (personal,
   * technical, documents) that the Profile screen edits.
   */
  async get() {
    const data = await apiClient.get('/profile')

    return data.employee
  },

  /**
   * Changes the signed-in user's own password.
   *
   * The current password goes with it: the API verifies it rather than trusting
   * the client, so somebody at an unlocked screen cannot lock the owner out.
   * On success every other session is signed out — this one keeps working.
   */
  async changePassword({ currentPassword, newPassword }) {
    const data = await apiClient.post('/profile/password', {
      currentPassword,
      newPassword,
      // Laravel's `confirmed` rule looks for this exact name.
      newPassword_confirmation: newPassword
    })

    return data.message
  },

  /**
   * Only self-editable fields are accepted. The API silently drops anything
   * HR controls — department, designation, employment type, probation status,
   * email, status — so there is no point sending them.
   *
   * @param {{name?: string, birthday?: string, personal?: object, technical?: object}} attributes
   */
  async update(attributes) {
    const data = await apiClient.patch('/profile', attributes)

    return data.employee
  },

  /**
   * Uploads or replaces the signed-in user's own documents. A new file goes
   * back to HR for review.
   *
   * @param {Record<string, File>} files keyed cv, contract, offerLetter, ...
   */
  async uploadDocuments(files) {
    const body = new FormData()
    Object.entries(files).forEach(([key, file]) => body.append(`documents[${key}]`, file))

    const data = await apiClient.post('/profile/documents', body)

    return data.employee
  }
}

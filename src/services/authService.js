import { apiClient, setToken, getToken } from './apiClient.js'

/**
 * Wraps the auth endpoints. Keeps token handling in one place so views never
 * touch localStorage directly.
 */
export const authService = {
  /** @returns {Promise<{token: string, user: object}>} */
  async login(email, password) {
    const data = await apiClient.post(
      '/login',
      { email, password, device_name: 'web' },
      // No token exists yet, and sending a stale one would get it cleared by
      // the 401 handler before the login response is even read.
      { auth: false }
    )

    setToken(data.token)

    return data
  },

  /** Re-reads the signed-in user, e.g. on app boot to validate a stored token. */
  async me() {
    const data = await apiClient.get('/me')

    return data.user
  },

  async logout() {
    try {
      await apiClient.post('/logout')
    } catch {
      // A failed logout call must not strand the user in a signed-in UI —
      // an expired token is the most likely cause, and it is already invalid.
    } finally {
      setToken(null)
    }
  },

  isAuthenticated() {
    return Boolean(getToken())
  }
}

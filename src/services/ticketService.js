import { apiClient } from './apiClient.js'

export const ticketService = {
  /**
   * @returns {Promise<{data: Array, meta: object}>}
   *
   * Defaults to a large page because the kanban board renders every ticket in
   * a module at once — paging a board would hide columns rather than shorten
   * a list. The API caps this at 200 regardless.
   */
  async list(filters = {}, perPage = 200) {
    const params = new URLSearchParams(
      Object.entries({ ...filters, per_page: perPage })
        .filter(([, v]) => v !== '' && v != null)
    ).toString()

    return apiClient.get(`/tickets?${params}`)
  },

  async find(id) {
    const data = await apiClient.get(`/tickets/${id}`)

    return data.ticket
  },

  async create({ moduleId, title, description, status, priority, assigneeId, deadline }) {
    const data = await apiClient.post('/tickets', {
      moduleId, title, description, status, priority, assigneeId, deadline
    })

    return data.ticket
  },

  /**
   * Partial update. Only send what changed: the API treats a missing key as
   * "leave alone", so sending the whole ticket would overwrite fields another
   * user may have changed in the meantime.
   */
  async update(id, attributes) {
    const data = await apiClient.patch(`/tickets/${id}`, attributes)

    return data.ticket
  },

  async comment(id, body) {
    const data = await apiClient.post(`/tickets/${id}/comments`, { body })

    return data.ticket
  },

  /** Starting a timer stops any already running one, server-side. */
  async startTimer(id) {
    const data = await apiClient.post(`/tickets/${id}/start-timer`)

    return data.ticket
  },

  async stopTimer() {
    return apiClient.post('/tickets/stop-timer')
  }
}

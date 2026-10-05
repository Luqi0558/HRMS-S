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

  /** Uploads a file to a ticket. Returns the ticket with its attachments. */
  async attach(id, file) {
    const body = new FormData()
    body.append('file', file)

    const data = await apiClient.post(`/tickets/${id}/attachments`, body)

    return data.ticket
  },

  /** Downloads an attachment; behind auth, so fetched as a blob. */
  async downloadAttachment(ticketId, attachment) {
    const blob = await apiClient.blob(`/tickets/${ticketId}/attachments/${attachment.id}`)
    const url = URL.createObjectURL(blob)

    const link = document.createElement('a')
    link.href = url
    link.download = attachment.name || `attachment-${attachment.id}`
    link.click()

    URL.revokeObjectURL(url)
  },

  /** Starting a timer stops any already running one, server-side. */
  async startTimer(id) {
    const data = await apiClient.post(`/tickets/${id}/start-timer`)

    return data.ticket
  },

  /**
   * Returns the refreshed ticket so the caller can show the session that just
   * ended, or null when no timer was running.
   */
  async stopTimer() {
    const data = await apiClient.post('/tickets/stop-timer')

    return data.ticket ?? null
  }
}

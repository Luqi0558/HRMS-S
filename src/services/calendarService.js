import { apiClient } from './apiClient.js'

/** The server-owned, date-bounded company calendar feed. */
export const calendarService = {
  async events({ from, to, types = [] }) {
    const params = new URLSearchParams({ from, to })
    types.forEach(type => params.append('types[]', type))

    return apiClient.get('/calendar-events?' + params)
  }
}

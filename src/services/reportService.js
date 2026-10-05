import { apiClient } from './apiClient.js'

function queryString(filters = {}) {
  const query = new URLSearchParams(
    Object.entries(filters).filter(([, value]) => value !== '' && value != null && value !== 'all')
  ).toString()
  return query ? `?${query}` : ''
}

/** Server-authoritative operational reports and exports. */
export const reportService = {
  async list(type, filters = {}) {
    return apiClient.get(`/reports/${type}${queryString(filters)}`)
  },

  async download(type, filters = {}) {
    const blob = await apiClient.blob(`/reports/${type}/csv${queryString(filters)}`)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `${type}-report.csv`
    link.click()
    URL.revokeObjectURL(url)
  }
}

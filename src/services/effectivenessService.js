import { apiClient } from './apiClient.js'

export const effectivenessService = {
  mine({ from, to }) {
    return apiClient.get('/effectiveness/me?' + new URLSearchParams({ from, to }))
  },

  workforce({ from, to }) {
    return apiClient.get('/effectiveness/workforce?' + new URLSearchParams({ from, to }))
  },

  history(scope, months = 6) {
    return apiClient.get('/effectiveness/history?scope=' + encodeURIComponent(scope) + '&months=' + encodeURIComponent(months))
  },

  cycles() {
    return apiClient.get('/effectiveness/cycles')
  },

  reviewCycle(cycleId, note = '') {
    return apiClient.post('/effectiveness/cycles/' + cycleId + '/review', { note })
  },

  finalizeCycle(cycleId) {
    return apiClient.post('/effectiveness/cycles/' + cycleId + '/finalize')
  }
}

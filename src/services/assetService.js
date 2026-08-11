import { apiClient } from './apiClient.js'

/**
 * The asset register.
 *
 * An asset is held by at most one person at a time, and every handover leaves
 * a record. Issuing something already out is refused by the API rather than
 * silently reassigned — the previous holder would otherwise vanish from the
 * register while still physically having it.
 */
export const assetService = {
  async list(filters = {}) {
    const query = new URLSearchParams(
      Object.entries(filters).filter(([, v]) => v !== '' && v != null && v !== 'all')
    ).toString()

    const data = await apiClient.get(`/assets${query ? `?${query}` : ''}`)

    return data.data || []
  },

  async create({ name, category, serialNumber, condition, notes }) {
    const data = await apiClient.post('/assets', { name, category, serialNumber, condition, notes })

    return data.asset
  },

  async update(id, attributes) {
    const data = await apiClient.patch(`/assets/${id}`, attributes)

    return data.asset
  },

  /** Issues an asset. Rejected with 409 if it is already out. */
  async assign(id, { employeeId, issuedOn, returnDue, condition, notes }) {
    const data = await apiClient.post(`/assets/${id}/assign`, {
      employeeId, issuedOn, returnDue, condition, notes
    })

    return data.asset
  },

  /**
   * Records a return. The condition it comes back in becomes the asset's, so
   * the next person is issued what actually exists.
   */
  async returnAsset(id, { returnedOn, condition, notes } = {}) {
    const data = await apiClient.post(`/assets/${id}/return`, { returnedOn, condition, notes })

    return data.asset
  },

  /** Retires an asset. Refused while someone still holds it. */
  async retire(id) {
    const data = await apiClient.post(`/assets/${id}/retire`, {})

    return data.asset
  },

  /** Everyone who has held this asset, newest first. */
  async history(id) {
    const data = await apiClient.get(`/assets/${id}/history`)

    return data.data || []
  }
}

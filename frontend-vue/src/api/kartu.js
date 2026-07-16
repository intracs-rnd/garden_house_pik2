import api from './axios'

/**
 * Access card (kartu) endpoints.
 *
 * Covers the standard apiResource "kartu" plus the custom gate actions
 * (tab-in / tab-out), status checks, blacklist toggles and access logs.
 */
export default {
  /** GET /api/kartu */
  list(params = {}) {
    return api.get('/kartu', { params }).then((res) => res.data)
  },

  /** GET /api/kartu/{id} */
  get(id) {
    return api.get(`/kartu/${id}`).then((res) => res.data)
  },

  /** POST /api/kartu */
  create(payload) {
    return api.post('/kartu', payload).then((res) => res.data)
  },

  /** PUT /api/kartu/{id} */
  update(id, payload) {
    return api.put(`/kartu/${id}`, payload).then((res) => res.data)
  },

  /** DELETE /api/kartu/{id} */
  remove(id) {
    return api.delete(`/kartu/${id}`).then((res) => res.data)
  },

  /** POST /api/kartu/tab-in — gate entry tap. */
  tabIn(payload) {
    return api.post('/kartu/tab-in', payload).then((res) => res.data)
  },

  /** POST /api/kartu/tab-out — gate exit tap. */
  tabOut(payload) {
    return api.post('/kartu/tab-out', payload).then((res) => res.data)
  },

  /** POST /api/kartu/status-check — check access status by card number. */
  statusByNumber(payload) {
    return api.post('/kartu/status-check', payload).then((res) => res.data)
  },

  /** GET /api/kartu/{id}/status — access status by id. */
  status(id) {
    return api.get(`/kartu/${id}/status`).then((res) => res.data)
  },

  /** GET /api/kartu/{id}/logs — paginated tap history. */
  logs(id, params = {}) {
    return api.get(`/kartu/${id}/logs`, { params }).then((res) => res.data)
  },

  /** GET /api/kartu-logs — recent tap history across all cards. */
  accessLogs(params = {}) {
    return api.get('/kartu-logs', { params }).then((res) => res.data)
  },

  /** POST /api/kartu/{id}/blacklist */
  blacklist(id, payload = {}) {
    return api.post(`/kartu/${id}/blacklist`, payload).then((res) => res.data)
  },

  /** POST /api/kartu/{id}/clear-blacklist */
  clearBlacklist(id) {
    return api.post(`/kartu/${id}/clear-blacklist`).then((res) => res.data)
  },
}

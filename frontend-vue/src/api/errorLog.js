import api from './axios'

/**
 * Application error / bug log endpoints (SUPER ADMIN only).
 *
 * The download call requests a binary blob so the Sanctum bearer token stays
 * attached by the axios interceptor.
 */
export default {
  /** GET /api/error-logs — paginated list. */
  list(params = {}) {
    return api.get('/error-logs', { params }).then((res) => res.data)
  },

  /** GET /api/error-logs/{id} — full detail incl. stack trace. */
  get(id) {
    return api.get(`/error-logs/${id}`).then((res) => res.data)
  },

  /** GET /api/error-logs/download — export as CSV (default) or JSON blob. */
  download(format = 'csv') {
    return api
      .get('/error-logs/download', { params: { format }, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** DELETE /api/error-logs — clear all logs. */
  clear() {
    return api.delete('/error-logs').then((res) => res.data)
  },
}

import api from './axios'

/**
 * Vehicle (kendaraan) resource endpoints (apiResource "kendaraan").
 */
export default {
  /** GET /api/kendaraan */
  list(params = {}) {
    return api.get('/kendaraan', { params }).then((res) => res.data)
  },

  /** GET /api/kendaraan/{id} */
  get(id) {
    return api.get(`/kendaraan/${id}`).then((res) => res.data)
  },

  /** POST /api/kendaraan */
  create(payload) {
    return api.post('/kendaraan', payload).then((res) => res.data)
  },

  /** PUT /api/kendaraan/{id} */
  update(id, payload) {
    return api.put(`/kendaraan/${id}`, payload).then((res) => res.data)
  },

  /** DELETE /api/kendaraan/{id} */
  remove(id) {
    return api.delete(`/kendaraan/${id}`).then((res) => res.data)
  },
}

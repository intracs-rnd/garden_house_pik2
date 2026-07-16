import api from './axios'

/**
 * User resource endpoints (apiResource "users").
 */
export default {
  /** GET /api/users */
  list(params = {}) {
    return api.get('/users', { params }).then((res) => res.data)
  },

  /** GET /api/users/{id} */
  get(id) {
    return api.get(`/users/${id}`).then((res) => res.data)
  },

  /** POST /api/users */
  create(payload) {
    return api.post('/users', payload).then((res) => res.data)
  },

  /** PUT /api/users/{id} */
  update(id, payload) {
    return api.put(`/users/${id}`, payload).then((res) => res.data)
  },

  /** DELETE /api/users/{id} */
  remove(id) {
    return api.delete(`/users/${id}`).then((res) => res.data)
  },
}

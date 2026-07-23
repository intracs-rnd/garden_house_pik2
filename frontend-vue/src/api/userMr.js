import api from './axios'

/**
 * User MR resource endpoints (apiResource "user-mr").
 * Only accessible to superadmin role.
 */
export default {
  /** GET /api/user-mr */
  list(params = {}) {
    return api.get('/user-mr', { params }).then((res) => res.data)
  },

  /** GET /api/user-mr/{uuid} */
  get(uuid) {
    return api.get(`/user-mr/${uuid}`).then((res) => res.data)
  },

  /** POST /api/user-mr */
  create(payload) {
    return api.post('/user-mr', payload).then((res) => res.data)
  },

  /** PUT /api/user-mr/{uuid} */
  update(uuid, payload) {
    return api.put(`/user-mr/${uuid}`, payload).then((res) => res.data)
  },

  /** DELETE /api/user-mr/{uuid} */
  remove(uuid) {
    return api.delete(`/user-mr/${uuid}`).then((res) => res.data)
  },
}

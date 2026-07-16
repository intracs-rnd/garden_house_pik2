import api from './axios'

/**
 * Access control (RBAC) endpoints.
 * All responses follow the API envelope: { success, message, data }.
 */
export default {
  /** GET /api/access-control -> { features, roles, permissions } */
  getMatrix() {
    return api.get('/access-control').then((res) => res.data)
  },

  /** PUT /api/access-control -> updates one role's permissions */
  update(payload) {
    return api.put('/access-control', payload).then((res) => res.data)
  },
}

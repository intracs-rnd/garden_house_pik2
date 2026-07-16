import api from './axios'

/**
 * Category endpoints (read-only on the backend).
 */
export default {
  /** GET /api/categories */
  list() {
    return api.get('/categories').then((res) => res.data)
  },

  /** GET /api/categories/{id} */
  get(id) {
    return api.get(`/categories/${id}`).then((res) => res.data)
  },
}

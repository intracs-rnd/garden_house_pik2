import api from './axios'

/**
 * Dashboard statistics endpoint.
 */
export default {
  /** GET /api/dashboard */
  stats() {
    return api.get('/dashboard').then((res) => res.data)
  },

  /** GET /api/dashboard/activity-trends */
  activityTrends() {
    return api.get('/dashboard/activity-trends').then((res) => res.data)
  },
}

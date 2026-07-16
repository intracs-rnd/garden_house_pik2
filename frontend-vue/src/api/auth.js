import api from './axios'

/**
 * Authentication endpoints.
 * All responses follow the API envelope: { success, message, data }.
 */
export default {
  /** POST /api/login */
  login(credentials) {
    return api.post('/login', credentials).then((res) => res.data)
  },

  /** POST /api/register */
  register(payload) {
    return api.post('/register', payload).then((res) => res.data)
  },

  /** POST /api/forgot-password */
  forgotPassword(payload) {
    return api.post('/forgot-password', payload).then((res) => res.data)
  },

  /** POST /api/reset-password */
  resetPassword(payload) {
    return api.post('/reset-password', payload).then((res) => res.data)
  },

  /** GET /api/me */
  me() {
    return api.get('/me').then((res) => res.data)
  },

  /** POST /api/change-password */
  changePassword(payload) {
    return api.post('/change-password', payload).then((res) => res.data)
  },

  /** POST /api/logout */
  logout() {
    return api.post('/logout').then((res) => res.data)
  },
}

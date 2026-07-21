import axios from 'axios'

/**
 * Central Axios instance for the GH PIK2 Laravel API.
 *
 * - baseURL comes from VITE_API_BASE_URL (defaults to the local Laravel server).
 * - A request interceptor attaches the Sanctum bearer token.
 * - A response interceptor normalises errors and handles expired sessions.
 */
const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

const TOKEN_KEY = 'gh_pik2_token'

export function getToken() {
  return localStorage.getItem(TOKEN_KEY)
}

export function setToken(token) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
  } else {
    localStorage.removeItem(TOKEN_KEY)
  }
}

// Attach the bearer token to every outgoing request.
api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Handle common error cases in one place.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status

    // Session expired / invalid token -> force a clean logout.
    if (status === 401) {
      setToken(null)
      localStorage.removeItem('gh_pik2_user')
      if (window.location.pathname !== '/login') {
        window.location.assign('/login?reason=session_expired')
      }
    }

    return Promise.reject(error)
  },
)

export default api

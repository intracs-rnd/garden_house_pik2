import { defineStore } from 'pinia'
import authApi from '@/api/auth'
import { getToken, setToken } from '@/api/axios'
import { extractErrorMessage } from '@/utils/helper'

const USER_KEY = 'gh_pik2_user'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: null,
    loading: false,
    error: null,
  }),

  getters: {
    isAuthenticated: (state) => !!state.token,
    isAdmin: (state) => ['admin', 'superadmin'].includes(state.user?.role),
    isSuperAdmin: (state) => state.user?.role === 'superadmin',
    /** Feature permission map: { feature_key: 'view' | 'manage' }. */
    permissions: (state) => state.user?.permissions || {},
    /**
     * Whether the current user can at least *view* a feature.
     * Super admin implicitly has access to everything.
     */
    hasFeature: (state) => (key) => {
      if (state.user?.role === 'superadmin') return true
      return !!(state.user?.permissions || {})[key]
    },
    /**
     * Whether the current user can *manage* (perform actions on) a feature.
     * Super admin implicitly has full access.
     */
    canManage: (state) => (key) => {
      if (state.user?.role === 'superadmin') return true
      return (state.user?.permissions || {})[key] === 'manage'
    },
    userName: (state) => state.user?.name || 'Pengguna',
    userInitials: (state) => {
      const name = state.user?.name || 'U'
      return name
        .split(' ')
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('')
    },
  },

  actions: {
    /** Restore session from localStorage (called once on app start). */
    bootstrap() {
      this.token = getToken()
      const stored = localStorage.getItem(USER_KEY)
      if (stored) {
        try {
          this.user = JSON.parse(stored)
        } catch {
          this.user = null
        }
      }
    },

    persistUser() {
      if (this.user) {
        localStorage.setItem(USER_KEY, JSON.stringify(this.user))
      } else {
        localStorage.removeItem(USER_KEY)
      }
    },

    setSession(user, token) {
      this.user = user
      this.token = token
      setToken(token)
      this.persistUser()
    },

    async login(credentials) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.login(credentials)
        this.setSession(res.data.user, res.data.token)
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Login gagal.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async register(payload) {
      this.loading = true
      this.error = null
      try {
        const res = await authApi.register(payload)
        this.setSession(res.data.user, res.data.token)
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Registrasi gagal.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchUser() {
      try {
        const res = await authApi.me()
        this.user = res.data
        this.persistUser()
        return res
      } catch (error) {
        throw error
      }
    },

    async forgotPassword(payload) {
      this.loading = true
      this.error = null
      try {
        return await authApi.forgotPassword(payload)
      } catch (error) {
        this.error = extractErrorMessage(error, 'Email anda tidak ada di sistem.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async resetPassword(payload) {
      this.loading = true
      this.error = null
      try {
        return await authApi.resetPassword(payload)
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal mereset password.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async changePassword(payload) {
      this.loading = true
      this.error = null
      try {
        return await authApi.changePassword(payload)
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal mengubah password.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async logout() {
      try {
        await authApi.logout()
      } catch {
        // Ignore network/api errors on logout — clear locally anyway.
      } finally {
        this.clearSession()
      }
    },

    clearSession() {
      this.user = null
      this.token = null
      this.error = null
      setToken(null)
      localStorage.removeItem(USER_KEY)
    },
  },
})

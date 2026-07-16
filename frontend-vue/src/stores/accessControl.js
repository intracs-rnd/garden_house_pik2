import { defineStore } from 'pinia'
import accessControlApi from '@/api/accessControl'
import { extractErrorMessage } from '@/utils/helper'

/**
 * Role label mapping for display.
 */
export const ROLE_LABELS = {
  superadmin: 'Super Admin',
  admin: 'Admin',
  staff: 'Staff',
  user: 'User',
}

/**
 * Access level options shown per feature.
 */
export const ACCESS_OPTIONS = [
  { value: '', label: 'Tidak ada akses' },
  { value: 'view', label: 'Hanya lihat' },
  { value: 'manage', label: 'Kelola (aksi penuh)' },
]

export const useAccessControlStore = defineStore('accessControl', {
  state: () => ({
    features: [],
    roles: [],
    // { role: { feature_key: 'view' | 'manage' } }
    permissions: {},
    loading: false,
    saving: false,
    error: null,
  }),

  actions: {
    async fetchMatrix() {
      this.loading = true
      this.error = null
      try {
        const res = await accessControlApi.getMatrix()
        this.features = res.data.features || []
        this.roles = res.data.roles || []
        this.permissions = res.data.permissions || {}
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data hak akses.')
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Save the permissions for a single role.
     * @param {string} role
     * @param {Record<string,string>} featureMap  { feature_key: 'view'|'manage'|'' }
     */
    async saveRole(role, featureMap) {
      this.saving = true
      try {
        const permissions = Object.entries(featureMap)
          .filter(([, access]) => access === 'view' || access === 'manage')
          .map(([feature_key, access]) => ({ feature_key, access }))

        const res = await accessControlApi.update({ role, permissions })
        this.features = res.data.features || this.features
        this.roles = res.data.roles || this.roles
        this.permissions = res.data.permissions || this.permissions
        return res
      } finally {
        this.saving = false
      }
    },
  },
})

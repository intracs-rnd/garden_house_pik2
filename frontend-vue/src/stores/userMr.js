import { defineStore } from 'pinia'
import userMrApi from '@/api/userMr'
import { extractErrorMessage } from '@/utils/helper'

export const useUserMrStore = defineStore('userMr', {
  state: () => ({
    items: [],
    current: null,
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    filters: { search: '' },
    loading: false,
    refreshing: false,
    saving: false,
    error: null,
    pageCache: new Map(),
  }),

  actions: {
    _cacheKey(page) {
      return `${this.filters.search || ''}|${this.meta.per_page}|${page}`
    },

    _requestParams(page) {
      return {
        page,
        per_page: this.meta.per_page,
        search: this.filters.search || undefined,
      }
    },

    clearCache() {
      this.pageCache.clear()
    },

    setPerPage(perPage) {
      const n = Number(perPage) || 10
      if (n === this.meta.per_page) return
      this.meta.per_page = n
      this.meta.current_page = 1
      return this.fetchList(1)
    },

    async fetchList(page = 1, { force = false } = {}) {
      const key = this._cacheKey(page)
      const cached = this.pageCache.get(key)

      if (cached && !force) {
        this.items = cached.data
        this.meta = cached.meta
        this.error = null
        this._revalidate(page, key)
        return cached
      }

      this.loading = true
      this.error = null
      try {
        const res = await userMrApi.list(this._requestParams(page))
        this.items = res.data
        if (res.meta) this.meta = res.meta
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data user MR.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async _revalidate(page, key) {
      if (this.refreshing) return
      this.refreshing = true
      try {
        const res = await userMrApi.list(this._requestParams(page))
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        if (this._cacheKey(this.meta.current_page) === key) {
          this.items = res.data
          if (res.meta) this.meta = res.meta
        }
      } catch {
        // Silent: keep showing cached data on background failures.
      } finally {
        this.refreshing = false
      }
    },

    async fetchOne(uuid) {
      try {
        const res = await userMrApi.get(uuid)
        this.current = res.data
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat user MR.')
        throw error
      }
    },

    async create(payload) {
      this.saving = true
      try {
        const res = await userMrApi.create(payload)
        this.clearCache()
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal membuat user MR.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async update(uuid, payload) {
      this.saving = true
      try {
        const res = await userMrApi.update(uuid, payload)
        this.current = res.data
        this.clearCache()
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal mengubah user MR.')
        throw error
      } finally {
        this.saving = false
      }
    },

    async remove(uuid) {
      try {
        const res = await userMrApi.remove(uuid)
        this.clearCache()
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal menghapus user MR.')
        throw error
      }
    },
  },
})

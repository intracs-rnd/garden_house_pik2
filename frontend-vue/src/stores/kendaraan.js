import { defineStore } from 'pinia'
import kendaraanApi from '@/api/kendaraan'
import userApi from '@/api/user'
import { extractErrorMessage } from '@/utils/helper'

export const useKendaraanStore = defineStore('kendaraan', {
  state: () => ({
    items: [],
    current: null,
    users: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    filters: { search: '', status: '' },
    loading: false,
    // Background revalidation (data already on screen, silently refreshing).
    refreshing: false,
    saving: false,
    error: null,
    // Cache of already-fetched pages keyed by search|status|per_page|page so
    // navigating back to a page does not trigger a heavy full reload.
    pageCache: new Map(),
  }),

  actions: {
    _cacheKey(page) {
      const { search, status } = this.filters
      return `${search || ''}|${status || ''}|${this.meta.per_page}|${page}`
    },

    _requestParams(page) {
      return {
        page,
        per_page: this.meta.per_page,
        search: this.filters.search || undefined,
        status: this.filters.status || undefined,
      }
    },

    clearCache() {
      this.pageCache.clear()
    },

    /**
     * Change how many rows are shown per page and reload from page 1.
     * The cache key already includes per_page, so previously fetched
     * page sizes stay cached and switching back is instant.
     */
    setPerPage(perPage) {
      const n = Number(perPage) || 10
      if (n === this.meta.per_page) return
      this.meta.per_page = n
      this.meta.current_page = 1
      return this.fetchList(1)
    },

    /**
     * Fetch a page. If it is already cached it renders instantly and is
     * revalidated in the background (stale-while-revalidate), so the list
     * never blocks on a slow request when the data is already available.
     */
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
        const res = await kendaraanApi.list(this._requestParams(page))
        this.items = res.data
        if (res.meta) this.meta = res.meta
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data kendaraan.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async _revalidate(page, key) {
      if (this.refreshing) return
      this.refreshing = true
      try {
        const res = await kendaraanApi.list(this._requestParams(page))
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        // Only apply if the user is still viewing this exact page/filters.
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

    async fetchOne(id) {
      this.loading = true
      this.error = null
      try {
        const res = await kendaraanApi.get(id)
        this.current = res.data
        return res.data
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat kendaraan.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async fetchUsers() {
      if (this.users.length) return this.users
      try {
        const res = await userApi.list({ per_page: 100 })
        this.users = res.data
        return res.data
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data pengguna.')
        return []
      }
    },


    async create(payload) {
      this.saving = true
      try {
        const res = await kendaraanApi.create(payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async update(id, payload) {
      this.saving = true
      try {
        const res = await kendaraanApi.update(id, payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async remove(id) {
      const res = await kendaraanApi.remove(id)
      this.clearCache()
      return res
    },

    resetFilters() {
      this.filters = { search: '', status: '' }
      this.clearCache()
    },
  },
})

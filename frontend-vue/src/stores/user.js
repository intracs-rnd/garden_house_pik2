import { defineStore } from 'pinia'
import userApi from '@/api/user'
import { extractErrorMessage } from '@/utils/helper'

export const useUserStore = defineStore('user', {
  state: () => ({
    items: [],
    current: null,
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    filters: { search: '' },
    loading: false,
    // Background revalidation (data already on screen, silently refreshing).
    refreshing: false,
    saving: false,
    error: null,
    // Cache of already-fetched pages keyed by search|per_page|page so
    // navigating back to a page does not trigger a heavy full reload.
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
        const res = await userApi.list(this._requestParams(page))
        this.items = res.data
        if (res.meta) this.meta = res.meta
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data pengguna.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async _revalidate(page, key) {
      if (this.refreshing) return
      this.refreshing = true
      try {
        const res = await userApi.list(this._requestParams(page))
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
        const res = await userApi.get(id)
        this.current = res.data
        return res.data
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat pengguna.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async create(payload) {
      this.saving = true
      try {
        const res = await userApi.create(payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async update(id, payload) {
      this.saving = true
      try {
        const res = await userApi.update(id, payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async remove(id) {
      const res = await userApi.remove(id)
      this.clearCache()
      return res
    },

    setSearch(value) {
      this.filters.search = value
      this.clearCache()
    },
  },
})

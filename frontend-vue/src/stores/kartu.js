import { defineStore } from 'pinia'
import kartuApi from '@/api/kartu'
import userApi from '@/api/user'
import { extractErrorMessage } from '@/utils/helper'

export const useKartuStore = defineStore('kartu', {
  state: () => ({
    items: [],
    current: null,
    users: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    filters: { search: '', status: '', is_blacklisted: '' },
    loading: false,
    // Background revalidation (data already on screen, silently refreshing).
    refreshing: false,
    saving: false,
    error: null,
    // Tab filter: 'active' or 'deleted'
    activeTab: 'active',
    // Cache of already-fetched pages keyed by the active filters + per_page +
    // page so navigating back to a page does not trigger a heavy full reload.
    pageCache: new Map(),
  }),

  actions: {
    _cacheKey(page) {
      const { search, status, is_blacklisted } = this.filters
      const includeDeleted = this.activeTab === 'deleted' ? '1' : '0'
      return `${search || ''}|${status || ''}|${is_blacklisted}|${this.meta.per_page}|${page}|${includeDeleted}`
    },

    _requestParams(page) {
      return {
        page,
        per_page: this.meta.per_page,
        search: this.filters.search || undefined,
        status: this.filters.status || undefined,
        is_blacklisted:
          this.filters.is_blacklisted !== '' ? this.filters.is_blacklisted : undefined,
        include_deleted: this.activeTab === 'deleted' ? true : undefined,
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
        const res = await kartuApi.list(this._requestParams(page))
        this.items = res.data
        if (res.meta) this.meta = res.meta
        this.pageCache.set(key, { data: res.data, meta: res.meta || { ...this.meta } })
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data kartu akses.')
        throw error
      } finally {
        this.loading = false
      }
    },

    async _revalidate(page, key) {
      if (this.refreshing) return
      this.refreshing = true
      try {
        const res = await kartuApi.list(this._requestParams(page))
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
        const res = await kartuApi.get(id)
        this.current = res.data
        return res.data
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat kartu akses.')
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
        const res = await kartuApi.create(payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async update(id, payload) {
      this.saving = true
      try {
        const res = await kartuApi.update(id, payload)
        this.clearCache()
        return res
      } finally {
        this.saving = false
      }
    },

    async remove(id) {
      const res = await kartuApi.remove(id)
      this.clearCache()
      return res
    },

    async blacklist(id, reason) {
      const res = await kartuApi.blacklist(id, { reason })
      this.clearCache()
      return res
    },

    async clearBlacklist(id) {
      const res = await kartuApi.clearBlacklist(id)
      this.clearCache()
      return res
    },

    async tabIn(cardNumber, gate, noPlat) {
      const res = await kartuApi.tabIn({
        card_number: cardNumber,
        gate: gate || undefined,
        no_plat: noPlat || undefined,
      })
      return res.data
    },

    async tabOut(cardNumber, gate, noPlat) {
      const res = await kartuApi.tabOut({
        card_number: cardNumber,
        gate: gate || undefined,
        no_plat: noPlat || undefined,
      })
      return res.data
    },

    async checkStatus(cardNumber) {
      const res = await kartuApi.statusByNumber({ card_number: cardNumber })
      return res.data
    },

    async fetchLogs(id, page = 1, perPage = 10) {
      const res = await kartuApi.logs(id, { page, per_page: perPage })
      return res
    },

    async fetchRecentLogs(params = {}) {
      return kartuApi.accessLogs(params)
    },

    resetFilters() {
      this.filters = { search: '', status: '', is_blacklisted: '' }
      this.clearCache()
    },

    setActiveTab(tab) {
      if (this.activeTab !== tab) {
        this.activeTab = tab
        this.clearCache()
      }
    },
  },
})

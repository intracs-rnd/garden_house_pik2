import { defineStore } from 'pinia'
import iuranApi from '@/api/iuran'
import { extractErrorMessage } from '@/utils/helper'

export const useIuranStore = defineStore('iuran', {
  state: () => ({
    // Daftar tagihan iuran
    items: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    filters: {
      no_kk: '',
      periode: '',
      status: '',
    },

    // Riwayat pembayaran
    history: [],
    historyMeta: { current_page: 1, per_page: 10, total: 0, last_page: 1 },
    historyFilters: { no_kk: '' },

    // State UI
    loading: false,
    historyLoading: false,
    saving: false,
    paying: false,
    generating: false,
    error: null,

    // Detail tagihan yang sedang dilihat
    current: null,
  }),

  actions: {
    // -----------------------------------------------------------------------
    // Fetch list tagihan
    // -----------------------------------------------------------------------
    async fetchList(page = 1) {
      this.loading = true
      this.error = null
      try {
        const params = {
          page,
          per_page: this.meta.per_page,
          no_kk: this.filters.no_kk || undefined,
          periode: this.filters.periode || undefined,
          status: this.filters.status || undefined,
        }
        const res = await iuranApi.list(params)
        this.items = res.data
        if (res.meta) this.meta = res.meta
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat data iuran.')
        throw error
      } finally {
        this.loading = false
      }
    },

    setPerPage(perPage) {
      const n = Number(perPage) || 10
      if (n === this.meta.per_page) return
      this.meta.per_page = n
      this.meta.current_page = 1
      return this.fetchList(1)
    },

    // -----------------------------------------------------------------------
    // Fetch satu tagihan
    // -----------------------------------------------------------------------
    async fetchOne(id) {
      this.loading = true
      this.error = null
      try {
        const res = await iuranApi.get(id)
        this.current = res.data
        return res.data
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat detail iuran.')
        throw error
      } finally {
        this.loading = false
      }
    },

    // -----------------------------------------------------------------------
    // CRUD (admin only)
    // -----------------------------------------------------------------------
    async create(payload) {
      this.saving = true
      try {
        const res = await iuranApi.create(payload)
        await this.fetchList(1)
        return res
      } finally {
        this.saving = false
      }
    },

    async update(id, payload) {
      this.saving = true
      try {
        const res = await iuranApi.update(id, payload)
        await this.fetchList(this.meta.current_page)
        return res
      } finally {
        this.saving = false
      }
    },

    async remove(id) {
      const res = await iuranApi.remove(id)
      const page =
        this.items.length === 1 && this.meta.current_page > 1
          ? this.meta.current_page - 1
          : this.meta.current_page
      await this.fetchList(page)
      return res
    },

    // -----------------------------------------------------------------------
    // Bayar iuran (warga)
    // -----------------------------------------------------------------------
    async pay(id, payload = {}) {
      this.paying = true
      try {
        const res = await iuranApi.pay(id, payload)
        // Refresh list setelah bayar agar status terupdate
        await this.fetchList(this.meta.current_page)
        return res
      } finally {
        this.paying = false
      }
    },

    // -----------------------------------------------------------------------
    // Generate batch tagihan (admin only)
    // -----------------------------------------------------------------------
    async generate(payload) {
      this.generating = true
      try {
        const res = await iuranApi.generate(payload)
        await this.fetchList(1)
        return res
      } finally {
        this.generating = false
      }
    },

    // -----------------------------------------------------------------------
    // Riwayat pembayaran
    // -----------------------------------------------------------------------
    async fetchHistory(page = 1) {
      this.historyLoading = true
      try {
        const params = {
          page,
          per_page: this.historyMeta.per_page,
          no_kk: this.historyFilters.no_kk || undefined,
        }
        const res = await iuranApi.history(params)
        this.history = res.data
        if (res.meta) this.historyMeta = res.meta
        return res
      } catch (error) {
        this.error = extractErrorMessage(error, 'Gagal memuat riwayat pembayaran.')
        throw error
      } finally {
        this.historyLoading = false
      }
    },

    setHistoryPerPage(perPage) {
      const n = Number(perPage) || 10
      if (n === this.historyMeta.per_page) return
      this.historyMeta.per_page = n
      this.historyMeta.current_page = 1
      return this.fetchHistory(1)
    },

    // -----------------------------------------------------------------------
    // Reset
    // -----------------------------------------------------------------------
    resetFilters() {
      this.filters = { no_kk: '', periode: '', status: '' }
      this.fetchList(1)
    },
  },
})

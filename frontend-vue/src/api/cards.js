import api from './axios'

/**
 * Cards (tabel cards — bukan kartus) endpoints.
 * Khusus Super Admin.
 */
export default {
  /**
   * GET /api/cards
   * Daftar semua cards dengan paginasi & pencarian.
   */
  list(params = {}) {
    return api.get('/cards', { params }).then((res) => res.data)
  },

  /**
   * PUT /api/cards/manage/{id}
   * Update data card (name, unit, status, expiry, grace_days).
   */
  update(id, payload) {
    return api.put(`/cards/manage/${id}`, payload).then((res) => res.data)
  },

  /**
   * DELETE /api/cards/manage/{id}
   * Hapus card.
   */
  remove(id) {
    return api.delete(`/cards/manage/${id}`).then((res) => res.data)
  },

  /**
   * POST /api/cards/import
   * Upload CSV file untuk import bulk cards.
   * @param {File} file  — CSV file
   * @param {Function} onProgress — optional upload progress callback (0–100)
   */
  importCsv(file, onProgress) {
    const form = new FormData()
    form.append('file', file)
    return api
      .post('/cards/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: onProgress
          ? (e) => onProgress(Math.round((e.loaded * 100) / e.total))
          : undefined,
      })
      .then((res) => res.data)
  },
}

import api from './axios'

/**
 * Iuran Perumahan API endpoints.
 *
 * - Admin / Super Admin : CRUD tagihan + generate batch + lihat semua riwayat
 * - Warga               : lihat tagihan KK sendiri + bayar + lihat riwayat KK sendiri
 */
export default {
  /** GET /api/iuran — daftar tagihan (role-aware di backend) */
  list(params = {}) {
    return api.get('/iuran', { params }).then((res) => res.data)
  },

  /** GET /api/iuran/{id} — detail tagihan */
  get(id) {
    return api.get(`/iuran/${id}`).then((res) => res.data)
  },

  /** GET /api/iuran/history — riwayat pembayaran */
  history(params = {}) {
    return api.get('/iuran/history', { params }).then((res) => res.data)
  },

  /** POST /api/iuran — buat tagihan baru (admin only) */
  create(payload) {
    return api.post('/iuran', payload).then((res) => res.data)
  },

  /** PUT /api/iuran/{id} — update tagihan (admin only) */
  update(id, payload) {
    return api.put(`/iuran/${id}`, payload).then((res) => res.data)
  },

  /** DELETE /api/iuran/{id} — hapus tagihan (admin only) */
  remove(id) {
    return api.delete(`/iuran/${id}`).then((res) => res.data)
  },

  /** POST /api/iuran/{id}/pay — warga bayar iuran */
  pay(id, payload = {}) {
    // If payload is FormData, send as multipart/form-data
    const isFormData = typeof FormData !== 'undefined' && payload instanceof FormData
    if (isFormData) {
      return api.post(`/iuran/${id}/pay`, payload, { headers: { 'Content-Type': 'multipart/form-data' } }).then((res) => res.data)
    }

    return api.post(`/iuran/${id}/pay`, payload).then((res) => res.data)
  },

  /** POST /api/iuran/pembayaran/{id}/approve — superadmin approve pembayaran */
  approvePayment(id) {
    return api.post(`/iuran/pembayaran/${id}/approve`).then((res) => res.data)
  },

  /** POST /api/iuran/generate — generate batch tagihan per periode (admin only) */
  generate(payload) {
    return api.post('/iuran/generate', payload).then((res) => res.data)
  },
}

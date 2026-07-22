import api from './axios'

/**
 * Transaction report endpoints (recap & detail, per day / month / year).
 *
 * The `*Pdf` calls request a binary blob so the Sanctum bearer token is still
 * attached by the axios interceptor (a plain window.open could not send it).
 */
export default {
  /** GET /api/reports/recap — aggregated recap preview (JSON). */
  recap(params = {}) {
    return api.get('/reports/recap', { params }).then((res) => res.data)
  },

  /** GET /api/reports/detail — per-tap detail preview (JSON). */
  detail(params = {}) {
    return api.get('/reports/detail', { params }).then((res) => res.data)
  },

  /** GET /api/reports/recap/pdf — recap as a PDF blob. */
  recapPdf(params = {}) {
    return api
      .get('/reports/recap/pdf', { params, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** GET /api/reports/detail/pdf — detail as a PDF blob. */
  detailPdf(params = {}) {
    return api
      .get('/reports/detail/pdf', { params, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** GET /api/reports/recap/excel — recap as an Excel (.xlsx) blob. */
  recapExcel(params = {}) {
    return api
      .get('/reports/recap/excel', { params, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** GET /api/reports/detail/excel — detail as an Excel (.xlsx) blob. */
  detailExcel(params = {}) {
    return api
      .get('/reports/detail/excel', { params, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** GET /api/reports/gate-control — gate control log (log_gate + gate_manual_control) per period. */
  gateControl(params = {}) {
    return api.get('/reports/gate-control', { params }).then((res) => res.data)
  },

  /** GET /api/reports/gate-control/pdf — gate control as a PDF blob. */
  gateControlPdf(params = {}) {
    return api
      .get('/reports/gate-control/pdf', { params, responseType: 'blob' })
      .then((res) => res.data)
  },

  /** GET /api/reports/gate-control/excel — gate control as an Excel (.xlsx) blob. */
  gateControlExcel(params = {}) {
    return api
      .get('/reports/gate-control/excel', { params, responseType: 'blob' })
      .then((res) => res.data)
  },
}

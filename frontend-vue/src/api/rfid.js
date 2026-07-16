import api from './axios'

/**
 * RFID gate reader connection status endpoint.
 *
 * Backed by the `log_rfid_conn` heartbeat table; used by the dashboard to show
 * whether each gate's RFID reader is currently connected.
 */
export default {
  /** GET /api/rfid-conn/status */
  connStatus() {
    return api.get('/rfid-conn/status').then((res) => res.data)
  },

  /** GET /api/rfid-conn/history/:gateId */
  connHistory(gateId, params = {}) {
    return api.get(`/rfid-conn/history/${gateId}`, { params }).then((res) => res.data)
  },
}

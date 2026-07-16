import axios from './axios'

export const gateApi = {
  /**
   * Log gate action (subscribe from MQTT)
   * @param {object} data - { gate_id, open, event_ts? }
   */
  logGateAction: (data) => axios.post('/gate/log', data),

  /**
   * Log manual gate control (ketika user klik Buka/Tutup gate dari dashboard)
   * @param {object} data - { gate_id, nomor_plat, action, notes? }
   */
  logManualControl: (data) => axios.post('/gate/manual-control', data),

  /**
   * Get all gate logs
   * @param {object} params - { limit?, gate_id? }
   */
  getAllLogs: (params = {}) => axios.get('/gate/logs', { params }),

  /**
   * Get logs for specific gate with pagination
   * @param {string} gateId
   * @param {object} params - { page?, per_page? }
   */
  getLogsByGateId: (gateId, params = {}) => axios.get(`/gate/logs/${gateId}`, { params }),
}

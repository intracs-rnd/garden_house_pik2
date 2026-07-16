import api from './axios'

/**
 * Live-CCTV camera configuration endpoints.
 * All responses follow the API envelope: { success, message, data }.
 */
export default {
  /** GET /api/cameras -> { cameras: [{ path, name, rtsp_url, enabled, stream_url }] } */
  getCameras() {
    return api.get('/cameras').then((res) => res.data)
  },

  /** GET /api/cameras/feeds -> { cameras: [{ path, name, stream_url, enabled }] } (no RTSP) */
  getFeeds() {
    return api.get('/cameras/feeds').then((res) => res.data)
  },

  /** PUT /api/cameras -> { cameras, apply } */
  updateCameras(cameras) {
    return api.put('/cameras', { cameras }).then((res) => res.data)
  },

  /** POST /api/cameras/apply -> re-push stored config to go2rtc */
  apply() {
    return api.post('/cameras/apply').then((res) => res.data)
  },
}

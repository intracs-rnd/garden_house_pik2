import { ref } from 'vue'
import { useMqtt } from './useMqtt'
import { gateApi } from '@/api/gate'

/**
 * Composable untuk mengontrol gate via MQTT dan logging
 */
export function useGateControl() {
  const { isConnected, connect, publish } = useMqtt(null, { autoConnect: false })
  const isPublishing = ref(false)
  const publishError = ref(null)
  const isConnecting = ref(false)

  const TOPIC_GATE_CMD = 'gate/in/cmd'

  /**
   * Publish gate action ke MQTT dan log ke database
   * @param {string} gateId - Gate ID (e.g., "GATE_IN_01")
   * @param {boolean} open - true untuk buka, false untuk tutup
   * @param {object} options - { nomor_plat?, notes? }
   */
  const publishGateAction = async (gateId, open, options = {}) => {
    isPublishing.value = true
    publishError.value = null

    try {
      // Auto-connect jika belum terhubung
      if (!isConnected.value) {
        isConnecting.value = true
        console.log('⏳ Connecting to MQTT...')
        await connect()
        isConnecting.value = false
        console.log('✅ MQTT connected')
      }

      if (!isConnected.value) {
        throw new Error('MQTT tidak terhubung')
      }

      // Simple payload sesuai spec (event_ts optional)
      const message = {
        gate_id: gateId,
        open: open,
      }

      console.log('📤 Publishing gate action:', message)

      // Publish ke MQTT
      await publish(TOPIC_GATE_CMD, message)

      // Log ke database via API (dengan event_ts dari server)
      await gateApi.logGateAction({
        gate_id: gateId,
        open: open,
      })

      // Jika ada nomor_plat, log ke manual control table
      if (options.nomor_plat) {
        await gateApi.logManualControl({
          gate_id: gateId,
          nomor_plat: options.nomor_plat,
          action: open ? 'OPEN' : 'CLOSE',
        })
      }

      console.log('✅ Gate action completed:', gateId, open ? 'OPEN' : 'CLOSE')
      return true
    } catch (err) {
      publishError.value = err.message || 'Gagal mengirim perintah gate'
      console.error('❌ Error publishing gate action:', err)
      return false
    } finally {
      isPublishing.value = false
    }
  }

  return {
    isConnected,
    isConnecting,
    isPublishing,
    publishError,
    publishGateAction,
  }
}

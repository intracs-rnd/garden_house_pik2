import { ref, onMounted, onUnmounted } from 'vue'
import mqttService from '../services/mqtt'

/**
 * Composable untuk menggunakan MQTT di Vue component
 * @param {string|null} autoSubscribeTopic - Topic yang akan otomatis di-subscribe saat component mounted
 * @param {object} options
 * @param {boolean} options.autoConnect - Auto connect saat component mounted (default: true)
 * @returns {object}
 */
export function useMqtt(autoSubscribeTopic = null, { autoConnect = true } = {}) {
  const isConnected = ref(false)
  const isConnecting = ref(false)
  const lastMessage = ref(null)
  const error = ref(null)

  /**
   * Connect ke MQTT broker
   */
  const connect = async () => {
    if (isConnecting.value) return
    isConnecting.value = true
    error.value = null
    try {
      await mqttService.connect()
      isConnected.value = true
    } catch (err) {
      error.value = err
      isConnected.value = false
      console.error('Failed to connect MQTT:', err)
    } finally {
      isConnecting.value = false
    }
  }

  /**
   * Disconnect dari MQTT broker
   */
  const disconnect = () => {
    mqttService.disconnect()
    isConnected.value = false
  }

  /**
   * Subscribe ke topic
   * @param {string} topic - Topic yang akan di-subscribe
   * @param {function} callback - Callback function
   * @param {object} options - Subscribe options (qos: 0, 1, or 2)
   */
  const subscribe = async (topic, callback, options = { qos: 0 }) => {
    try {
      await mqttService.subscribe(topic, (message, receivedTopic) => {
        lastMessage.value = { message, topic: receivedTopic }
        if (callback) {
          callback(message, receivedTopic)
        }
      }, options)
      error.value = null
    } catch (err) {
      error.value = err
      console.error('Failed to subscribe:', err)
    }
  }

  /**
   * Unsubscribe dari topic
   * @param {string} topic - Topic yang akan di-unsubscribe
   */
  const unsubscribe = async (topic) => {
    try {
      await mqttService.unsubscribe(topic)
      error.value = null
    } catch (err) {
      error.value = err
      console.error('Failed to unsubscribe:', err)
    }
  }

  /**
   * Publish message ke topic
   * @param {string} topic - Topic tujuan
   * @param {string|object} message - Message yang akan dikirim
   * @param {object} options - Options untuk publish
   */
  const publish = async (topic, message, options = {}) => {
    try {
      await mqttService.publish(topic, message, options)
      error.value = null
    } catch (err) {
      error.value = err
      console.error('Failed to publish:', err)
    }
  }

  // Auto connect dan subscribe saat component mounted
  onMounted(async () => {
    if (!autoConnect) return

    await connect()
    
    if (autoSubscribeTopic && isConnected.value) {
      await subscribe(autoSubscribeTopic)
    }
  })

  // Auto disconnect saat component unmounted
  onUnmounted(() => {
    if (autoSubscribeTopic) {
      unsubscribe(autoSubscribeTopic)
    }
  })

  return {
    isConnected,
    isConnecting,
    lastMessage,
    error,
    connect,
    disconnect,
    subscribe,
    unsubscribe,
    publish,
  }
}

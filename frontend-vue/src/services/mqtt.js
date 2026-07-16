import mqtt from 'mqtt'

class MQTTService {
  constructor() {
    this.client = null
    this.isConnected = false
    this.subscribers = new Map() // Map untuk menyimpan callback subscribers
  }

  /**
   * Menghubungkan ke MQTT broker
   * @returns {Promise<void>}
   */
  connect() {
    return new Promise((resolve, reject) => {
      if (this.isConnected && this.client) {
        console.log('MQTT already connected')
        resolve()
        return
      }

      // Hentikan client lama jika masih ada (misal sedang reconnecting)
      if (this.client) {
        this.client.end(true)
        this.client = null
      }

      const options = {
        clientId: 'dashboard-PIK-' + Math.random().toString(16).slice(2, 8),
        username: 'dev',
        password: 'dev',
        clean: true,
        reconnectPeriod: 0, // Nonaktifkan auto-reconnect, kontrol manual
        connectTimeout: 10000, // Timeout 10 detik
      }

      // Koneksi ke broker menggunakan WebSocket
      // Format: ws://IP:PORT/PATH (EMQX default path: /mqtt)
      const brokerUrl = 'ws://192.168.214.7:8083/mqtt'
      
      console.log('Connecting to MQTT broker:', brokerUrl)
      
      this.client = mqtt.connect(brokerUrl, options)

      this.client.on('connect', () => {
        console.log('MQTT connected successfully')
        this.isConnected = true
        resolve()
      })

      this.client.on('error', (error) => {
        console.error('MQTT connection error:', error)
        this.isConnected = false
        reject(error)
      })

      this.client.on('offline', () => {
        console.log('MQTT client offline')
        this.isConnected = false
      })

      this.client.on('reconnect', () => {
        console.log('MQTT reconnecting...')
      })

      this.client.on('message', (topic, message) => {
        this.handleMessage(topic, message)
      })
    })
  }

  /**
   * Memutuskan koneksi dari MQTT broker
   */
  disconnect() {
    if (this.client) {
      this.client.end()
      this.client = null
      this.isConnected = false
      this.subscribers.clear()
      console.log('MQTT disconnected')
    }
  }

  /**
   * Subscribe ke topic tertentu
   * @param {string} topic - Topic yang akan di-subscribe
   * @param {function} callback - Callback function yang akan dipanggil ketika ada message
   * @param {object} options - Subscribe options (qos: 0, 1, or 2)
   * @returns {Promise<void>}
   */
  subscribe(topic, callback, options = { qos: 0 }) {
    return new Promise((resolve, reject) => {
      if (!this.client || !this.isConnected) {
        reject(new Error('MQTT not connected'))
        return
      }

      this.client.subscribe(topic, options, (error) => {
        if (error) {
          console.error('Subscribe error:', error)
          reject(error)
        } else {
          console.log(`Subscribed to topic: ${topic} (QoS: ${options.qos})`)
          
          // Simpan callback untuk topic ini
          if (!this.subscribers.has(topic)) {
            this.subscribers.set(topic, [])
          }
          this.subscribers.get(topic).push(callback)
          
          resolve()
        }
      })
    })
  }

  /**
   * Unsubscribe dari topic tertentu
   * @param {string} topic - Topic yang akan di-unsubscribe
   * @returns {Promise<void>}
   */
  unsubscribe(topic) {
    return new Promise((resolve, reject) => {
      if (!this.client) {
        reject(new Error('MQTT not connected'))
        return
      }

      this.client.unsubscribe(topic, (error) => {
        if (error) {
          console.error('Unsubscribe error:', error)
          reject(error)
        } else {
          console.log('Unsubscribed from topic:', topic)
          this.subscribers.delete(topic)
          resolve()
        }
      })
    })
  }

  /**
   * Publish message ke topic tertentu
   * @param {string} topic - Topic tujuan
   * @param {string|object} message - Message yang akan dikirim
   * @param {object} options - Options untuk publish (qos, retain, dll)
   * @returns {Promise<void>}
   */
  publish(topic, message, options = {}) {
    return new Promise((resolve, reject) => {
      if (!this.client || !this.isConnected) {
        reject(new Error('MQTT not connected'))
        return
      }

      // Convert object to string jika message berupa object
      const payload = typeof message === 'object' ? JSON.stringify(message) : message

      this.client.publish(topic, payload, options, (error) => {
        if (error) {
          console.error('Publish error:', error)
          reject(error)
        } else {
          console.log('Message published to topic:', topic)
          resolve()
        }
      })
    })
  }

  /**
   * Handle incoming message
   * @param {string} topic - Topic yang menerima message
   * @param {Buffer} message - Message yang diterima
   */
  handleMessage(topic, message) {
    const payload = message.toString()
    console.log('Message received:', { topic, payload })

    // Parse message berdasarkan format
    let parsedMessage
    try {
      // Coba parse sebagai JSON dulu
      parsedMessage = JSON.parse(payload)
    } catch (e) {
      // Jika bukan JSON, coba parse sebagai pipe-delimited
      // Format: GATE_ID|DEVICE|STATUS|MESSAGE|TIMESTAMP
      if (payload.includes('|')) {
        const parts = payload.split('|')
        if (parts.length === 5) {
          parsedMessage = {
            gate_id: parts[0].trim(),
            device_type: parts[1].trim(),
            status: parts[2].trim(),
            message: parts[3].trim(),
            timestamp: parts[4].trim(),
          }
          console.log('Parsed pipe-delimited format:', parsedMessage)
        } else {
          parsedMessage = payload // Return as string jika format tidak sesuai
        }
      } else {
        parsedMessage = payload // Return as string jika bukan JSON dan bukan pipe-delimited
      }
    }

    // Panggil callback semua subscriber yang cocok (exact maupun wildcard)
    this.subscribers.forEach((callbacks, subscribedTopic) => {
      if (this.matchTopic(subscribedTopic, topic)) {
        callbacks.forEach(callback => {
          callback(parsedMessage, topic)
        })
      }
    })
  }

  /**
   * Match topic dengan wildcard support
   * @param {string} subscribedTopic - Topic dengan wildcard
   * @param {string} receivedTopic - Topic yang diterima
   * @returns {boolean}
   */
  matchTopic(subscribedTopic, receivedTopic) {
    const subscribed = subscribedTopic.split('/')
    const received = receivedTopic.split('/')

    if (subscribed.length !== received.length && !subscribedTopic.includes('#')) {
      return false
    }

    for (let i = 0; i < subscribed.length; i++) {
      if (subscribed[i] === '#') {
        return true
      }
      if (subscribed[i] !== '+' && subscribed[i] !== received[i]) {
        return false
      }
    }

    return true
  }

  /**
   * Check apakah client terhubung
   * @returns {boolean}
   */
  getConnectionStatus() {
    return this.isConnected
  }
}

// Export sebagai singleton instance
export default new MQTTService()

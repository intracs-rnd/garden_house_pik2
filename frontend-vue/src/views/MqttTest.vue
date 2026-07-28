<template>
  <div class="mqtt-test-container">
    <!-- ============================================================ -->
    <!-- DEVICE STATUS TEST (get/+/status wildcard)                  -->
    <!-- ============================================================ -->
    <div class="card ds-card">
      <div class="card-header ds-header">
        <div class="ds-header-left">
          <span class="ds-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="2" y="3" width="20" height="14" rx="2" />
              <path d="M8 21h8M12 17v4" />
            </svg>
          </span>
          <span>Device Status Test <code class="ds-code">get/+/status</code></span>
        </div>
        <div class="status-indicator" style="margin-bottom:0">
          <span class="status-dot" :class="{ connected: isConnected }"></span>
          <span style="font-size:13px">{{ isConnected ? 'Connected' : 'Disconnected' }}</span>
        </div>
      </div>

      <div class="card-body">
        <!-- Connect hint -->
        <div v-if="!isConnected" class="ds-hint">
          ⚠️ Sambungkan ke MQTT broker terlebih dahulu di bagian bawah halaman ini.
        </div>

        <!-- Simulator form -->
        <div class="ds-form">
          <div class="ds-row">
            <div class="ds-field">
              <label class="ds-label">Nama Device</label>
              <input
                v-model="deviceName"
                type="text"
                class="input"
                placeholder="cth: cctv-gerbang, sensor-pintu"
              />
              <span class="ds-hint-text">Topic: <code>get/{{ deviceName || 'nama_device' }}/status</code></span>
            </div>
            <div class="ds-field ds-field-sm">
              <label class="ds-label">Status</label>
              <div class="ds-radio-group">
                <label class="ds-radio" :class="{ active: deviceStatus === 'online' }">
                  <input type="radio" v-model="deviceStatus" value="online" />
                  <span class="ds-radio-dot online"></span> online
                </label>
                <label class="ds-radio" :class="{ active: deviceStatus === 'offline' }">
                  <input type="radio" v-model="deviceStatus" value="offline" />
                  <span class="ds-radio-dot offline"></span> offline
                </label>
              </div>
            </div>
          </div>

          <!-- Preview JSON yang akan diterima oleh backend Laravel -->
          <div class="ds-preview">
            <span class="ds-preview-label">📦 Payload yang akan dikirim ke MQTT broker:</span>
            <code class="ds-preview-code">{{ deviceStatus }}</code>
            <span class="ds-preview-arrow">→</span>
            <span class="ds-preview-label">JSON yang akan di-publish oleh backend ke <code>dashboard/device/status</code>:</span>
            <code class="ds-preview-code">{{ JSON.stringify({ nama_device: deviceName || 'nama_device', status: deviceStatus }) }}</code>
          </div>

          <button
            @click="handlePublishDeviceStatus"
            :disabled="!isConnected || isPublishingDevice"
            class="btn btn-publish"
          >
            <svg v-if="isPublishingDevice" style="width:16px;height:16px;animation:spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>
            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px">
              <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
            </svg>
            {{ isPublishingDevice ? 'Mengirim...' : 'Kirim Status Device' }}
          </button>
        </div>

        <!-- Log hasil publish -->
        <div class="ds-log" v-if="deviceStatusMessages.length > 0">
          <div class="ds-log-header">
            <span>📋 Log Pengiriman</span>
            <button @click="deviceStatusMessages = []" class="btn btn-sm btn-secondary">Clear</button>
          </div>
          <div class="ds-log-list">
            <div
              v-for="(m, i) in deviceStatusMessages"
              :key="i"
              class="ds-log-item"
              :class="m.status"
            >
              <div class="ds-log-left">
                <span class="ds-status-dot" :class="m.status"></span>
                <span class="ds-log-device">{{ m.nama_device }}</span>
              </div>
              <div class="ds-log-mid">
                <code class="ds-log-topic">{{ m.topic }}</code>
                <code class="ds-log-json">{{ JSON.stringify({ nama_device: m.nama_device, status: m.status }) }}</code>
              </div>
              <span class="ds-log-time">{{ m.time }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- GENERAL MQTT TEST (original)                                -->
    <!-- ============================================================ -->
    <div class="card" style="margin-top:24px">
      <div class="card-header">
        <h2>MQTT Connection Test</h2>
      </div>
      
      <div class="card-body">
        <!-- Connection Status -->
        <div class="status-section">
          <h3>Connection Status</h3>
          <div class="status-indicator">
            <span class="status-dot" :class="{ connected: isConnected }"></span>
            <span>{{ isConnected ? 'Connected' : 'Disconnected' }}</span>
          </div>
          <div class="button-group">
            <button @click="handleConnect" :disabled="isConnected || isConnecting" class="btn btn-primary">
              <span v-if="isConnecting">⏳ Connecting...</span>
              <span v-else>Connect</span>
            </button>
            <button @click="handleDisconnect" :disabled="!isConnected" class="btn btn-secondary">
              Disconnect
            </button>
          </div>
        </div>

        <hr />

        <!-- Subscribe Section -->
        <div class="subscribe-section">
          <h3>Subscribe to Topic</h3>
          <div class="input-group">
            <input 
              v-model="subscribeTopic" 
              type="text" 
              placeholder="e.g., gate/status or get/+/status"
              class="input"
            />
            <button @click="handleSubscribe" :disabled="!isConnected" class="btn btn-success">
              Subscribe
            </button>
          </div>
          
          <div v-if="subscribedTopics.length > 0" class="subscribed-list">
            <h4>Subscribed Topics:</h4>
            <div v-for="topic in subscribedTopics" :key="topic" class="topic-item">
              <span>{{ topic }}</span>
              <button @click="handleUnsubscribe(topic)" class="btn btn-sm btn-danger">
                Unsubscribe
              </button>
            </div>
          </div>
        </div>

        <hr />

        <!-- Publish Section -->
        <div class="publish-section">
          <h3>Publish Message</h3>
          <div class="input-group">
            <input 
              v-model="publishTopic" 
              type="text" 
              placeholder="Topic (e.g., get/cctv-gerbang/status)"
              class="input"
            />
          </div>
          <div class="input-group">
            <textarea 
              v-model="publishMessage" 
              placeholder='Message (text or JSON, e.g., online)'
              class="textarea"
              rows="3"
            ></textarea>
          </div>
          <button @click="handlePublish" :disabled="!isConnected" class="btn btn-primary">
            Publish
          </button>
        </div>

        <hr />

        <!-- Messages Log -->
        <div class="messages-section">
          <div class="messages-header">
            <h3>Received Messages</h3>
            <button @click="messages = []" class="btn btn-sm btn-secondary">
              Clear
            </button>
          </div>
          
          <div class="messages-log">
            <div v-if="messages.length === 0" class="no-messages">
              No messages received yet. Subscribe to a topic and wait for messages.
            </div>
            <div v-else>
              <div 
                v-for="(msg, index) in messages" 
                :key="index" 
                class="message-item"
              >
                <div class="message-header">
                  <span class="message-topic">{{ msg.topic }}</span>
                  <span class="message-time">{{ msg.time }}</span>
                </div>
                <div class="message-content">
                  <pre>{{ msg.message }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Error Display -->
        <div v-if="error" class="error-message">
          <strong>Error:</strong> {{ error.message || error }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useMqtt } from '@/composables/useMqtt'

const subscribeTopic = ref('get/+/status')
const publishTopic = ref('get/cctv-gerbang/status')
const publishMessage = ref('online')
const subscribedTopics = ref([])
const messages = ref([])

// --- Device Status Test ---
const deviceName = ref('cctv-gerbang')
const deviceStatus = ref('online')
const deviceStatusMessages = ref([])
const isPublishingDevice = ref(false)

async function handlePublishDeviceStatus() {
  if (!deviceName.value.trim()) {
    alert('Masukkan nama device')
    return
  }
  if (!isConnected.value) {
    alert('Sambungkan ke MQTT broker terlebih dahulu')
    return
  }

  isPublishingDevice.value = true
  const topic = `get/${deviceName.value.trim()}/status`
  const payload = deviceStatus.value

  await publish(topic, payload)

  // Log entry
  deviceStatusMessages.value.unshift({
    topic,
    nama_device: deviceName.value.trim(),
    status: payload,
    time: new Date().toLocaleTimeString('id-ID'),
  })
  if (deviceStatusMessages.value.length > 30) {
    deviceStatusMessages.value = deviceStatusMessages.value.slice(0, 30)
  }

  isPublishingDevice.value = false
}

const { isConnected, isConnecting, error, connect, disconnect, subscribe, unsubscribe, publish } = useMqtt(null, { autoConnect: false })

const handleConnect = async () => {
  await connect()
}

const handleDisconnect = () => {
  disconnect()
  subscribedTopics.value = []
}

const handleSubscribe = async () => {
  if (!subscribeTopic.value.trim()) {
    alert('Please enter a topic')
    return
  }

  const topic = subscribeTopic.value.trim()
  
  if (subscribedTopics.value.includes(topic)) {
    alert('Already subscribed to this topic')
    return
  }

  await subscribe(topic, (message, receivedTopic) => {
    messages.value.unshift({
      topic: receivedTopic,
      message: typeof message === 'object' ? JSON.stringify(message, null, 2) : message,
      time: new Date().toLocaleTimeString()
    })
    
    // Keep only last 50 messages
    if (messages.value.length > 50) {
      messages.value = messages.value.slice(0, 50)
    }
  })

  subscribedTopics.value.push(topic)
  subscribeTopic.value = ''
}

const handleUnsubscribe = async (topic) => {
  await unsubscribe(topic)
  subscribedTopics.value = subscribedTopics.value.filter(t => t !== topic)
}

const handlePublish = async () => {
  if (!publishTopic.value.trim()) {
    alert('Please enter a topic')
    return
  }

  if (!publishMessage.value.trim()) {
    alert('Please enter a message')
    return
  }

  let messageToSend = publishMessage.value.trim()
  
  // Try to parse as JSON
  try {
    messageToSend = JSON.parse(messageToSend)
  } catch (e) {
    // Not JSON, send as string
  }

  await publish(publishTopic.value.trim(), messageToSend)
  
  // Log sent message
  messages.value.unshift({
    topic: publishTopic.value.trim() + ' (sent)',
    message: typeof messageToSend === 'object' ? JSON.stringify(messageToSend, null, 2) : messageToSend,
    time: new Date().toLocaleTimeString()
  })
}

// Watch for errors
watch(error, (err) => {
  if (err) {
    console.error('MQTT Error:', err)
  }
})
</script>

<style scoped>
.mqtt-test-container {
  padding: 20px;
  max-width: 1200px;
  margin: 0 auto;
}

.card {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.card-header {
  background: #4CAF50;
  color: white;
  padding: 20px;
}

.card-header h2 {
  margin: 0;
}

.card-body {
  padding: 20px;
}

.status-section,
.subscribe-section,
.publish-section,
.messages-section {
  margin-bottom: 20px;
}

h3 {
  margin-top: 0;
  margin-bottom: 15px;
  color: #333;
}

h4 {
  margin-top: 15px;
  margin-bottom: 10px;
  color: #666;
  font-size: 14px;
}

.status-indicator {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 15px;
  font-size: 16px;
}

.status-dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: #dc3545;
  animation: pulse 2s infinite;
}

.status-dot.connected {
  background: #28a745;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.button-group {
  display: flex;
  gap: 10px;
}

.input-group {
  margin-bottom: 10px;
}

.input,
.textarea {
  width: 100%;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 14px;
  font-family: inherit;
}

.textarea {
  resize: vertical;
  font-family: 'Consolas', 'Monaco', monospace;
}

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 500;
  transition: all 0.2s;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  background: #007bff;
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: #0056b3;
}

.btn-secondary {
  background: #6c757d;
  color: white;
}

.btn-secondary:hover:not(:disabled) {
  background: #545b62;
}

.btn-success {
  background: #28a745;
  color: white;
}

.btn-success:hover:not(:disabled) {
  background: #218838;
}

.btn-danger {
  background: #dc3545;
  color: white;
}

.btn-danger:hover:not(:disabled) {
  background: #c82333;
}

.btn-sm {
  padding: 5px 10px;
  font-size: 12px;
}

.subscribed-list {
  margin-top: 15px;
}

.topic-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px;
  background: #f8f9fa;
  border-radius: 4px;
  margin-bottom: 8px;
}

.messages-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.messages-log {
  max-height: 400px;
  overflow-y: auto;
  border: 1px solid #ddd;
  border-radius: 4px;
  padding: 10px;
  background: #f8f9fa;
}

.no-messages {
  text-align: center;
  color: #999;
  padding: 20px;
}

.message-item {
  background: white;
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  padding: 10px;
  margin-bottom: 10px;
}

.message-header {
  display: flex;
  justify-content: space-between;
  margin-bottom: 8px;
}

.message-topic {
  font-weight: 600;
  color: #007bff;
}

.message-time {
  color: #999;
  font-size: 12px;
}

.message-content pre {
  margin: 0;
  padding: 10px;
  background: #f5f5f5;
  border-radius: 4px;
  overflow-x: auto;
  font-size: 12px;
  font-family: 'Consolas', 'Monaco', monospace;
}

.error-message {
  padding: 15px;
  background: #f8d7da;
  color: #721c24;
  border: 1px solid #f5c6cb;
  border-radius: 4px;
  margin-top: 20px;
}

hr {
  border: none;
  border-top: 1px solid #e0e0e0;
  margin: 30px 0;
}

/* ===== Device Status Test Styles ===== */
.ds-card { overflow: hidden; }

.ds-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: white;
  padding: 18px 24px;
}
.ds-header h2 { margin: 0; }
.ds-header-left {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 17px;
  font-weight: 600;
}
.ds-icon {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  background: rgba(255,255,255,0.15);
  border-radius: 8px;
}
.ds-icon svg { width: 20px; height: 20px; }
.ds-code {
  background: rgba(255,255,255,0.15);
  color: #c7d2fe;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 13px;
}

.ds-hint {
  padding: 12px 16px;
  background: #fefce8;
  border: 1px solid #fde68a;
  border-radius: 8px;
  font-size: 13px;
  color: #92400e;
  margin-bottom: 20px;
}

.ds-form { display: flex; flex-direction: column; gap: 16px; }
.ds-row {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}
.ds-field { display: flex; flex-direction: column; gap: 6px; flex: 1; min-width: 200px; }
.ds-field-sm { flex: 0 0 auto; min-width: 140px; }
.ds-label { font-size: 13px; font-weight: 600; color: #374151; }
.ds-hint-text { font-size: 11px; color: #6b7280; }
.ds-hint-text code { background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }

.ds-radio-group { display: flex; gap: 10px; }
.ds-radio {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  cursor: pointer;
  font-size: 13px;
  font-weight: 600;
  transition: all 0.2s;
}
.ds-radio input { display: none; }
.ds-radio.active { border-color: #4f46e5; background: #eef2ff; color: #4f46e5; }
.ds-radio-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
.ds-radio-dot.online { background: #22c55e; }
.ds-radio-dot.offline { background: #ef4444; }

.ds-preview {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 16px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  font-size: 12px;
  flex-wrap: wrap;
}
.ds-preview-label { color: #6b7280; }
.ds-preview-label code { background: #e2e8f0; padding: 1px 4px; border-radius: 3px; }
.ds-preview-code {
  background: #1e293b;
  color: #7dd3fc;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
}
.ds-preview-arrow { color: #94a3b8; font-size: 16px; font-weight: bold; }

.btn-publish {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 11px 24px;
  background: linear-gradient(135deg, #4f46e5, #7c3aed);
  color: white;
  border: none;
  border-radius: 8px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  transition: all 0.2s;
  align-self: flex-start;
}
.btn-publish:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(79,70,229,0.35); }
.btn-publish:disabled { opacity: 0.5; cursor: not-allowed; }

@keyframes spin { to { transform: rotate(360deg); } }

/* DS Log */
.ds-log { margin-top: 8px; }
.ds-log-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  font-size: 13px;
  font-weight: 600;
  color: #374151;
}
.ds-log-list { display: flex; flex-direction: column; gap: 8px; }
.ds-log-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 10px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  border-left: 3px solid #e2e8f0;
}
.ds-log-item.online { border-left-color: #22c55e; }
.ds-log-item.offline { border-left-color: #ef4444; }

.ds-log-left {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 120px;
}
.ds-status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.ds-status-dot.online { background: #22c55e; }
.ds-status-dot.offline { background: #ef4444; }
.ds-log-device { font-size: 13px; font-weight: 600; color: #1e293b; }

.ds-log-mid {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}
.ds-log-topic {
  font-size: 11px;
  color: #7c3aed;
  background: #ede9fe;
  padding: 1px 6px;
  border-radius: 4px;
  align-self: flex-start;
}
.ds-log-json {
  font-size: 12px;
  color: #1e293b;
  background: #f1f5f9;
  padding: 3px 8px;
  border-radius: 4px;
  font-family: 'Consolas', 'Monaco', monospace;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ds-log-time {
  font-size: 11px;
  color: #94a3b8;
  flex-shrink: 0;
}
</style>

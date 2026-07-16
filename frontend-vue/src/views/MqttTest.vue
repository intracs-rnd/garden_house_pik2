<template>
  <div class="mqtt-test-container">
    <div class="card">
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
              placeholder="e.g., gate/status or gate/+/status"
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
              placeholder="Topic (e.g., gate/command)"
              class="input"
            />
          </div>
          <div class="input-group">
            <textarea 
              v-model="publishMessage" 
              placeholder='Message (JSON or text, e.g., {"action":"open"})'
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

const subscribeTopic = ref('gate/status')
const publishTopic = ref('gate/command')
const publishMessage = ref('{"action":"test","timestamp":' + Date.now() + '}')
const subscribedTopics = ref([])
const messages = ref([])

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
</style>

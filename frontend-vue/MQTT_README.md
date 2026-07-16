# MQTT Service untuk GH PIK2 Dashboard

Service ini digunakan untuk koneksi MQTT ke broker dengan konfigurasi:
- **Broker IP**: 192.168.214.7
- **Port**: 8083 (WebSocket)
- **Username**: dev
- **Password**: dev
- **Client ID**: dashboard-PIK

## Struktur File
- `src/services/mqtt.js` - Service utama MQTT (singleton)
- `src/composables/useMqtt.js` - Composable Vue untuk menggunakan MQTT

## Penggunaan

### 1. Menggunakan MQTT Service Langsung

```javascript
import mqttService from '@/services/mqtt'

// Connect ke broker
await mqttService.connect()

// Subscribe ke topic
await mqttService.subscribe('gate/status', (message, topic) => {
  console.log('Received:', message)
})

// Publish message
await mqttService.publish('gate/command', { action: 'open' })

// Unsubscribe
await mqttService.unsubscribe('gate/status')

// Disconnect
mqttService.disconnect()
```

### 2. Menggunakan Composable di Vue Component

```vue
<script setup>
import { useMqtt } from '@/composables/useMqtt'

// Opsi 1: Auto connect dan subscribe ke topic tertentu
const { isConnected, lastMessage, publish } = useMqtt('gate/status')

// Opsi 2: Manual control
const { isConnected, subscribe, publish } = useMqtt()

// Subscribe ke topic lain
subscribe('gate/sensor', (message) => {
  console.log('Sensor data:', message)
})

// Publish message
const openGate = () => {
  publish('gate/command', { action: 'open', gate: 1 })
}
</script>

<template>
  <div>
    <p>Status: {{ isConnected ? 'Connected' : 'Disconnected' }}</p>
    <p v-if="lastMessage">Last: {{ lastMessage.message }}</p>
    <button @click="openGate">Open Gate</button>
  </div>
</template>
```

### 3. Contoh Penggunaan di Dashboard Component

```vue
<script setup>
import { ref, watch } from 'vue'
import { useMqtt } from '@/composables/useMqtt'

const gateStatus = ref('unknown')
const sensorData = ref(null)

const { isConnected, subscribe, publish } = useMqtt()

// Subscribe ke multiple topics
subscribe('gate/+/status', (message, topic) => {
  console.log('Gate status update:', topic, message)
  gateStatus.value = message.status
})

subscribe('sensor/data', (message) => {
  sensorData.value = message
})

// Kirim command
const sendCommand = (command) => {
  publish('gate/command', {
    action: command,
    timestamp: Date.now()
  })
}
</script>
```

## Wildcard Topics

Service ini mendukung MQTT wildcard:
- `+` (single level): `gate/+/status` akan match `gate/1/status`, `gate/2/status`
- `#` (multi level): `gate/#` akan match semua topic di bawah `gate/`

## Connection Features

- **Auto Reconnect**: Otomatis reconnect setiap 5 detik jika koneksi terputus
- **Connection Timeout**: 30 detik
- **Clean Session**: Ya
- **Multiple Subscribers**: Bisa subscribe ke topic yang sama dari berbagai tempat

## Error Handling

```javascript
const { error, subscribe } = useMqtt()

subscribe('gate/status')

watch(error, (err) => {
  if (err) {
    console.error('MQTT Error:', err)
    // Handle error (show toast, retry, etc)
  }
})
```

## Catatan Penting

1. **WebSocket Port**: Pastikan broker MQTT mendukung WebSocket di port 8083
2. **CORS**: Jika ada masalah CORS, pastikan broker dikonfigurasi dengan benar
3. **Koneksi**: Service akan otomatis connect saat menggunakan composable `useMqtt()`
4. **Cleanup**: Composable otomatis cleanup saat component di-unmount

## Konfigurasi Broker (Mosquitto)

Jika menggunakan Mosquitto, tambahkan konfigurasi WebSocket:

```
listener 1883
protocol mqtt

listener 8083
protocol websockets

allow_anonymous false
password_file /mosquitto/config/passwd
```

Buat user dengan:
```bash
mosquitto_passwd -c /mosquitto/config/passwd dev
# masukkan password: dev
```

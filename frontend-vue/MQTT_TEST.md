# Cara Test MQTT Service

Ada 3 cara untuk test MQTT service:

## 1. Menggunakan UI Test Page (Paling Mudah) ✅

Akses halaman test melalui browser:
```
http://localhost:5173/mqtt-test
```

**Langkah-langkah:**
1. Buka halaman `http://localhost:5173/mqtt-test`
2. Klik tombol **"Connect"** - status akan berubah jadi **Connected** (hijau)
3. **Subscribe ke topic:**
   - Ketik topic (misal: `gate/status`)
   - Klik **"Subscribe"**
4. **Publish message:**
   - Ketik topic tujuan (misal: `gate/command`)
   - Ketik message (JSON atau text)
   - Klik **"Publish"**
5. Lihat message yang diterima di bagian **"Received Messages"**

**Contoh Topics untuk Test:**
- `gate/status` - status gate
- `gate/+/status` - semua gate (wildcard)
- `sensor/data` - data sensor
- `gate/command` - kirim command

---

## 2. Test via Browser Console 🔧

Buka browser console (F12) dan ketik:

### Test Koneksi
```javascript
import mqttService from './src/services/mqtt.js'

// Connect
await mqttService.connect()
// Output: "MQTT connected successfully"

// Check status
mqttService.getConnectionStatus()
// Output: true
```

### Test Subscribe
```javascript
// Subscribe dan tampilkan message
mqttService.subscribe('gate/status', (message, topic) => {
  console.log('📨 Received:', topic, message)
})
```

### Test Publish
```javascript
// Kirim message JSON
mqttService.publish('gate/command', {
  action: 'open',
  gate: 1,
  timestamp: Date.now()
})

// Kirim message text
mqttService.publish('sensor/alert', 'Temperature too high!')
```

### Test Unsubscribe
```javascript
mqttService.unsubscribe('gate/status')
```

### Disconnect
```javascript
mqttService.disconnect()
```

---

## 3. Test dengan MQTT Client External 📱

Gunakan aplikasi MQTT client untuk test dari luar:

### A. MQTT Explorer (Desktop) - Recommended
Download: http://mqtt-explorer.com/

**Settings:**
- Host: `192.168.214.7`
- Port: `1883` (untuk TCP) atau `8083` (untuk WebSocket)
- Username: `dev`
- Password: `dev`
- Client ID: `mqtt-explorer-test`

### B. MQTT.fx (Desktop)
Download: https://mqttfx.jensd.de/

### C. MQTT Dash (Android/iOS)
- Publish message ke topic
- Subscribe dan lihat message dari dashboard Vue

### D. Mosquitto CLI (Command Line)

**Subscribe:**
```bash
mosquitto_sub -h 192.168.214.7 -p 1883 -u dev -P dev -t "gate/#" -v
```

**Publish:**
```bash
mosquitto_pub -h 192.168.214.7 -p 1883 -u dev -P dev -t "gate/command" -m '{"action":"open"}'
```

---

## Troubleshooting 🔍

### Error: "MQTT connection error"

**Kemungkinan penyebab:**

1. **Broker tidak jalan**
   ```bash
   # Check apakah mosquitto jalan
   netstat -an | findstr 1883
   ```

2. **WebSocket tidak aktif**
   - Pastikan broker support WebSocket di port 8083
   - Edit `mosquitto.conf`:
     ```
     listener 8083
     protocol websockets
     ```

3. **Authentication failed**
   - Cek username/password benar
   - Pastikan user sudah dibuat di mosquitto

4. **Firewall blocking**
   - Allow port 8083 di firewall
   ```bash
   netsh advfirewall firewall add rule name="MQTT WebSocket" dir=in action=allow protocol=TCP localport=8083
   ```

### Error: "Network error" atau "ECONNREFUSED"

- Cek IP broker benar (192.168.214.7)
- Ping broker: `ping 192.168.214.7`
- Telnet ke port: `telnet 192.168.214.7 8083`

### Message tidak diterima

1. **Check subscription berhasil:**
   ```javascript
   // Di console
   console.log(mqttService.subscribers)
   ```

2. **Check topic match:**
   - Topic harus sama persis (case-sensitive)
   - Atau gunakan wildcard (`+` atau `#`)

3. **Check QoS level:**
   ```javascript
   // Publish dengan QoS 1
   mqttService.publish('test/topic', 'message', { qos: 1 })
   ```

---

## Test Checklist ✓

- [ ] Connect ke broker berhasil
- [ ] Subscribe ke topic berhasil  
- [ ] Terima message yang di-publish
- [ ] Publish message berhasil
- [ ] Unsubscribe berhasil
- [ ] Wildcard topic (`+`, `#`) bekerja
- [ ] JSON parsing otomatis
- [ ] Auto reconnect saat disconnect
- [ ] Error handling bekerja

---

## Contoh Skenario Test

### Skenario 1: Real-time Gate Status
```javascript
// Dashboard subscribe ke status semua gate
subscribe('gate/+/status', (msg, topic) => {
  console.log(`Gate ${topic.split('/')[1]} status:`, msg)
})

// Gate controller publish status
publish('gate/1/status', { 
  status: 'open', 
  timestamp: Date.now() 
})
```

### Skenario 2: Command & Response
```javascript
// Dashboard kirim command
publish('gate/1/command', { action: 'open' })

// Subscribe untuk response
subscribe('gate/1/response', (msg) => {
  console.log('Gate response:', msg)
  // { success: true, action: 'open' }
})
```

### Skenario 3: Sensor Data Stream
```javascript
// Subscribe ke data sensor
subscribe('sensor/#', (data, topic) => {
  console.log('Sensor update:', topic, data)
  // sensor/temperature: { value: 25.5, unit: 'C' }
  // sensor/humidity: { value: 60, unit: '%' }
})
```

---

## Tips 💡

1. **Gunakan QoS yang sesuai:**
   - QoS 0: At most once (default, tercepat)
   - QoS 1: At least once (reliable)
   - QoS 2: Exactly once (paling lambat)

2. **Topic naming convention:**
   - Gunakan `/` sebagai separator
   - lowercase dan underscore
   - Contoh: `device/gate1/status`

3. **JSON vs String:**
   - Service otomatis parse JSON
   - Kirim object langsung: `publish('topic', { key: 'value' })`

4. **Clean up:**
   - Unsubscribe saat component unmount
   - Composable sudah otomatis cleanup

5. **Development:**
   - Gunakan console.log untuk debug
   - Check Network tab di DevTools
   - Monitor WebSocket connection

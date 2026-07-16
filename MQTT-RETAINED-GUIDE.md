# MQTT Retained Message - Guide

## 📖 Konsep

**Retained Message** adalah fitur MQTT dimana broker menyimpan message terakhir dari sebuah topic. Setiap subscriber baru akan **langsung mendapat message terakhir** saat subscribe, tanpa perlu menunggu publish baru.

### Use Case:
- ✅ **Status Monitoring** (RFID connected/disconnected)
- ✅ **Last Known State** (temperature, humidity, door status)
- ✅ **Configuration** (settings yang jarang berubah)

### NOT Recommended:
- ❌ Event data (tap kartu, transaksi) → Pakai database
- ❌ Streaming data (sensor readings setiap detik)
- ❌ Temporary messages

---

## 🔧 Cara Publish dengan Retain

### **1. Mosquitto CLI**

```bash
# Publish dengan retain
mosquitto_pub -h 192.168.214.7 -p 1883 -u dev -P dev \
  -t "gate/in/rfid_status" \
  -q 1 \
  -r \
  -m "GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00"
```

**Flags:**
- `-r` = Retain flag (broker will save this message)
- `-q 1` = QoS 1 (at least once delivery)

### **2. MQTTX (GUI Client)**

1. Open MQTTX
2. Create connection
3. Go to publish tab
4. Check **"Retain"** checkbox ✓
5. Set QoS to **1**
6. Publish message

### **3. Python (paho-mqtt)**

```python
import paho.mqtt.client as mqtt

client = mqtt.Client()
client.username_pw_set("dev", "dev")
client.connect("192.168.214.7", 1883, 60)

# Publish dengan retain=True
client.publish(
    topic="gate/in/rfid_status",
    payload="GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00",
    qos=1,
    retain=True  # ← Retained message
)

client.disconnect()
```

### **4. Arduino/ESP32**

```cpp
#include <PubSubClient.h>

WiFiClient espClient;
PubSubClient client(espClient);

void publishStatus() {
  String payload = "GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00";
  
  // publish(topic, payload, retained)
  client.publish("gate/in/rfid_status", payload.c_str(), true);  // ← Retained = true
}
```

### **5. Node.js**

```javascript
const mqtt = require('mqtt')
const client = mqtt.connect('mqtt://192.168.214.7:1883', {
  username: 'dev',
  password: 'dev'
})

client.on('connect', () => {
  client.publish(
    'gate/in/rfid_status',
    'GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00',
    {
      qos: 1,
      retain: true  // ← Retained message
    }
  )
})
```

---

## 🧪 Testing

### **1. Test Retained Message**

```bash
# Step 1: Publish retained message
mosquitto_pub -h 192.168.214.7 -p 1883 -u dev -P dev \
  -t "gate/in/rfid_status" -q 1 -r \
  -m "GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00"

# Step 2: Subscribe (akan langsung dapat message)
mosquitto_sub -h 192.168.214.7 -p 1883 -u dev -P dev \
  -t "gate/in/rfid_status" -q 1 -v
  
# Output (instant!):
# gate/in/rfid_status GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00
```

### **2. Test Refresh Dashboard**

1. Publish retained message
2. Buka dashboard → Status tampil ✓
3. **Refresh browser** (F5)
4. Status tetap tampil (langsung dari broker) ✓

---

## 🗑️ Cara Hapus Retained Message

Publish **empty message** dengan retain:

```bash
# Hapus retained message
mosquitto_pub -h 192.168.214.7 -p 1883 -u dev -P dev \
  -t "gate/in/rfid_status" -q 1 -r -n
```

Flag `-n` = null payload

---

## 📊 Format Message

### **Status RFID Reader**

Format: `GATE_ID|DEVICE|STATUS|MESSAGE|TIMESTAMP`

**Example:**
```
GATE_IN_01|RFID|CONNECTED|OK|2026-07-15 13:30:00
GATE_OUT_01|RFID|DISCONNECTED|Hardware Error|2026-07-15 13:35:00
```

**Fields:**
- `GATE_ID`: Gate identifier (GATE_IN_01, GATE_OUT_01)
- `DEVICE`: Device type (RFID, CAMERA, etc)
- `STATUS`: CONNECTED or DISCONNECTED
- `MESSAGE`: Status message (OK, Error description, etc)
- `TIMESTAMP`: ISO8601 or local datetime

---

## ⚙️ Broker Configuration

Pastikan broker support retained messages (EMQX default: enabled).

### **Check EMQX Config:**

```bash
# SSH ke server EMQX
cat /etc/emqx/emqx.conf | grep retain
```

Should show:
```
mqtt.max_packet_size = 1MB
mqtt.retain_available = true  # ← Must be true
```

---

## 🎯 Best Practices

### ✅ DO:
- Gunakan retained untuk **status/state monitoring**
- Update retained message setiap kali status berubah
- Gunakan QoS 1 untuk reliability
- Hapus retained message jika sudah tidak relevan

### ❌ DON'T:
- Jangan gunakan untuk **event logging** (pakai database)
- Jangan retained untuk data yang berubah sangat cepat
- Jangan lupa update retained saat status berubah
- Jangan biarkan retained message outdated

---

## 🔄 Update Flow

```
Hardware detects status change
    ↓
Publish dengan retain=true
    ↓
Broker saves as retained
    ↓
All subscribers get update instantly
    ↓
New subscribers get last state on connect
```

---

## 💡 Tips

1. **Multiple Gates**: Gunakan topic terpisah
   ```
   gate/in/rfid_status    (retained)
   gate/out/rfid_status   (retained)
   ```

2. **Wildcard Subscribe**:
   ```
   gate/+/rfid_status  (dapat semua gates)
   ```

3. **Status Update**: Update retained setiap kali status berubah
   ```
   CONNECTED → DISCONNECTED → CONNECTED
   ```

4. **Timestamp**: Selalu include timestamp untuk tracking

---

## 🐛 Troubleshooting

### Message tidak retained?

1. Check publish command ada flag `-r`
2. Check broker config: `retain_available = true`
3. Coba hapus & publish ulang

### Dapat old message?

1. Retained message memang saved
2. Publish new message dengan retain untuk update
3. Atau hapus dengan `-n` flag

### Multiple messages?

1. Retained hanya simpan **1 message terakhir** per topic
2. Old message otomatis ter-replace

---

## 📝 Summary

**Retained Message = Last Known State**

- ✅ Perfect untuk status monitoring
- ✅ Subscriber baru langsung dapat state
- ✅ No database needed untuk simple state
- ✅ Native MQTT feature

**Command:**
```bash
mosquitto_pub -h <broker> -p <port> -u <user> -P <pass> \
  -t <topic> -q 1 -r -m "<message>"
```

**Dashboard:** Refresh tetap dapat last state! 🎉

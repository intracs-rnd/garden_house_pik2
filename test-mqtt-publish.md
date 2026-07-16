# Test MQTT Publish ke Dashboard

## Informasi Koneksi
- **Broker URL**: `ws://192.168.214.7:8083/mqtt`
- **Username**: `dev`
- **Password**: `dev`
- **Topic**: `gate/in/rfid_status`
- **QoS**: 1 (wajib)

---

## 🧪 Cara 1: Pakai Halaman /mqtt-test (Web UI)

1. Buka browser: `http://localhost:5173/mqtt-test`
2. Klik **"Connect"**
3. Di section **"Publish Message"**:
   - Topic: `gate/in/rfid_status`
   - Message (paste salah satu contoh di bawah)
   - Klik **"Publish"**
4. Buka Dashboard di tab lain untuk lihat hasilnya!

---

## 🧪 Cara 2: Pakai MQTTX (GUI Client)

### Download MQTTX:
- Website: https://mqttx.app/
- atau install via: `winget install EMQX.MQTTX`

### Setup Connection:
1. Klik **"+ New Connection"**
2. Isi:
   - Name: `GH PIK2 Dashboard`
   - Host: `ws://192.168.214.7`
   - Port: `8083`
   - Path: `/mqtt`
   - Username: `dev`
   - Password: `dev`
3. Klik **"Connect"**

### Publish Message:
1. Di bagian bawah, pilih **QoS: 1**
2. Topic: `gate/in/rfid_status`
3. Payload: (paste contoh JSON di bawah)
4. Klik **"Publish"**

---

## 🧪 Cara 3: Pakai Mosquitto CLI

### Install Mosquitto Client:
```bash
# Windows (via Chocolatey)
choco install mosquitto

# atau download dari: https://mosquitto.org/download/
```

### Publish Command:
```bash
mosquitto_pub -h 192.168.214.7 -p 1883 -t "gate/in/rfid_status" -m '{"card_number":"KRT-12345678","rfid_tag":"A1B2C3D4E5","access_granted":true,"reason":"ok","timestamp":"2026-07-15T10:46:00.000Z"}' -u dev -P dev -q 1
```

**Note:** Port 1883 untuk MQTT (bukan WebSocket). Jika hanya WebSocket yang aktif, gunakan cara 1 atau 2.

---

## 📋 Contoh Message untuk Test

### ✅ Akses Diberikan (Success)
```json
{
  "card_number": "KRT-12345678",
  "rfid_tag": "A1B2C3D4E5",
  "access_granted": true,
  "reason": "ok",
  "timestamp": "2026-07-15T10:46:00.000Z"
}
```

### ❌ Akses Ditolak - Kartu Expired
```json
{
  "card_number": "KRT-87654321",
  "rfid_tag": "F9E8D7C6B5",
  "access_granted": false,
  "reason": "expired",
  "timestamp": "2026-07-15T10:47:00.000Z"
}
```

### ❌ Akses Ditolak - Kartu Tidak Aktif
```json
{
  "card_number": "KRT-11223344",
  "rfid_tag": "1A2B3C4D5E",
  "access_granted": false,
  "reason": "inactive",
  "timestamp": "2026-07-15T10:48:00.000Z"
}
```

### ❌ Akses Ditolak - Kartu Blacklist
```json
{
  "card_number": "KRT-99887766",
  "rfid_tag": "ABCDEF1234",
  "access_granted": false,
  "reason": "blacklisted",
  "timestamp": "2026-07-15T10:49:00.000Z"
}
```

### ❌ Akses Ditolak - Kartu Belum Berlaku
```json
{
  "card_number": "KRT-55667788",
  "rfid_tag": "9876543210",
  "access_granted": false,
  "reason": "not_yet_valid",
  "timestamp": "2026-07-15T10:50:00.000Z"
}
```

---

## 🔍 Cara Melihat Hasil

1. Buka **Dashboard** di browser
2. Scroll ke card **"Status RFID Reader (MQTT)"**
3. Data akan **langsung muncul real-time**!

### Tampilan Sukses:
- Card Number: KRT-12345678
- RFID Tag: A1B2C3D4E5
- Status: **✓ Akses Diberikan** (badge hijau)
- Timestamp: 15 Jul 2026, 10.46

### Tampilan Ditolak:
- Status: **✗ Akses Ditolak** (badge merah)
- Alasan: (contoh: "expired", "inactive", dll)

---

## 🐛 Troubleshooting

### Dashboard tidak terima message?
1. ✅ Pastikan Dashboard menunjukkan status **"Connected"**
2. ✅ Cek console browser (F12) untuk error
3. ✅ Pastikan QoS = **1** saat publish
4. ✅ Pastikan topic **persis** `gate/in/rfid_status` (case-sensitive)

### MQTT Client tidak bisa connect?
1. ✅ Cek broker aktif di `192.168.214.7`
2. ✅ Pastikan port 8083 (WebSocket) atau 1883 (TCP) terbuka
3. ✅ Username: `dev`, Password: `dev`

---

## 📝 Notes

- Dashboard auto-subscribe dengan **QoS 1** saat dibuka
- Data di-update **real-time** tanpa perlu refresh
- Message terakhir akan tetap ditampilkan hingga ada message baru
- Format timestamp bisa ISO 8601 atau format datetime lainnya

---

**Happy Testing! 🚀**

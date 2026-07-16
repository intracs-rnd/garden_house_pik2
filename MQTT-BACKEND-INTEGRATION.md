# MQTT Backend Integration

## 📋 Overview

Backend Laravel sekarang bisa **listen** ke MQTT topic `gate/in/rfid_status` dan **otomatis menyimpan** data tap kartu ke database table `kartu_access_logs`.

**Flow:**
```
RFID Reader → MQTT Broker → Laravel Listener → Database
                                    ↓
                            kartu_access_logs
```

---

## 🔧 Instalasi

### 1. Install PHP MQTT Client

```bash
cd backend-laravel
composer require php-mqtt/client
```

### 2. Verifikasi Command Terdaftar

```bash
php artisan list | grep mqtt
```

Harusnya muncul:
```
mqtt:rfid-listener    Listen to MQTT topic for RFID gate status
```

---

## 🚀 Cara Menjalankan

### Basic (dengan default config):

```bash
php artisan mqtt:rfid-listener
```

Default config:
- Host: `192.168.214.7`
- Port: `1883`
- Username: `dev`
- Password: `dev`
- Topic: `gate/in/rfid_status`
- QoS: `1`

### Custom config:

```bash
php artisan mqtt:rfid-listener \
  --host=192.168.1.100 \
  --port=1883 \
  --username=myuser \
  --password=mypass \
  --topic=custom/topic \
  --qos=2
```

---

## 📺 Output Console

Ketika berhasil connect dan listening:

```
🚀 Starting MQTT RFID Listener...
📡 Broker: 192.168.214.7:1883
📋 Topic: gate/in/rfid_status (QoS: 1)
👤 User: dev

Press Ctrl+C to stop

✅ Connected to MQTT broker
✅ Subscribed to: gate/in/rfid_status

🎧 Listening for messages...

📨 [2026-07-15 11:20:00] Message received on: gate/in/rfid_status
📦 Data: {
    "card_number": "KRT-12345678",
    "rfid_tag": "A1B2C3D4E5",
    "access_granted": true,
    "direction": 1,
    "reason": "ok",
    "gate": "Gate A",
    "timestamp": "2026-07-15T11:20:00.000Z"
}
💾 Saved to DB: #42 | KRT-12345678 | IN | ✅ GRANTED | Gate A
```

---

## 📡 Format Message MQTT

### Minimal (wajib):

```json
{
  "card_number": "KRT-12345678"
}
```

### Lengkap (recommended):

```json
{
  "card_number": "KRT-12345678",
  "rfid_tag": "A1B2C3D4E5",
  "access_granted": true,
  "direction": 1,
  "reason": "ok",
  "gate": "Gate A",
  "no_plat": "B1234XYZ",
  "timestamp": "2026-07-15T11:20:00.000Z"
}
```

### Field Mapping:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `card_number` | string | **required** | Nomor kartu |
| `rfid_tag` | string | - | RFID tag |
| `access_granted` | boolean | `false` | Akses diberikan/ditolak |
| `direction` | int | `1` | 1=IN, 2=OUT |
| `reason` | string | `"mqtt"` | Alasan (ok, expired, dll) |
| `gate` | string | `"MQTT"` | Nama gate |
| `no_plat` | string | - | Nomor plat kendaraan |
| `timestamp` | string | now() | ISO 8601 timestamp |

---

## 💾 Database Schema

Data disimpan ke table: **`kartu_access_logs`**

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `kartu_id` | bigint | FK ke `kartus.id` (nullable) |
| `user_id` | bigint | FK ke `users.id` (nullable) |
| `card_number` | string | Nomor kartu |
| `no_plat` | string | Nomor plat |
| `direction` | int | 1=IN, 2=OUT |
| `access_granted` | boolean | true/false |
| `reason` | string | Reason code |
| `gate` | string | Nama gate |
| `tapped_at` | datetime | Waktu tap |
| `created_at` | datetime | Auto |
| `updated_at` | datetime | Auto |

**Logic:**
- Jika `card_number` ditemukan di database → `kartu_id` dan `user_id` otomatis di-set
- Jika tidak ditemukan → tetap disimpan dengan `kartu_id = null`

---

## 🔁 Menjalankan Sebagai Service (Production)

### Option 1: Laravel Queue Worker (Recommended)

Buat service untuk background process:

**Linux (systemd):**

```bash
sudo nano /etc/systemd/system/mqtt-rfid-listener.service
```

```ini
[Unit]
Description=MQTT RFID Listener
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/gh-pik2/backend-laravel
ExecStart=/usr/bin/php artisan mqtt:rfid-listener
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Enable & start:
```bash
sudo systemctl enable mqtt-rfid-listener
sudo systemctl start mqtt-rfid-listener
sudo systemctl status mqtt-rfid-listener
```

**Windows (NSSM):**

```bash
# Install NSSM (Non-Sucking Service Manager)
choco install nssm

# Create service
nssm install MqttRfidListener "C:\php\php.exe" "artisan mqtt:rfid-listener"
nssm set MqttRfidListener AppDirectory "D:\kerjaan\Project\GH PIK2\backend-laravel"
nssm start MqttRfidListener
```

### Option 2: Supervisor (Alternative)

```bash
sudo nano /etc/supervisor/conf.d/mqtt-rfid-listener.conf
```

```ini
[program:mqtt-rfid-listener]
process_name=%(program_name)s
command=php artisan mqtt:rfid-listener
directory=/var/www/gh-pik2/backend-laravel
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/gh-pik2/storage/logs/mqtt-rfid-listener.log
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start mqtt-rfid-listener
```

---

## 🐛 Troubleshooting

### Error: "MQTT Client not installed!"

```bash
cd backend-laravel
composer require php-mqtt/client
```

### Error: "Connection refused"

- Cek broker MQTT aktif: `telnet 192.168.214.7 1883`
- Cek firewall allow port 1883
- Cek username/password benar

### Error: "Timeout"

- Naikkan timeout di command:
```php
$connectionSettings->setConnectTimeout(30);
```

### Error: "Authentication failed"

- Cek username/password di broker
- Cek ACL (Access Control List) di broker

### Logs

Semua error dan info tercatat di:
```
storage/logs/laravel.log
```

---

## 🧪 Testing

### 1. Jalankan Listener:

```bash
php artisan mqtt:rfid-listener
```

### 2. Publish Test Message:

**Menggunakan mosquitto_pub:**

```bash
mosquitto_pub \
  -h 192.168.214.7 \
  -p 1883 \
  -u dev \
  -P dev \
  -t gate/in/rfid_status \
  -q 1 \
  -m '{"card_number":"KRT-12345678","access_granted":true,"direction":1,"gate":"Test Gate"}'
```

**Menggunakan MQTTX (GUI):**

1. Connect ke broker
2. Topic: `gate/in/rfid_status`
3. QoS: `1`
4. Message: (JSON seperti di atas)
5. Publish

### 3. Cek Database:

```sql
SELECT * FROM kartu_access_logs ORDER BY id DESC LIMIT 10;
```

Harusnya ada entry baru dengan `gate = "Test Gate"`

### 4. Cek Frontend Dashboard:

Refresh dashboard → Feed "Live Kendaraan In/Out" harusnya muncul entry baru!

---

## 📊 Monitoring

### Cek Status Service (Linux):

```bash
sudo systemctl status mqtt-rfid-listener
```

### Cek Logs:

```bash
tail -f storage/logs/laravel.log | grep MQTT
```

### Cek Koneksi MQTT:

```bash
# Test subscribe manual
mosquitto_sub -h 192.168.214.7 -p 1883 -u dev -P dev -t gate/in/rfid_status -v
```

---

## 🔒 Security Notes

- ⚠️ Username/password di-hardcode di command options
- 💡 Sebaiknya simpan di `.env`:

```env
MQTT_HOST=192.168.214.7
MQTT_PORT=1883
MQTT_USERNAME=dev
MQTT_PASSWORD=dev
MQTT_TOPIC=gate/in/rfid_status
```

Lalu update command untuk baca dari `.env`

---

## 📝 Next Steps

1. ✅ Install package: `composer require php-mqtt/client`
2. ✅ Test run: `php artisan mqtt:rfid-listener`
3. ✅ Publish test message dari RFID reader
4. ✅ Verifikasi data masuk ke database
5. ✅ Setup sebagai service untuk production
6. ✅ Monitor logs dan performance

---

**Happy Integrating! 🚀**

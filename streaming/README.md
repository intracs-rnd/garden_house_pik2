# Live streaming CCTV (RTSP → browser, WebRTC low-latency)

Browser **tidak bisa** memutar URL `rtsp://` secara langsung. Jadi ada komponen
kecil di sisi server — **go2rtc** — yang menarik feed RTSP kamera dan
menyiarkannya ulang sebagai **WebRTC**, yang kemudian diputar oleh dashboard Vue
dengan latency **di bawah satu detik**.

```
Kamera (RTSP)  ──►  go2rtc  ──►  WebRTC (MSE/HLS fallback)  ──►  Vue <video>
rtsp://…/live2.sdp              ws://localhost:1984/api/ws?src=cam1 … src=cam4
```

Kredensial kamera (`root:cctv123456`) hanya berada **di sisi go2rtc**. Browser
hanya melihat URL WebSocket `localhost` yang bebas kredensial.

> **Kenapa ganti dari MediaMTX ke go2rtc?** MediaMTX menyajikan feed sebagai HLS,
> yang secara desain memakai buffer beberapa segmen → **delay beberapa detik**.
> go2rtc memakai **WebRTC** yang berjalan mendekati real-time (< 1 detik), jadi
> live CCTV tidak lagi terasa tertinggal.

---

## Alur kerja (bagaimana streaming ini bekerja)

1. **Kamera menyiarkan RTSP.** CCTV mengeluarkan video lewat
   `rtsp://root:cctv123456@192.168.203.119:554/live2.sdp`. Format RTSP tidak bisa
   dibuka langsung oleh browser.
2. **go2rtc menarik & merestream.** `start-stream.ps1` menjalankan `go2rtc.exe`
   dengan `go2rtc.yaml`. go2rtc menyambung ke RTSP kamera, lalu menyediakannya
   sebagai **WebRTC** (dan MSE/HLS sebagai cadangan) tanpa mengencode ulang bila
   codec-nya H.264/H.265.
3. **Browser memutar lewat WebRTC.** Komponen `LiveStream.vue` memakai web
   component go2rtc (`video-rtc.js`) yang melakukan signalling lewat WebSocket
   `ws://localhost:1984/api/ws?src=cam1`, lalu membuka koneksi WebRTC langsung ke
   go2rtc. `Dashboard.vue` menampilkan empat pemutar ini dalam grid 2x2.

```
Kamera ──RTSP──► go2rtc ──WebRTC──► <video>   (fallback: MSE → HLS → MJPEG)
```

> **Ganti kamera tanpa mengubah kode.** URL RTSP tiap kamera bisa diatur lewat
> menu **Pengaturan Kamera** di aplikasi (Sidebar → Pengaturan Kamera). Saat
> disimpan, backend mengirim sumber RTSP ke go2rtc lewat REST API
> (`PUT /api/streams?name=cam1&src=…`) sehingga feed langsung berubah tanpa perlu
> mengedit `go2rtc.yaml` atau restart. go2rtc juga menuliskan perubahan itu ke
> `go2rtc.yaml`, jadi tetap tersimpan setelah restart.

---

## Mulai cepat (satu perintah)

Dari folder `streaming/`, jalankan skrip bantuan. Skrip ini mengunduh go2rtc
secara otomatis pada pertama kali, lalu menjalankannya:

```powershell
powershell -ExecutionPolicy Bypass -File .\start-stream.ps1
```

Biarkan jendela itu tetap terbuka. Buka **http://localhost:1984/** untuk melihat
status stream dan player uji bawaan go2rtc. Kalau `cam1` sudah `online`, feed
sudah aktif. Jalankan aplikasi Vue (`npm run dev` di `frontend-vue`) dan kartu
**Live CCTV** akan menampilkan keempat feed dalam grid 2x2.

Untuk menghentikan stream, tekan `Ctrl+C` di jendela tersebut.

---

## Instalasi manual (opsional)

Satu file executable, tanpa kode, otomatis menyambung ulang.

1. Unduh `go2rtc_win64.zip` dari
   https://github.com/AlexxIT/go2rtc/releases lalu ekstrak `go2rtc.exe` ke folder
   `streaming/` ini (di samping `go2rtc.yaml`).
2. Buka terminal di sini dan jalankan:
   ```powershell
   .\go2rtc.exe -config go2rtc.yaml
   ```
3. Uji output sebelum menyentuh aplikasi:
   - Buka `http://localhost:1984/` (dashboard go2rtc), klik `cam1` → `webrtc`, **atau**
   - Buka `http://localhost:1984/stream.html?src=cam1`.

Itulah stream yang dipakai frontend lewat `VITE_STREAM_URL_1`
(`ws://localhost:1984/api/ws?src=cam1`); cam2..cam4 mengikuti pola yang sama.

---

## Penyambungan frontend (sudah dilakukan di repo ini)

- `frontend-vue/.env` → `VITE_STREAM_URL_1..4=http://localhost:1984/api/ws?src=cam1..4`
- `frontend-vue/src/components/common/video-rtc.js` → web component go2rtc (vendored).
- `frontend-vue/src/components/common/LiveStream.vue` → pemutar WebRTC.
- `frontend-vue/src/views/dashboard/Dashboard.vue` → kartu **Live CCTV** grid 2x2.

Backend:

- `backend-laravel/.env` → `GO2RTC_API_URL`, `GO2RTC_STREAM_URL`.
- `backend-laravel/app/Services/CameraService.php` → push sumber RTSP ke go2rtc.

---

## Checklist uji cepat

1. `192.168.203.119:554` (dan IP kamera lain) dapat dijangkau dari mesin ini.
2. Jalankan streamer (`start-stream.ps1` atau `go2rtc.exe -config go2rtc.yaml`).
3. Pastikan `http://localhost:1984/` menampilkan `cam1` `online` dan player
   `webrtc`-nya berjalan.
4. `npm run dev` di `frontend-vue`, buka aplikasi, dan kartu **Live CCTV** akan
   menampilkan grid 2x2 berisi feed dengan latency rendah.

## Catatan keamanan

- Jangan pernah menaruh URL `rtsp://user:password@...` di kode Vue, di file
  `.env` yang ter-commit, atau di URL stream. URL itu hanya milik `go2rtc.yaml`
  / REST API di sisi server.
- Untuk penggunaan bersama/produksi, aktifkan autentikasi go2rtc atau proksikan
  endpoint go2rtc di balik API Laravel daripada membiarkan port 1984 terbuka.

## Migrasi dari MediaMTX

File lama MediaMTX (`mediamtx.exe`, `mediamtx.yml`, `mediamtx*.log`) boleh
dibiarkan sebagai cadangan atau dihapus — sudah tidak dipakai. Konfigurasi
aktif sekarang ada di `go2rtc.yaml`, dan launcher `start-stream.ps1` menjalankan
go2rtc.

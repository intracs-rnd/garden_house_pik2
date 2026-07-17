# GH PIK2 · Frontend (Vue 3)

Admin panel single-page application built with **Vue 3 + Vite + Pinia + Vue Router**,
connected to the `backend-laravel` REST API (Laravel Sanctum token auth).

## ✨ Fitur

- Autentikasi (login / register / logout) menggunakan Sanctum Bearer token
- Proteksi route dengan navigation guard
- Dashboard statistik
- CRUD **Pengguna** (users)
- CRUD **Kendaraan** dengan filter kategori & status
- State management dengan Pinia, HTTP layer dengan Axios (interceptor token & 401)
- Notifikasi toast, modal konfirmasi, pagination, loader

## 🔧 Prasyarat

- Node.js 18+ (diuji pada Node 23)
- Backend Laravel berjalan di `http://localhost:8000`

## 🚀 Menjalankan

```bash
cd frontend-vue
npm install
npm run dev
```

Aplikasi berjalan di **http://localhost:3000**.

## ⚙️ Konfigurasi

Salin `.env.example` menjadi `.env` bila belum ada, lalu sesuaikan:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_UPLOADS_API_URL=http://192.168.214.7:4000/api/uploads
VITE_APP_NAME=GH PIK2
```

> `VITE_API_BASE_URL` harus menyertakan akhiran `/api`.
> `VITE_UPLOADS_API_URL` dipakai untuk ambil gambar transaksi di halaman laporan (method `POST` dengan body `{ "path": "..." }`).

## 🔌 Koneksi ke Backend

1. Jalankan backend Laravel:
   ```bash
   cd ../backend-laravel
   php artisan serve   # http://localhost:8000
   ```
2. Pastikan CORS mengizinkan origin frontend (default `config/cors.php` sudah `*`).
3. Token disimpan di `localStorage` dan otomatis dikirim sebagai
   `Authorization: Bearer <token>` pada setiap request.

## 📁 Struktur

```
src/
├── api/          # Axios instance + endpoint (auth, user, kendaraan, category, dashboard)
├── assets/       # css, images, icons, fonts
├── components/
│   ├── common/   # Button, Card, Modal, Loader, Pagination, ToastHost
│   ├── forms/    # UserForm, KendaraanForm
│   └── layout/   # DefaultLayout, Sidebar, Navbar, Header, Footer
├── composables/  # useToast
├── router/       # definisi route + guard
├── stores/       # Pinia store (auth, user, kendaraan)
├── utils/        # formatter, helper, validator
└── views/        # auth, dashboard, users, kendaraan, NotFound
```

## 🏗️ Build produksi

```bash
npm run build      # output ke folder dist/
npm run preview    # preview hasil build
```

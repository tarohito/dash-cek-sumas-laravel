# Sumas Dashboard (Laravel + React)

Sistem monitoring keaslian produk berbasis QR Code, manajemen batch & tag fisik, kontrol akses multi-tenant (RBAC), serta telemetri lokasi pemindaian secara real-time. Dibangun menggunakan backend **Laravel 12** dan antarmuka SPA **React 19 + TypeScript + Tailwind CSS v4**.

---

## Ringkasan Fitur & Arsitektur

- **Autentikasi & Keamanan Sesi**
  - Hashing kata sandi menggunakan **bcrypt** (`Hash::make` / `Hash::check`).
  - Manajemen sesi berbasis database (`user_sessions`) dengan token acak 256-bit yang di-hash menggunakan **HMAC-SHA256**, cookie `HttpOnly`, dukungan header `Authorization: Bearer`, serta batas maksimal 10 sesi aktif per akun.
  - Proteksi brute-force lockout pada endpoint login dan rate limiter terpisah untuk endpoint publik, autentikasi, dan operasi berat.
- **Kontrol Akses Multi-Tenant (RBAC)**
  - Pembagian hak akses untuk peran `Super Admin` (global) dan `Admin Brand` (terisolasi per kepemilikan brand).
  - Matriks izin dapat dikonfigurasi secara dinamis dari panel pengaturan tanpa mengubah kode.
- **Telemetri QR & Deteksi Anomali Lokasi**
  - Verifikasi publik `/q/{tagNumber}` dengan dukungan mode `flexible`, `hybrid`, dan `strict` GPS.
  - Deteksi anomali otomatis: jarak Haversine, *Impossible Travel Velocity* (`maxVelocityKmh`), batas scan sah/waspada/palsu, cooldown deduplikasi, deteksi mock location, serta pembatasan koordinat wilayah Indonesia.
  - Peta sebaran lokasi pemindaian interaktif menggunakan Leaflet & OpenStreetMap beserta grafik tren aktivitas (`7d`, `30d`, `12m`).
- **Manajemen Master Data & Ekspor**
  - CRUD Brand/Merk, Kategori bertingkat (Level 1–3 dengan skema spesifikasi dinamis), Produk (SKU) beserta relasi batch/tag, alokasi proyek B2B, ekspor PDF/QR Code, serta backup/restore database JSON & SQL.

---

## Teknologi & Prasyarat Server

| Komponen | Spesifikasi |
|---|---|
| Backend | PHP 8.2 / 8.3, Laravel 12 |
| Frontend | React 19, TypeScript, Vite 7, Tailwind CSS v4 |
| Peta & Grafik | Leaflet, Recharts |
| Dokumen & QR | jsPDF, qrcode, Lucide Icons |
| Database | MySQL 8.0+ / MariaDB 10.6+ (dukungan SQLite untuk pengujian) |

**Ekstensi PHP yang dibutuhkan:** `pdo_mysql`, `mbstring`, `openssl`, `xml`, `dom`, `tokenizer`, `fileinfo`, `ctype`, `json`.

---

## Struktur Direktori Utama

```text
.
├── app/
│   ├── Http/
│   │   ├── Controllers/      # Controller API, verifikasi QR publik, dan SPA entry
│   │   └── Middleware/       # Sanitasi input API dan autentikasi sesi
│   ├── Providers/            # Konfigurasi rate limiter & service provider
│   └── Services/             # Kebijakan keamanan QR/GPS, RBAC, sesi, dan notifikasi
├── config/                   # Konfigurasi standar Laravel (database, session, cache, dll.)
├── database/
│   ├── migrations/           # Skema 15 tabel Sumas + tabel cache & queue Laravel
│   └── seeders/              # SumasSeeder dan dataset awal sumas_seed.json
├── public/
│   ├── .htaccess             # Rewrite SPA/API, header Authorization, gzip & cache aset
│   ├── build/                # Hasil kompilasi produksi Vite (dibuat saat npm run build)
│   └── fonts/                # Font lokal (KodeMono) untuk generator PDF
├── resources/views/          # Template utama SPA (app.blade.php)
├── routes/
│   ├── api.php               # Seluruh endpoint REST API (/api/*)
│   └── web.php               # Route verifikasi publik (/q/{tagNumber}) dan SPA fallback
├── src/                      # Kode sumber frontend React + TypeScript
└── vite.config.ts            # Konfigurasi Vite dengan pemecahan chunk vendor
```

---

## Skema Database & Data Awal (Seeder)

Migrasi [`database/migrations/2026_01_01_000001_create_sumas_tables.php`](database/migrations/2026_01_01_000001_create_sumas_tables.php) menyiapkan 15 tabel utama dengan panjang kolom dan indeks yang kompatibel penuh dengan InnoDB `utf8mb4`:

`batches`, `tags`, `scan_points`, `scan_activities`, `audit_logs`, `users`, `brands`, `products`, `password_reset_requests`, `notifications`, `read_notifications`, `categories`, `system_settings`, `server_secrets`, `user_sessions`.

[`SumasSeeder`](database/seeders/SumasSeeder.php) mengisi data awal yang konsisten langsung dari `database/seeders/data/sumas_seed.json`:
- `3` akun pengguna dengan hash **bcrypt**
- `7` batch produksi dengan total **1.270 tag real** sesuai `qty` masing-masing batch
- `10` titik pemindaian beserta riwayat event & `24` agregasi aktivitas (`7d`, `30d`, `12m`)
- `15` brand, `15` produk SKU, `17` kategori, `7` audit log, dan `2` konfigurasi sistem

### Akun Bawaan (Seeder)

| Peran | Email | Kata Sandi | Cakupan Akses |
|---|---|---|---|
| Super Admin | `kalinggajeandy3@gmail.com` | `SumasPass2026!` | Seluruh sistem & brand |
| Admin Brand | `budi.s@sumas.id` | `SumasPass2026!` | Brand Sumas |
| Admin Brand | `rina.w@sumas.id` | `rinawijaya01` | Somethinc, Skintific, Whitelab |

Segera ubah kata sandi akun bawaan setelah aplikasi berjalan di lingkungan produksi.

---

## Instalasi & Pengembangan Lokal

1. Clone repository dan masuk ke direktori proyek:
   ```bash
   git clone https://github.com/tarohito/dash-cek-sumas-laravel.git
   cd dash-cek-sumas-laravel
   ```

2. Salin konfigurasi environment dan sesuaikan kredensial MySQL lokal:
   ```bash
   cp .env.example .env
   ```

3. Pasang dependensi backend dan frontend:
   ```bash
   composer install
   npm install
   ```

4. Generate application key, jalankan migrasi beserta seeder, dan build aset frontend:
   ```bash
   php artisan key:generate
   php artisan migrate --seed
   npm run build
   ```

5. Jalankan server pengembangan lokal:
   ```bash
   php artisan serve
   ```
   Untuk pengembangan frontend secara langsung dengan hot-reload, jalankan `npm run dev` pada terminal terpisah.

---

## Panduan Deploy Produksi (Shared / Cloud Hosting)

Panduan ini berlaku untuk deployment di hosting berbasis Apache/LiteSpeed + MySQL (seperti Niagahoster):

1. **Siapkan Database MySQL:**
   Buat database dan user MySQL melalui panel hosting, lalu catat host, nama database, username, dan password.

2. **Build Aset di Lokal (Jika Server Tidak Menjalankan Node.js):**
   ```bash
   composer install --no-dev --optimize-autoloader
   npm install
   npm run build
   ```
   Pastikan folder `public/build/` ikut terunggah ke server.

3. **Konfigurasi `.env` Produksi:**
   Salin `.env.example` menjadi `.env` di server dan sesuaikan nilainya:
   ```env
   APP_NAME="Sumas Dashboard"
   APP_ENV=production
   APP_KEY=
   APP_DEBUG=false
   APP_URL=https://domainanda.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=nama_database_anda
   DB_USERNAME=user_database_anda
   DB_PASSWORD=password_database_anda

   SESSION_DRIVER=file
   CACHE_STORE=file
   QUEUE_CONNECTION=sync
   ```

4. **Konfigurasi Web Server & Hak Akses:**
   - Pilih versi **PHP 8.2** atau **PHP 8.3** dan aktifkan seluruh ekstensi yang tercantum pada bagian prasyarat.
   - Arahkan **Document Root** domain/subdomain langsung ke direktori `public/` agar `.env` dan source code tidak dapat diakses langsung dari web.
   - Pastikan direktori `storage/` dan `bootstrap/cache/` memiliki izin tulis (*writable*).

5. **Inisialisasi Key, Migrasi Database, & Optimasi Cache Laravel:**
   Jalankan melalui SSH / Terminal hosting di root direktori proyek:
   ```bash
   php artisan key:generate
   php artisan migrate --seed --force
   php artisan optimize
   ```

---

## Ringkasan Endpoint Utama

- **Publik & Verifikasi:**
  - `GET /q/{tagNumber}` — Halaman verifikasi QR konsumen beserta validasi lokasi/GPS
  - `POST /api/q/{tagNumber}/verify` & `POST /api/scans/record` — Pencatatan telemetri scan
  - `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`, `POST /api/password-resets`
- **Dashboard & Telemetri (Terautentikasi):**
  - `GET /api/dashboard/stats`, `GET /api/scans/locations`, `GET /api/scans/stats`, `GET /api/scans/activity`
- **Manajemen Data & Sistem (Terautentikasi + RBAC):**
  - `/api/batches/*` — Pembuatan batch, daftar tag terpaginasi, alokasi proyek B2B, redirect URL, suspend, audit log
  - `/api/brands/*`, `/api/categories/*`, `/api/products/*`, `/api/users/*`
  - `/api/rbac/permissions`, `/api/notifications/*`, `/api/settings/*` (termasuk backup & restore penuh)

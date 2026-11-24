# Sumas Dashboard (Laravel + React)

Sistem monitoring keaslian produk berbasis QR Code, manajemen batch & tag fisik, editor layout stiker PDF presisi milimeter, kontrol akses multi-tenant (RBAC), notifikasi real-time, serta telemetri geolokasi pemindaian. Dibangun dengan backend **Laravel 12** dan antarmuka SPA **React 19 + TypeScript + Tailwind CSS v4**.

---

## Ringkasan Fitur & Arsitektur

- **Autentikasi, Sesi, & Keamanan API**
  - Hashing kata sandi menggunakan **bcrypt** (`Hash::make` / `Hash::check`).
  - Manajemen sesi berbasis database (`user_sessions`) dengan token acak 256-bit yang di-hash menggunakan **HMAC-SHA256**, cookie `HttpOnly` (`sumas_session`, `SameSite=Lax`), rotasi token otomatis, dukungan header `Authorization: Bearer`, serta batas sesi aktif per akun.
  - Validasi *Same-Origin* & CSRF ganda (`sumas_csrf` + header `X-Sumas-CSRF`), sanitasi input API (`SanitizeApiInput`), kebijakan CORS ketat (`config/cors.php`), serta header keamanan HTTP (`SecurityHeaders`: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, dan HSTS pada HTTPS).
  - Rate limiter terpisah (`api-global`, `auth-login`, `auth-reset`, `public-scan`, `heavy-ops`) pada `AppServiceProvider`.
- **Kontrol Akses Multi-Tenant (RBAC)**
  - Pembagian hak akses untuk peran `Super Admin` (akses global seluruh sistem, manajemen layout tag, & unduh dokumen batch) dan `Admin Brand` (terisolasi per kepemilikan brand).
  - Matriks 22 izin granular dapat dikonfigurasi dinamis dari panel pengaturan tanpa mengubah kode (`RbacService`).
- **Telemetri QR & Deteksi Anomali Lokasi**
  - Verifikasi publik `/q/{tagNumber}` dengan dukungan mode validasi GPS `flexible`, `hybrid`, dan `strict`.
  - Deteksi anomali otomatis: jarak Haversine, *Impossible Travel Velocity* (`maxVelocityKmh`), ambang batas scan sah/waspada/palsu, cooldown deduplikasi, deteksi mock location, dan pembatasan koordinat wilayah Indonesia.
  - Peta sebaran lokasi pemindaian interaktif menggunakan **Leaflet** & OpenStreetMap serta grafik tren aktivitas (`7d`, `30d`, `12m`) teragregasi dinamis dari database menggunakan **Recharts**.
- **Tag Layout Studio & Ekspor PDF Vektor**
  - Editor visual layout stiker QR & PIN berbasis satuan milimeter (`mm`) dengan 3 preset bawaan (`builtin-sumas-25x60`, `builtin-compact-30x40`, `builtin-seal-50x22`), template kustom tersinkron ke server (`TagLayoutController`), serta dukungan *multi-select* (marquee drag, `Shift/Ctrl+Klik`, `Ctrl+A`, bulk align/nudge/duplicate/delete/z-index).
  - Kontrol tipografi lengkap: font (`Kode Mono`, `Sans / Helvetica`, `Serif / Times`), ukuran (`2–300 pt`), ketebalan (`Bold` / `Normal`), **kerenggangan teks / tracking (`pt`)**, perataan (`Kiri` / `Tengah` / `Kanan`) presisi pada sudut rotasi berapa pun, serta token dinamis (`{brand}`, `{sku}`, `{batchId}`, `{tagNumber}`, `{pin}`).
  - Kontrol bentuk & die-cut: rotasi kotak/rounded-rect vektor (kurva Bezier), mode *outline-only* (isi transparan), ketebalan & warna garis potong die-cut (`mm`).
  - Ekspor massal stiker ke PDF menggunakan **jsPDF** dan **qrcode**, dengan **QR Code dirender sebagai compound vector path murni** (bukan bitmap/PNG) sehingga tetap tajam dan dapat diedit bebas di Adobe Illustrator / CorelDRAW.
- **PIN Alfanumerik CSPRNG & Manajemen Master Data**
  - **Keamanan PIN**: Seluruh batch baru menggunakan PIN kombinasi **huruf kapital & angka anti-ambiguitas** (`ABCDEFGHJKLMNPQRSTUVWXYZ23456789`, mengecualikan `0`, `O`, `1`, `I`) yang diacak dengan OS CSPRNG (`random_int`), panjang fleksibel **4–8 digit** (default `8 digit`), validasi penolakan pola lemah/berurutan, serta pengecekan anti-duplikasi di level batch & database.
  - **Brand/Merk & Kategori Bertingkat**: CRUD brand dengan foto/logo `1:1` atau avatar inisial deterministik, hierarki kategori Level 1–3 dengan spesifikasi dinamis, serta sinkronisasi otomatis perubahan nama Brand/Kategori/SKU ke batch, produk, titik scan, dan Tempat Sampah.
  - **Produk (SKU), Batch & Tag**: CRUD SKU (`products.category` hingga 400 karakter), pembuatan batch & ribuan tag fisik, alokasi proyek B2B, pengaturan redirect URL, suspend batch/tag, audit log, serta backup & restore penuh JSON/SQL.
  - **Tempat Sampah (Trash Manager)**: Penghapusan aman berbasis soft-delete (`trash_items`) untuk Brand, Kategori, SKU, Batch, User, dan Tag Layout dengan pemulihan (*restore*), hapus permanen (satuan/massal/kosongkan), serta pembersihan otomatis item kedaluwarsa (> 30 hari). Item di Tempat Sampah otomatis dikecualikan dari statistik dashboard, daftar SKU aktif, dan peta scan.
  - **Notifikasi Real-Time**: Pembaruan streaming via **Server-Sent Events (SSE)** pada `/api/notifications/stream` yang terisolasi sesuai peran dan cakupan brand.
- **Font Self-Hosted (Tanpa CDN Eksternal)**
  - Seluruh font UI (`Plus Jakarta Sans` weight `400`, `500`, `600`, `700`, `800` dan `Kode Mono` weight `400`, `600`, `700`) dikemas langsung ke dalam bundle Vite melalui `@fontsource/plus-jakarta-sans` dan `@fontsource/kode-mono`, ditambah `public/fonts/KodeMono-Bold.ttf` untuk generator PDF, sehingga tampilan tetap konsisten tanpa bergantung pada Google Fonts CDN.

---

## Teknologi & Prasyarat Server

| Komponen | Spesifikasi |
|---|---|
| Backend | PHP `^8.2` (disarankan PHP 8.2 / 8.3), Laravel 12 |
| Frontend | React 19, TypeScript 7, Vite 7, Tailwind CSS v4 |
| Font Lokal | `@fontsource/plus-jakarta-sans`, `@fontsource/kode-mono`, `KodeMono-Bold.ttf` |
| Peta & Grafik | Leaflet, Recharts |
| Dokumen & UI | jsPDF, qrcode (vector path PDF), Lucide React, Motion |
| Database | MySQL 8.0+ / MariaDB 10.6+ (produksi); PostgreSQL / SQLite didukung untuk container & pengujian |

**Ekstensi PHP yang dibutuhkan:** `pdo_mysql`, `mbstring`, `openssl`, `xml`, `dom`, `tokenizer`, `fileinfo`, `ctype`, `json`, `bcmath`, `zip` (tambahkan `pdo_pgsql` atau `pdo_sqlite` jika menggunakan PostgreSQL/SQLite).  
**Tooling build frontend:** Node.js `>= 20` (disarankan `22 LTS`) dan `npm`.

---

## Struktur Direktori Utama

```text
.
├── app/
│   ├── Http/
│   │   ├── Controllers/      # Controller REST API (Batch, Brand, Product, Category, Scan, Trash, TagLayout, dll.)
│   │   └── Middleware/       # Sanitasi input API, security headers, & autentikasi sesi + CSRF
│   ├── Providers/            # Konfigurasi rate limiter, HTTPS forcing, & service provider
│   └── Services/             # Kebijakan keamanan QR/GPS, RBAC, sesi, notifikasi, TrashService, & hardening
├── bootstrap/app.php         # Registrasi routing, middleware, trusted proxies, & cookie encryption
├── config/                   # Konfigurasi Laravel (database, cors, session, cache, dll.)
├── database/
│   ├── migrations/           # Skema 16 tabel Sumas (termasuk trash_items) + migrasi penyesuaian
│   └── seeders/              # SumasSeeder & dataset awal database/seeders/data/sumas_seed.json
├── docker/                   # Konfigurasi Apache, mpm_prefork, & entrypoint container produksi
├── public/
│   ├── .htaccess             # Rewrite SPA/API, header Authorization & X-XSRF-Token, gzip, cache 1 tahun
│   ├── build/                # Hasil kompilasi produksi Vite (dibuat oleh npm run build, gitignored)
│   └── fonts/                # Font lokal KodeMono-Bold.ttf untuk generator PDF jsPDF
├── resources/views/          # Template utama SPA (app.blade.php) & boot splash screen
├── routes/
│   ├── api.php               # Seluruh endpoint REST API (/api/*)
│   └── web.php               # Route verifikasi publik (/q/{tagNumber}) dan SPA fallback
├── src/                      # Kode sumber frontend React + TypeScript (App, components, utils, types)
├── Dockerfile                # Multi-stage build (Node 22 + PHP 8.3 Apache) untuk Docker/Render/Railway
└── vite.config.ts            # Konfigurasi Vite + Laravel plugin + manual vendor chunks
```

---

## Skema Database & Data Awal (Seeder)

Daftar file migrasi aplikasi:
- [`2026_01_01_000001_create_sumas_tables.php`](database/migrations/2026_01_01_000001_create_sumas_tables.php) — Menyiapkan 15 tabel utama kompatibel InnoDB `utf8mb4`:
  `batches`, `tags`, `scan_points`, `scan_activities`, `audit_logs`, `users`, `brands`, `products`, `password_reset_requests`, `notifications`, `read_notifications`, `categories`, `system_settings`, `server_secrets`, `user_sessions`.
- [`2026_11_24_000001_drop_category_from_brands_table.php`](database/migrations/2026_11_24_000001_drop_category_from_brands_table.php) — Menghapus kolom `category` dari tabel `brands`.
- [`2026_11_24_000002_expand_category_length_on_products_table.php`](database/migrations/2026_11_24_000002_expand_category_length_on_products_table.php) — Memperluas panjang kolom `products.category` menjadi `400` karakter untuk path kategori bertingkat.
- [`2026_11_24_000003_create_trash_items_table.php`](database/migrations/2026_11_24_000003_create_trash_items_table.php) — Menyiapkan tabel `trash_items` untuk fitur Tempat Sampah (soft-delete + retensi 30 hari).

[`SumasSeeder`](database/seeders/SumasSeeder.php) mengisi dataset konsisten dari `database/seeders/data/sumas_seed.json`:
- `3` akun pengguna dengan hash **bcrypt**
- `7` batch produksi dengan total **1.270 tag aktif** menggunakan **PIN alfanumerik CSPRNG** (kombinasi huruf & angka)
- `10` titik pemindaian (tersinkron dengan PIN & tag aktif) & `24` data agregasi aktivitas (`7d`, `30d`, `12m`)
- `15` brand, `15` produk SKU, `17` kategori, `7` audit log, dan `2` konfigurasi sistem

### Akun Bawaan (Seeder)

| Peran | Email | Kata Sandi | Cakupan Akses |
|---|---|---|---|
| Super Admin | `kalinggajeandy3@gmail.com` | `SumasPass2026!` | Seluruh sistem & semua brand |
| Admin Brand | `budi.s@sumas.id` | `SumasPass2026!` | Brand Sumas |
| Admin Brand | `rina.w@sumas.id` | `rinawijaya01` | Somethinc, Skintific, Whitelab |

> Segera ubah kata sandi seluruh akun bawaan setelah aplikasi dijalankan di lingkungan produksi.

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
   Untuk pengembangan aktif dengan hot-reload Vite pada terminal terpisah, jalankan:
   ```bash
   npm run dev
   ```
   Atau jalankan seluruh proses pengembangan sekaligus (server Laravel, queue listener, logs, & Vite) dengan:
   ```bash
   composer run dev
   ```

   Pengecekan TypeScript sebelum build produksi:
   ```bash
   npx tsc --noEmit && npm run build
   ```

---

## Panduan Deploy Produksi (Hostinger / Shared & Cloud Hosting)

Panduan ini berlaku untuk deployment di hosting berbasis **Apache / LiteSpeed + MySQL** (seperti **Hostinger** hPanel atau Niagahoster):

1. **Siapkan Database MySQL di Panel Hosting:**
   - Buat database dan user MySQL melalui hPanel (*Databases → Management*), lalu catat `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, dan `DB_HOST` (umumnya `127.0.0.1` atau `localhost`).

2. **Build Aset di Lokal (Wajib Jika Server Tidak Menyediakan Node.js):**
   Karena folder `/vendor`, `/node_modules`, dan `/public/build` diabaikan oleh [`.gitignore`](.gitignore), jalankan build produksi sebelum mengunggah kode ke server:
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci
   npm run build
   ```
   Pastikan folder **`public/build/`** (berisi `manifest.json` serta file JS, CSS, dan font `.woff2`/`.woff` di `public/build/assets/`) dan **`vendor/`** ikut terunggah ke server. Jika hosting memiliki akses SSH dengan Node.js, cukup pull repo lalu jalankan kedua perintah di atas langsung di server.

3. **Konfigurasi `.env` Produksi:**
   Salin `.env.example` menjadi `.env` di root proyek pada server dan sesuaikan nilainya:
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
   > Jika domain diakses melalui sub-domain atau origin berbeda yang sah, Anda dapat menambahkan `CORS_ALLOWED_ORIGIN=https://domainanda.com` (secara default aplikasi memakai kebijakan same-origin ketat).

4. **Konfigurasi Document Root & Hak Akses:**
   - Pilih versi **PHP 8.2** atau **PHP 8.3** di hPanel dan pastikan seluruh ekstensi prasyarat aktif.
   - Arahkan **Document Root** domain/subdomain langsung ke folder **`public/`** milik proyek Laravel (bukan ke root proyek) agar file `.env`, `storage/`, dan kode backend tidak dapat diakses dari browser.
   - Pastikan direktori `storage/` (beserta sub-foldernya) dan `bootstrap/cache/` memiliki izin tulis (*writable*, misalnya `775`).

5. **Inisialisasi Key, Migrasi Database, & Optimasi Cache Laravel:**
   Jalankan melalui SSH / Terminal di root direktori proyek:
   ```bash
   php artisan key:generate
   php artisan migrate --seed --force
   php artisan optimize
   ```
   > **Saat memperbarui aplikasi (update release):** setiap kali ada perubahan kode frontend, unggah ulang folder `public/build/` hasil `npm run build`, lalu jalankan:
   > ```bash
   > php artisan migrate --force
   > php artisan optimize:clear
   > php artisan optimize
   > ```

---

## Panduan Deploy dengan Docker (Render / Railway / VPS)

Repository menyertakan [`Dockerfile`](Dockerfile) multi-stage (`node:22-alpine` + `php:8.3-apache`) dan [`docker/entrypoint.sh`](docker/entrypoint.sh):
- Frontend (`npm ci && npm run build`) otomatis dikompilasi ke `public/build` pada tahap build image.
- Saat container menyala, `entrypoint.sh` otomatis:
  - Mendeteksi `DB_CONNECTION` (`pgsql`, `mysql`, atau fallback `sqlite`).
  - Menjalankan `php artisan migrate --force`.
  - Menjalankan `SumasSeeder` secara otomatis hanya jika tabel `users` masih kosong (dapat diatur via `AUTO_SEED_IF_EMPTY=true` atau dipaksa dengan `RUN_SEEDER=true`).
  - Menjalankan `config:cache`, `route:cache`, dan `view:cache` sebelum memulai Apache.

```bash
docker build -t sumas-dashboard .
docker run -p 10000:10000 -e APP_KEY="base64:..." -e APP_URL="https://domainanda.com" sumas-dashboard
```

---

## Ringkasan Endpoint Utama

- **Publik & Verifikasi:**
  - `GET /q/{tagNumber}` — Halaman verifikasi QR konsumen beserta validasi lokasi/GPS
  - `POST /api/q/{tagNumber}/verify` & `POST /api/scans/record` — Validasi tag dan pencatatan telemetri scan
  - `GET /api/auth/me`, `GET /api/auth/demo-accounts`, `POST /api/auth/login`, `POST /api/auth/logout`, `POST /api/password-resets`
- **Dashboard, Profil & Telemetri (Terautentikasi):**
  - `GET /api/auth/check-username`, `PUT /api/auth/profile`
  - `GET /api/dashboard/stats`, `GET /api/scans/locations`, `GET /api/scans/stats`, `GET /api/scans/activity`
- **Manajemen Data, Tag Layout, Tempat Sampah & Sistem (Terautentikasi + RBAC):**
  - `/api/batches/*` — Pembuatan batch (PIN alfanumerik CSPRNG 4–8 digit), daftar tag terpaginasi, seluruh tag (`all-tags`), ringkasan, alokasi/dealokasi proyek B2B, redirect URL, edit, & hapus batch
  - `/api/tag-layouts*` — Daftar preset/template layout stiker, simpan/ubah template, aktivasi template default, & hapus template kustom
  - `/api/trash*` — Daftar Tempat Sampah, `count`, `restore`, `bulk-restore`, `bulk-delete`, hapus permanen, & `empty`
  - `/api/brands/*`, `/api/categories/*` (termasuk `/specs`), `/api/products/*` (termasuk `/relational`), `/api/users/*`, `/api/audit-logs`, `/api/password-resets/*`
  - `/api/rbac/permissions`, `/api/notifications/*` (termasuk SSE `/stream` dan `mark-read`), `/api/settings/*` (termasuk `test-alert`, `backup`, & `restore`)

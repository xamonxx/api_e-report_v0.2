# E-Report API Backend (Laravel 11)

<p align="center">
  Backend API Headless berbasis Laravel 11 untuk manajemen konsultasi, lead tracking, pengingat (reminders), analitik, absensi harian, dan pelaporan operasional.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-11-red?style=for-the-badge&logo=laravel" alt="Laravel 11">
  <img src="https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.2">
  <img src="https://img.shields.io/badge/MySQL-Ready-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL Ready">
  <img src="https://img.shields.io/badge/Redis-Optional-red?style=for-the-badge&logo=redis" alt="Redis">
</p>

---

## 📌 Deskripsi Proyek

Repository ini berisi kode sumber **Backend API E-Report** yang dirancang sebagai headless CMS untuk berpasangan dengan frontend Next.js. Backend ini menangani seluruh logika bisnis utama, autentikasi berbasis cookie (Laravel Sanctum SPA), optimasi pencarian dengan database indexing, antrean pengunggahan data (queue), manajemen wilayah Indonesia, dan pembersihan log otomatis.

---

## 🛠️ Tech Stack & Spesifikasi

- **Framework**: Laravel 11.x
- **Bahasa**: PHP 8.2 / 8.3
- **Database**: MySQL 8.0+ / MariaDB
- **Autentikasi**: Laravel Sanctum (Stateful Cookie-based SPA Session)
- **Ekspor Dokumen**: DOMPDF (PDF) & Laravel Excel (XLSX)
- **Caching & Queue**: Database / Redis via Predis

---

## 📦 Struktur Proyek (Backend)

```text
app/
 ├── Console/Commands/      # Command CLI custom (misal: SyncWilayah)
 ├── Http/Controllers/Api/  # REST API Controllers (V1)
 ├── Http/Requests/         # Form Request Validation rules
 ├── Models/                # Eloquent Models & Relationship definitions
 ├── Services/              # Logika Bisnis Kompleks (misal: ConsultationImportService)
 └── Traits/                # Caching queries & helper reusable traits
config/                     # Konfigurasi laravel (cors, sanctum, wilayah, dll.)
database/
 ├── migrations/            # Struktur tabel & optimalisasi indeks database
 └── seeders/               # Seeders awal untuk data wilayah & testing
routes/api.php              # Definisi API Endpoints (/api/v1/*)
reset-pass.php              # Script utilitas pemulihan password darurat
```

---

## 🚀 Langkah Instalasi & Setup Lokal

### 1. Persiapan Awal
Pastikan Anda menggunakan PHP >= 8.2 dengan ekstensi yang diperlukan (`pdo_mysql`, `mbstring`, `openssl`, `xml`, `zip`, `gd`).

### 2. Kloning Repository
```bash
git clone https://github.com/xamonxx/api_e-report_v0.2.git
cd api_e-report_v0.2
```

### 3. Install Dependensi Composer
```bash
composer install
```

### 4. Konfigurasi Environment (`.env`)
Salin file konfigurasi contoh dan buat application key:
```bash
cp .env.example .env
php artisan key:generate
```

Buka file `.env` dan konfigurasikan database, caching, queue, serta URL stateful untuk Sanctum:
```ini
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:3000

# Sanctum Stateful Domains untuk Next.js (tanpa http/https prefix)
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=e_report_db
DB_USERNAME=root
DB_PASSWORD=

# Gunakan queue database atau redis untuk background import CSV
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### 5. Setup Database
Jalankan migrasi struktur database beserta seeder data bawaan (wilayah, kategori, status awal):
```bash
php artisan migrate --seed
```

### 6. Menjalankan Server
Jalankan web server Laravel lokal:
```bash
php artisan serve
```
Dan jalankan worker antrean di tab terminal terpisah untuk memproses queue upload CSV:
```bash
php artisan queue:work
```

---

## 🔒 Alur Autentikasi (Next.js - Laravel Sanctum SPA)

Aplikasi ini menggunakan **Cookie-based SPA Authentication** dari Laravel Sanctum. Sebelum melakukan permintaan login, aplikasi frontend Next.js harus:
1. Memanggil **`GET /sanctum/csrf-cookie`** untuk mendapatkan cookie proteksi CSRF.
2. Memanggil **`POST /api/v1/auth/login`** dengan parameter `email` dan `password` untuk membuat session cookie.
3. Permintaan selanjutnya akan otomatis membawa session cookie untuk autentikasi yang aman.

---

## 📡 Daftar API Endpoints (`/api/v1/...`)

### 🔑 Autentikasi & Profil
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `POST` | `/auth/login` | Login user, mengembalikan status sukses & membuat session cookie | Public (Throttle: 10/min) |
| `GET` | `/auth/me` | Mendapatkan data informasi profile user aktif | Logged-in |
| `POST` | `/auth/logout` | Menghapus session user aktif | Logged-in |
| `POST` | `/settings/profile` | Update profil user aktif (Nama, Email, Password Baru) | Logged-in |
| `POST` | `/settings/theme` | Menyimpan preferensi primary color tema dashboard user | Logged-in |

### 📊 Dashboard & Analitik
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/dashboard` | Mendapatkan ringkasan statistik leads, pengingat, dan status kehadiran harian | Logged-in |
| `GET` | `/analytics` | Menghasilkan data grafik tren, distibusi wilayah, kebutuhan, status, dan performa admin | Logged-in |

### 📋 Manajemen Konsultasi / Leads
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/consultations` | Mendapatkan daftar paginasi data konsultasi (mendukung query pencarian & multi-filter) | Logged-in |
| `GET` | `/consultations/id-preview` | Mengembalikan preview string ID konsultasi berikutnya untuk bulan/tahun tertentu | Logged-in |
| `POST` | `/consultations` | Membuat data lead konsultasi baru | Logged-in |
| `GET` | `/consultations/{id}` | Mengambil detail lengkap konsultasi | Logged-in |
| `PUT/PATCH`| `/consultations/{id}` | Update informasi data konsultasi | Logged-in |
| `DELETE` | `/consultations/{id}` | Menghapus data konsultasi secara permanen | Logged-in |
| `PATCH` | `/consultations/{id}/status` | Mengubah status kategori lead konsultasi dengan cepat | Logged-in |
| `GET` | `/consultations/import/template` | Download berkas template CSV untuk import massal | Logged-in |
| `POST` | `/consultations/import` | Upload file CSV untuk memproses data lead via Laravel Queue | Logged-in |

### 📝 Catatan & Pengingat (Nested)
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `POST` | `/consultations/{id}/notes` | Membuat catatan riwayat (follow-up) baru untuk lead terkait | Logged-in |
| `DELETE` | `/consultations/{id}/notes/{note}` | Menghapus catatan riwayat | Logged-in |
| `POST` | `/consultations/{id}/reminders` | Membuat jadwal pengingat masa depan untuk lead terkait | Logged-in |
| `DELETE` | `/consultations/{id}/reminders/{rem}`| Menghapus jadwal pengingat | Logged-in |

### 🕒 Kehadiran Absensi Admin
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/report-attendances` | Mengambil data rekapan absensi admin pada tanggal tertentu | Logged-in |
| `POST` | `/report-attendances` | Mengirim data absen harian admin (kategori: Ada WA, 0 WA, Libur) | Admin |
| `POST` | `/report-attendances/upsert-by-super-admin` | Mengubah status kehadiran admin tertentu | Super Admin |
| `GET` | `/report-attendances/export` | Download berkas XLS rekap kehadiran bulanan admin | Super Admin |

### 🗃️ Master Data (Super Admin)
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/master-data/needs-categories` | Daftar in-memory kategori kebutuhan lead | Logged-in |
| `GET` | `/master-data/status-categories` | Daftar in-memory kategori status lead | Logged-in |
| `GET` | `/master-data/accounts` | Daftar cabang/akun terdaftar | Logged-in |
| `GET` | `/master-data/categories/list` | Paginasi detail kategori kebutuhan | Super Admin |
| `POST` | `/master-data/categories` | Membuat kategori kebutuhan baru | Super Admin |
| `PUT` | `/master-data/categories/{id}` | Update kategori kebutuhan | Super Admin |
| `DELETE`| `/master-data/categories/{id}` | Hapus kategori kebutuhan | Super Admin |
| `GET` | `/master-data/statuses/list` | Paginasi detail kategori status leads | Super Admin |
| `POST` | `/master-data/statuses` | Membuat kategori status baru | Super Admin |
| `PUT` | `/master-data/statuses/{id}` | Update kategori status | Super Admin |
| `DELETE`| `/master-data/statuses/{id}` | Hapus kategori status | Super Admin |
| `GET` | `/master-data/users` | Paginasi daftar user admin / super admin | Super Admin |
| `POST` | `/master-data/users` | Registrasi admin/super admin baru | Super Admin |
| `PUT` | `/master-data/users/{id}` | Mengubah data user terdaftar | Super Admin |
| `DELETE`| `/master-data/users/{id}` | Menghapus user secara permanen | Super Admin |
| `POST` | `/master-data/users/{id}/reset-password` | Mereset password user menjadi bawaan (`password`) | Super Admin |
| `API` | `/accounts` (Resource) | CRUD Cabang / Cabang Operasional (termasuk upload logo) | Super Admin |

### 📦 Ekspor & Cetak Laporan (Format File)
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/export/csv` | Ekspor data lead terpilih ke berkas CSV standar | Logged-in |
| `GET` | `/export/leads/excel` | Unduh data leads konsultasi terfilter ke berkas Excel | Logged-in |
| `GET` | `/export/leads/pdf` | Unduh data leads konsultasi terfilter ke dokumen PDF | Logged-in |
| `GET` | `/export/analytics/excel` | Ekspor data ringkasan analitik ke file Excel | Logged-in |
| `GET` | `/export/analytics/pdf` | Ekspor data visual analitik ke dokumen PDF | Logged-in |

### 🇮🇩 Data Wilayah Geografis Indonesia
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/wilayah/provinces` | Mengambil seluruh data provinsi di Indonesia | Logged-in |
| `GET` | `/wilayah/cities` | Mengambil data kabupaten/kota berdasarkan provinsi | Logged-in |
| `GET` | `/wilayah/districts` | Mengambil data kecamatan berdasarkan kabupaten/kota | Logged-in |

### 🛠️ Fitur Debug & Diagnostik Pemeliharaan
| Method | Endpoint | Keterangan | Akses |
| :--- | :--- | :--- | :--- |
| `GET` | `/debug/stats` | Statistik debug (latensi DB, jumlah dummy data, leads per bulan) | Logged-in |
| `POST` | `/debug/generate-dummy` | Menghasilkan data dummy lead konsultasi (100, 500, atau 1000 data) | Logged-in |
| `POST` | `/debug/clear-dummy` | Menghapus seluruh data dummy lead (berawalan `[DUMMY]`) dengan aman | Logged-in |
| `POST` | `/debug/clear-logs` | Mengosongkan file log Laravel & tabel `audit_logs` database secara permanen | Super Admin |

---

## ⚡ Optimasi & Pemeliharaan Kinerja

1. **Indeks Database (Compound Indexing)**:
   Aplikasi telah dioptimasi dengan indeks komposit pada kolom-kolom pencarian (seperti `status_category_id`, `account_id`, `created_at`) untuk menjamin kecepatan query database di bawah 1ms meskipun jumlah data mencapai puluhan ribu lead.
2. **Optimasi Cache Query**:
   Data statis wilayah Indonesia dan kategori master dimuat menggunakan Query Cache Trait (`CachesQueries.php`) guna meminimalkan trip IO ke MySQL.
3. **Pembersihan Log Otomatis**:
   Super Admin dapat membersihkan log berkas aplikasi Laravel (`storage/logs/laravel.log`) dan mengosongkan tabel `audit_logs` secara instan melalui dashboard untuk mencegah penumpukan ruang penyimpanan server.

---

## 📄 Lisensi

Aplikasi ini bersifat open-source dan berada di bawah lisensi [MIT License](https://opensource.org/licenses/MIT).

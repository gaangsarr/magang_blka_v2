# LEMBAR PANDUAN TEKNIS & INFRASTRUKTUR (MEETING BSI KAMPUS)
## Sistem Informasi Pendaftaran Magang Mahasiswa (REMATE)
### Institut Teknologi PLN (Kolaborasi BLKA x PT PLN Persero)
**Panduan Lengkap untuk Bahan Diskusi dengan Bagian Sistem Informasi (BSI / PTIK ITPLN)**

---

### 1. RINGKASAN STACK TEKNOLOGI & ARSITEKTUR

Aplikasi **REMATE** dibangun dengan arsitektur web modern yang mandiri, performa tinggi, dan *resource-efficient* tanpa memerlukan runtime Node.js di server produksi.

| Layer / Komponen | Teknologi & Versi | Catatan Teknis untuk BSI |
| :--- | :--- | :--- |
| **Backend Runtime** | **PHP 8.2 / 8.3 FPM** (FastCGI) | Clean Architecture berbasis Service/Helper & PDO dengan PSR-4 Autoloading (`composer.json`). |
| **Web Server** | **Nginx 1.22+** (Direkomendasikan) / Apache 2.4+ | Menangani SSL termination, reverse proxy, FastCGI PHP, dan pengamanan direktori internal. |
| **Database Engine** | **MySQL 8.0+** atau **MariaDB 10.6+** | Wajib engine **InnoDB** (transaksi ACID untuk *concurrency locking* kuota pendaftaran). Charset: `utf8mb4`, Collation: `utf8mb4_unicode_ci`. |
| **Database Migration** | **Phinx 0.16** (`robmorgan/phinx`) | 33 file skrip migrasi terversi (`migrations/`). Pembuatan tabel dan pembaruan skema otomatis via CLI. |
| **Autentikasi Mahasiswa** | **Direct Microsoft Azure Entra ID (SSO)** | OAuth 2.0 Authorization Code Flow langsung ke Tenant ITPLN (`7b388d18-1900-418c-a5d3-e28d7a9a38e6`). **Tanpa Firebase / Pihak Ketiga**. |
| **Frontend UI** | **Vanilla HTML5, CSS3, Modern JS (ES6+)** | File statis di folder `public/`, dilayani instan oleh Nginx tanpa perlu proses `npm run build`. |
| **Document Generator** | **PhpSpreadsheet 3.0** & **PhpWord 1.4** | Ekspor rekapitulasi data mahasiswa (.xlsx) & generator otomatis Surat Pengantar resmi bertanda tangan digital (.docx). |

---

### 2. SKENARIO HOSTING: "NUMPANG" KE SERVER EKSISTING KAMPUS

Karena aplikasi akan ditempatkan pada server kampus yang sudah beroperasi (misalnya server portal `karirku.itpln.ac.id`), berikut perlakuan teknisnya:

#### A. Kompatibilitas Multi-PHP pada Server yang Sama
- Jika server eksisting sudah menggunakan PHP 8.2 / 8.3, aplikasi dapat langsung memakai socket FastCGI yang ada (`/var/run/php/php8.2-fpm.sock`).
- Jika server eksisting masih menjalankan PHP versi lebih lama (misal PHP 7.4 / 8.0), BSI dapat memasang **PHP 8.2-FPM berdampingan (*co-exist*)** via PPA Ubuntu (`ppa:ondrej/php`) tanpa mengganggu aplikasi lama.

#### B. Ekstensi PHP yang Wajib Diaktifkan:
- `php8.2-fpm` (FastCGI Process Manager)
- `php8.2-mysql` / `php8.2-pdo` (Koneksi database PDO)
- `php8.2-curl` (Komunikasi eksternal ke Microsoft Graph API `graph.microsoft.com`)
- `php8.2-mbstring` (Manipulasi karakter multibyte UTF-8)
- `php8.2-zip` (Ekstraksi & pembuatan file arsip .xlsx dan .docx)
- `php8.2-xml` / `php8.2-simplexml` (Parser XML untuk PhpWord dan PhpSpreadsheet)
- `php8.2-gd` atau `php8.2-imagick` (Pemrosesan gambar & barcode/QR)
- `php8.2-bcmath` (Kalkulasi matematika presisi tinggi)
- `php8.2-fileinfo` (Validasi MIME-type keamanan upload berkas PDF pendaftar)

#### C. Parameter `php.ini` yang Perlu Disesuaikan (`/etc/php/8.2/fpm/php.ini`):
```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 512M        # Wajib untuk buffer ekspor ribuan baris Excel
max_execution_time = 120
max_input_time = 120
date.timezone = Asia/Jakarta
```

---

### 3. DUA STRATEGI TOPOLOGI DOMAIN & URL (BAHAN DISKUSI BLKA vs BSI)

Dalam pertemuan, diskusikan 2 opsi berikut:

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│ OPSI 1 (REKOMENDASI DEVELOPER & SYSADMIN): SUBDOMAIN DI SERVER YANG SAMA               │
│ - URL: https://magang.itpln.ac.id  (atau https://magang.karirku.itpln.ac.id)           │
│ - Implementasi: Menambahkan 1 Server Block (Virtual Host) Nginx baru pada mesin sama. │
└────────────────────────────────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────────────────────┐
│ OPSI 2 (USULAN KLIEN BLKA): SUB-PATH DI BAWAH DOMAIN KARIRKU                           │
│ - URL: https://karirku.itpln.ac.id/daftar-magang/  (atau /magang/)                     │
│ - Implementasi: Memanfaatkan location block alias / reverse proxy Nginx pada vhost ada.│
└────────────────────────────────────────────────────────────────────────────────────────┘
```

#### Analisis Perbandingan untuk Rapat:

| Aspek Pertimbangan | Opsi 1: Subdomain Baru (Disarankan) | Opsi 2: Sub-path (`karirku.itpln.ac.id/daftar-magang`) |
| :--- | :--- | :--- |
| **Kesiapan Kode** | **100% Siap Pakai Langsung**. Seluruh routing frontend, cookie, dan 150+ pemanggilan API (`/api/...`) bekerja tanpa ubah kode. | Memerlukan penulisan ulang base URL frontend atau Nginx proxy rewrite yang kompleks agar tidak bentrok dengan `/api/` milik Karirku. |
| **Isolasi Sesi & Cookie** | **Sangat Aman**. Cookie sesi terisolasi penuh, tidak ada risiko *session collision* atau menimpa sesi login Karirku eksisting. | Rentan konflik sesi jika path cookie tidak dispesifikasikan secara ketat. |
| **Keinginan BLKA** | **Dapat Dijembatani**: BLKA cukup meletakkan tombol/banner di menu navigasi `karirku.itpln.ac.id` yang langsung mengarah ke `magang.itpln.ac.id`. | Tampilan URL tampak menyatu di bawah domain induk Karirku. |
| **Sertifikat SSL** | Cukup mengaitkan sertifikat Wildcard ITPLN (`*.itpln.ac.id`) atau Let's Encrypt Certbot baru. | Menggunakan SSL eksisting milik Karirku. |

> **Rekomendasi Argumen untuk Meeting:**  
> *"Kami sangat menyarankan **Opsi 1 (Subdomain)** karena Nginx di server yang sama bisa menangani banyak domain tanpa biaya tambahan, kode sudah 100% tervalidasi stabil, dan mahasiswa tetap merasa berada di ekosistem kampus yang resmi. Di portal Karirku cukup kita pasang banner/tombol 'Pendaftaran Magang PLN' yang mengarah ke subdomain tersebut."*

---

### 4. DATABASE & SKEMA MIGRASI (PHINX)

Karena menggunakan database MySQL eksisting di server kampus:

#### A. Kebutuhan yang Diminta ke BSI:
1. Pembuatan **1 Database Baru**: `blka_magang` (atau `magang_itpln`).
2. Pembuatan **1 User Database**:
   ```sql
   CREATE USER 'app_magang'@'localhost' IDENTIFIED BY 'password_aman';
   GRANT ALL PRIVILEGES ON blka_magang.* TO 'app_magang'@'localhost';
   FLUSH PRIVILEGES;
   ```

#### B. Cara Eksekusi Migrasi Skema:
Aplikasi memiliki 33 file migrasi Phinx (`migrations/`) yang siap dieksekusi:

- **Cara 1 (Standar Phinx Migration)**:
  ```bash
  cd /var/www/magang_blka/v2
  vendor/bin/phinx migrate
  vendor/bin/phinx seed:run
  ```
  *Phinx otomatis membuat seluruh tabel, primary key, foreign key, serta mengindeks tabel secara efisien.*

- **Cara 2 (Fallback SQL Dump Langsung)**:
  Jika BSI lebih memilih mengimpor raw SQL tanpa CLI Phinx:
  ```bash
  mysql -u app_magang -p blka_magang < docs/backup_magang_itpln_v2_latest.sql
  ```

---

### 5. INTEGRASI SSO MICROSOFT AZURE ENTRA ID

Aplikasi telah berhasil diuji dan terintegrasi 100% menggunakan SSO native Microsoft Azure Entra ID (tanpa Firebase).

#### Status Terkini & Tindakan yang Diminta ke BSI:
Aplikasi telah didaftarkan di Azure Portal pada Tenant ITPLN (`7b388d18-1900-418c-a5d3-e28d7a9a38e6`). Namun, karena akun pembuat bukan *Global Administrator*, ada **2 hal yang butuh tindakan staf BSI**:

1. **Memberikan Admin Consent**:
   - BSI login ke **Azure Portal** → **Microsoft Entra ID** → **App registrations** → Buka aplikasi Magang.
   - Buka menu **API permissions**.
   - Klik tombol **"Grant admin consent for Institut Teknologi PLN"** untuk izin `openid`, `profile`, `email`, dan `User.Read`.
   - *(Ini wajib agar mahasiswa ITPLN tidak muncul pop-up izin berulang saat pertama kali login).*
2. **Menambahkan Redirect URI Produksi**:
   - Di menu **Authentication** (Platform: **Web**).
   - Tambahkan URL Callback server kampus:
     - Jika Subdomain: `https://magang.itpln.ac.id/api/auth/azure/callback.php`
     - Jika Sub-path: `https://karirku.itpln.ac.id/daftar-magang/api/auth/azure/callback.php`
3. **Kredensial yang Dimiliki / Diperlukan**:
   - `AZURE_TENANT_ID`: `7b388d18-1900-418c-a5d3-e28d7a9a38e6`
   - `AZURE_CLIENT_ID`: (Sesuai App Registration)
   - `AZURE_CLIENT_SECRET`: (Value Secret yang aktif)

---

### 6. TUGAS TERJADWAL OTOMATIS (CRON JOBS)

Sistem membutuhkan 2 script cron job yang wajib didaftarkan pada crontab server (`crontab -u www-data -e`):

```cron
# 1. Pembersihan reservasi kuota kadaluarsa (Setiap 1 menit)
# Melepaskan kunci reservasi kuota jika mahasiswa tidak menyelesaikan submit dalam 5 menit, sehingga kuota kembali tersedia.
* * * * * /usr/bin/php /var/www/magang_blka/v2/scripts/cleanup_reservasi.php >> /var/www/magang_blka/v2/logs/cron_cleanup.log 2>&1

# 2. Penutupan otomatis periode pendaftaran (Setiap 1 menit)
# Mengubah status periode menjadi nonaktif tepat saat jam batas penutupan WIB server tercapai.
* * * * * /usr/bin/php /var/www/magang_blka/v2/scripts/cron_close_periode.php >> /var/www/magang_blka/v2/logs/cron_close_periode.log 2>&1

# 3. Pencadangan Basis Data Otomatis Harian (Pukul 02:00 WIB dini hari)
0 2 * * * /bin/bash /var/www/magang_blka/v2/scripts/backup_db.sh >> /var/www/magang_blka/v2/logs/cron_backup.log 2>&1
```

---

### 7. STRUKTUR DIREKTORI, STORAGE & KEAMANAN (PERMISSIONS)

- Direktori aplikasi disarankan di: `/var/www/magang_blka/v2`
- User web server: `www-data:www-data`

#### Direktori yang Membutuhkan Hak Tulis (`chmod 775`):
- `storage/transkrip/` (Penyimpanan berkas mahasiswa: CV max 1MB, Portofolio max 5MB, Transkrip max 2MB)
- `public/uploads/` (Template surat / gambar)
- `logs/` (Log aktivitas & cron job)
- `cache/` (Cache aplikasi)

#### Standar Hardening Nginx Wajib:
1. **Proteksi Berkas Dokumen Mahasiswa**:
   Folder `/storage/` **WAJIB diblokir dari akses web langsung (HTTP 403 / Deny all)**. Dokumen hanya bisa diunduh via endpoint terproteksi sesi (`/api/dokumen/download.php` / `/api/admin/...`).
2. **Pemblokiran File Sensitif**:
   Blokir akses web publik ke: `.env`, `.git`, `.gitignore`, `/src/`, `/vendor/`, `/migrations/`, `/seeds/`, `/scripts/`, file ekstensi `*.json`, `*.lock`, `*.sql`, `*.md`, dan file `phinx.php`.

---

### 8. STRATEGI AKSES DEPLOYMENT (PILIHAN UNTUK BSI)

Tawarkan 2 skenario fleksibel kepada tim BSI:

#### Skenario A: Deployment Kolaboratif / Akses SSH Terbatas (Disarankan)
1. BSI membuat direktori `/var/www/magang_blka/v2`.
2. BSI mendaftarkan SSH Deploy Key server ke repositori GitHub privat kita (Read-Only).
3. Tim Pengembang bersama staf BSI melakukan `git clone`, `composer install --no-dev`, konfigurasi `.env`, dan `phinx migrate`.
4. Keuntungan: Sangat cepat, jika ada update / bugfix cukup jalankan `git pull`.

#### Skenario B: Deployment Tertutup Penuh oleh BSI (Blackbox)
1. Tim Pengembang menyerahkan arsip bundle ZIP proyek yang sudah memuat folder `vendor/` (siap pakai tanpa perlu composer di server).
2. Tim Pengembang menyertakan file `.env` yang sudah diisi serta berkas SQL awal.
3. Staf BSI hanya perlu mengekstrak ke web root dan mengimpor database.

---

### 9. CONTOH KONFIGURASI FILE `.env` PRODUCTION

```ini
# --- Database MySQL Server ---
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=blka_magang
DB_USER=app_magang
DB_PASS=Password_Kuat_Database_Disini

# User khusus Phinx Migration CLI
DB_MIGRATION_USER=app_magang
DB_MIGRATION_PASS=Password_Kuat_Database_Disini

# --- Microsoft Azure Entra ID (SSO ITPLN) ---
AZURE_TENANT_ID=7b388d18-1900-418c-a5d3-e28d7a9a38e6
AZURE_CLIENT_ID=client_id_dari_azure_portal
AZURE_CLIENT_SECRET=client_secret_dari_azure_portal
AZURE_REDIRECT_URI=https://magang.itpln.ac.id/api/auth/azure/callback.php

# --- Enkripsi Sesi Server ---
# Generate via: php -r "echo bin2hex(random_bytes(32));"
SESSION_KEY=64_karakter_hex_acak_keamanan_sesi

# --- Lingkungan Aplikasi ---
APP_ENV=production
APP_URL=https://magang.itpln.ac.id
APP_DEBUG=false

# --- Aturan Bisnis Kuota Magang ---
RESERVATION_MINUTES=5
MAX_PEMINATAN=3
```

---

### 10. CHECKLIST SINGKAT YANG HARUS DISEPAKATI DI AKHIR MEETING

- [ ] Konfirmasi versi PHP aktif di server (apakah PHP 8.2+ sudah ada atau perlu install berdampingan).
- [ ] Kesepakatan Domain: **Subdomain** (`magang.itpln.ac.id`) vs **Sub-path** (`karirku.itpln.ac.id/daftar-magang`).
- [ ] BSI menyetujui **Admin Consent** di Azure Portal dan menambahkan Redirect URI.
- [ ] BSI membuatkan 1 Database MySQL kosong (`blka_magang`) + akun user.
- [ ] Pendaftaran Crontab untuk `cleanup_reservasi.php` dan `cron_close_periode.php`.
- [ ] Penentuan jadwal dan metode deployment (Akses SSH / Git Deploy Key / Paket Zip).

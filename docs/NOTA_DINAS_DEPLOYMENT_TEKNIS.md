# INSTITUT TEKNOLOGI PLN
## BAGIAN LAYANAN KARIR DAN ALUMNI (BLKA)
Jl. Lingkar Luar Barat, Duri Kosambi, Cengkareng, Jakarta Barat 11750  
Telp: (021) 5440342 | Email: karir@itpln.ac.id | Website: https://karirku.itpln.ac.id

---

### **NOTA DINAS**
**Nomor:** &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;/BLKA-ITPLN/ND/IX/2026  
**Kepada Yth.** : Kepala Bagian Sistem Informasi (BSI) Institut Teknologi PLN  
**Dari** &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: Kepala Bagian Layanan Karir dan Alumni (BLKA) Institut Teknologi PLN  
**Sifat** &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: Penting / Segera  
**Lampiran** &nbsp;&nbsp;&nbsp;: 4 (Empat) Berkas Teknis  
**Perihal** &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: **Permohonan Penyediaan Infrastruktur Server, Domain, dan Konfigurasi Deployment Sistem Informasi Pendaftaran Magang Mahasiswa (BLKA ITPLN x PT PLN Persero)**

---

### I. LATAR BELAKANG & URGENSI

Sehubungan dengan dimulainya siklus penerimaan Program Magang Mahasiswa Institut Teknologi PLN yang bekerja sama secara strategis dengan PT PLN (Persero) beserta Subholding dan Anak Perusahaan, Bagian Layanan Karir dan Alumni (BLKA) telah menyelesaikan pengembangan **Sistem Informasi Pendaftaran dan Penempatan Magang ITPLN (REMATE - Rekrutmen Magang Terintegrasi)**.

Sistem ini dirancang untuk menangani:
1. **Pendaftaran dan Seleksi Mandiri Mahasiswa ITPLN** menggunakan akun SSO Microsoft 365 resmi (`@itpln.ac.id`).
2. **Mekanisme Kuota & Reservasi Penempatan Realtime (*War Kuota*)** antar unit PLN di seluruh Indonesia dengan sistem penguncian slot berbasis durasi aktif (*locking slot*).
3. **Unggah dan Validasi Dokumen Persyaratan Digital**, meliputi Transkrip Nilai Akademik, Curriculum Vitae (CV), dan Dokumen Portofolio Mahasiswa.
4. **Portal Akses Khusus Mitra Perusahaan / Unit PLN** untuk verifikasi berkas, pemeringkatan pendaftar, dan penentuan kelulusan peserta.
5. **Dashboard Pengelolaan & Rekapitulasi BLKA**, termasuk generator otomatis dokumen Surat Pengantar Resmi (.docx/.pdf) dan rekapitulasi data pendaftar (.xlsx).

Mengingat sistem ini akan diakses secara serentak oleh ribuan mahasiswa aktif ITPLN dalam jendela waktu pendaftaran yang singkat, serta memuat data identitas mahasiswa dan rekapitulasi unit kerja PLN yang bersifat konfidensial, maka sistem **harus di-deploy secara mandiri di infrastruktur server on-premise / cloud kampus ITPLN** di bawah kendali Bagian Sistem Informasi (BSI).

Tim Pengembang BLKA telah mengundang staf teknis Bagian Sistem Informasi kampus sebagai kolaborator pada repositori GitHub resmi proyek ini. Bersama nota dinas ini, kami sampaikan rincian spesifikasi teknis dan kebutuhan konfigurasi yang dimohonkan kepada tim BSI.

---

### II. SPESIFIKASI KEBUTUHAN PERANGKAT KERAS (HARDWARE SIZING)

Untuk menjamin ketersediaan (*high availability*) dan performa responsif saat terjadi lonjakan beban trafik (*peak traffic*) pada periode pembukaan pendaftaran, berikut rekomendasi alokasi sumber daya komputasi server:

| Parameter Komputasi | Spesifikasi Rekomendasi (Disarankan) | Spesifikasi Minimum | Keterangan Teknis |
| :--- | :--- | :--- | :--- |
| **Tipe Lingkungan** | **VPS / VM Linux Mandiri** (Dedicated Instance) | VM Linux Mandiri | Memberikan fleksibilitas konfigurasi cron, daemon, PHP-FPM pool, dan proteksi direktori web server. |
| **Sistem Operasi** | **Ubuntu Server 22.04 LTS / 24.04 LTS** (64-bit) | Ubuntu Server 20.04 LTS / Debian 11 | Kompatibilitas penuh dengan PHP 8.2 FPM dan ekstensi Office/Spreadsheet. |
| **Processor (vCPU)** | **4 vCPU** (2.4 GHz+) | 2 vCPU | Pemrosesan konkuren reservasi kuota database dan kompilasi template surat Word/PDF. |
| **Memori (RAM)** | **8 GB RAM** | 4 GB RAM | Buffer PhpSpreadsheet ekspor data ribuan mahasiswa & PHP-FPM process worker. |
| **Penyimpanan (Storage)** | **60 GB – 80 GB SSD / NVMe** | 40 GB SSD | Alokasi OS, runtime, log, serta direktori penyimpanan berkas dokumen mahasiswa. |
| **Port Jaringan Terbuka** | Port **80** (HTTP), Port **443** (HTTPS), Port **22** (SSH Terbatas) | Port 80, 443, 22 | Akses port web publik & akses SSH administratif bagi sysadmin/developer. |

#### Analisis Kebutuhan Penyimpanan Berkas (*Storage Sizing*):
Sistem memfasilitasi unggah berkas mahasiswa dengan ketentuan batasan ukuran:
- **Curriculum Vitae (CV)** : Maksimal **1 MB** per berkas (Format: PDF).
- **Portofolio Mahasiswa** : Maksimal **5 MB** per berkas (Format: PDF).
- **Transkrip Nilai Akademik** : Maksimal **2 MB** per berkas (Format: PDF).
- *Estimasi Beban*: Untuk 1.500 pendaftar aktif per periode, total ruang dokumen berkisar **~8 GB hingga 12 GB per periode**. Sisa ruang disk dialokasikan untuk sistem operasi, database MySQL, log aktivitas, serta retensi berkas pencadangan harian (*daily automated backup*).

---

### III. KEBUTUHAN PERANGKAT LUNAK & RUNTIME (SOFTWARE STACK)

Berikut adalah tumpukan perangkat lunak (*software stack*) yang diperlukan di dalam server:

```
┌─────────────────────────────────────────────────────────────┐
│                    Nginx Web Server 1.22+                   │
│   (Reverse Proxy, SSL Termination, Security Rule Blocking)  │
└──────────────────────────────┬──────────────────────────────┘
                               │ (FastCGI Socket)
┌──────────────────────────────▼──────────────────────────────┐
│                       PHP 8.2 / 8.3 FPM                     │
│  (PhpSpreadsheet, PhpWord, Azure Entra ID, PDO MySQL Core)  │
└──────────────────────────────┬──────────────────────────────┘
                               │ (TCP/Socket Port 3306)
┌──────────────────────────────▼──────────────────────────────┐
│                    MySQL 8.0+ / MariaDB 10.6+               │
│         (Engine InnoDB, Charset utf8mb4_unicode_ci)         │
└─────────────────────────────────────────────────────────────┘
```

#### 1. Web Server
- **Nginx** versi 1.22 atau lebih baru (*Direkomendasikan*), atau Apache 2.4+ dengan modul `mod_rewrite` dan `mod_headers` aktif.
- Mengaktifkan modul SSL / TLS (OpenSSL 1.1.1+).

#### 2. Runtime PHP & Ekstensi Wajib
Aplikasi dibangun menggunakan **PHP versi >= 8.2** (mendukung hingga PHP 8.3). Ekstensi PHP berikut wajib terpasang dan aktif:
- `php8.2-fpm` (FastCGI Process Manager)
- `php8.2-mysql` / `php8.2-pdo` (Koneksi Database PDO MySQL)
- `php8.2-gd` / `php8.2-imagick` (Manipulasi grafis & barcode generator)
- `php8.2-zip` (Ekstraksi & kompilasi file dokumen xlsx/docx)
- `php8.2-xml` / `php8.2-simplexml` (Parser XML untuk PhpWord dan PhpSpreadsheet)
- `php8.2-mbstring` (Pemrosesan karakter multibyte UTF-8)
- `php8.2-curl` (Komunikasi eksternal ke Microsoft Graph API `/v1.0/me`)
- `php8.2-bcmath` (Kalkulasi presisi tinggi token JWT)
- `php8.2-fileinfo` (Validasi MIME-type keamanan upload berkas)

#### 3. Parameter Krusial `php.ini` (`/etc/php/8.2/fpm/php.ini`):
```ini
upload_max_filesize = 10M
post_max_size = 12M
memory_limit = 512M
max_execution_time = 120
max_input_time = 120
date.timezone = Asia/Jakarta
```

#### 4. Basis Data (Database)
- **MySQL 8.0+** atau **MariaDB 10.6+**.
- Konfigurasi Default: `default_character_set = utf8mb4`, `collation = utf8mb4_unicode_ci`.
- **Ketentuan Akun DB**:
  - Dibuatkan 1 (satu) database kosong dengan nama: `blka_magang` (atau `magang_itpln`).
  - Dibuatkan 1 (satu) user database dengan hak akses `ALL PRIVILEGES` pada database tersebut (contoh user: `app_magang@localhost`).
  - Skema tabel dan data awal (*master data entitas PLN, jurusan, admin*) akan diimpor langsung oleh tim pengembang menggunakan berkas SQL dump `docs/backup_magang_itpln_v2_latest.sql` yang telah tersedia di repositori.

#### 5. Utilitas Tambahan
- **Composer 2.x** (PHP Dependency Manager)
- **Git** (Version Control System)

---

### IV. TOPOLOGI DOMAIN, ROUTING URL, DAN SERTIFIKAT SSL

Mengingat BLKA telah mengoperasikan portal **`https://karirku.itpln.ac.id`**, pengintegrasian sistem magang ini ke dalam ekosistem domain kampus dapat dilakukan melalui salah satu dari 2 (dua) opsi teknis berikut:

```
OPSI A (Rekomendasi Utama: Subdomain Mandiri Terisolasi)
karirku.itpln.ac.id ──[Link Menu "Magang PLN"]──► magang.itpln.ac.id (atau magang.karirku.itpln.ac.id)
                                                  └── Root / & API /api/... Utuh & Terisolasi

OPSI B (Sub-path Reverse Proxy)
karirku.itpln.ac.id/daftarmagang/ ──[Nginx Reverse Proxy Rewrite]──► Server Magang (Port 80/Local)
```

#### Perbandingan Opsi Teknis:

| Aspek Penilaian | Opsi A: Subdomain Khusus (`magang.itpln.ac.id` / `magang.karirku.itpln.ac.id`) | Opsi B: Sub-path (`karirku.itpln.ac.id/daftarmagang`) |
| :--- | :--- | :--- |
| **Status Rekomendasi** | **Sangat Direkomendasikan** | Alternatif |
| **Integritas Kode Frontend** | **100% Siap Pakai Langsung**. Seluruh routing frontend (`/api/...`, `/daftar.html`, `/status.html`) berjalan di root tanpa modifikasi script JavaScript. | Memerlukan penulisan ulang (*URL rewrite*) kompleks pada Nginx proxy agar request `/daftarmagang/api/...` diteruskan ke `/api/...`. |
| **Isolasi Cookie & Sesi** | **Sangat Aman & Terpisah**. Cookie autentikasi sesi sistem magang tidak akan bertabrakan atau menimpa sesi website Karirku eksisting. | Berisiko terjadi bentrok path cookie session jika tidak dibatasi path domain secara presisi. |
| **Kemandirian Beban Server** | Lonjakan trafik pendaftaran magang tidak membebani server utama Karirku. | Beban trafik pendaftaran ikut melintasi gateway reverse proxy server utama Karirku. |
| **Implementasi di Karirku** | Cukup menambahkan 1 tautan/tombol menu navigasi di web Karirku yang mengarah ke subdomain tersebut. | Memerlukan konfigurasi virtual host Nginx reverse proxy di server Karirku eksisting. |

> **Catatan:**  
> BLKA sangat mengusulkan penerapan **Opsi A** (misalnya `magang.itpln.ac.id` atau `magang.karirku.itpln.ac.id`) demi stabilitas sistem dan efisiensi waktu deployment.  
> Untuk sertifikat SSL/HTTPS, dimohonkan menggunakan sertifikat Wildcard resmi kampus (`*.itpln.ac.id`) atau penerbitan sertifikat otomatis via **Let's Encrypt Certbot**.

---

### V. MEKANISME DEPLOYMENT DARI REPOSITORI GITHUB

Staf teknis BSI telah diundang sebagai kolaborator pada repositori GitHub privat sistem ini. Untuk proses penarikan (*pull*) kode ke server kampus secara aman dan terstandarisasi, disepakati mekanisme berikut:

1. **Pemanfaatan Git Deploy Key (SSH Read-Only)**:
   - Tim BSI men-generate pasangan kunci SSH di server kampus:
     ```bash
     ssh-keygen -t ed25519 -C "deploy-magang-server@itpln.ac.id" -f ~/.ssh/magang_deploy_key
     ```
   - Kunci publik (`magang_deploy_key.pub`) didaftarkan ke menu **Settings → Deploy Keys** pada repositori GitHub proyek dengan akses *Read-Only*.
   - Server dapat melakukan `git clone` dan `git pull` sewaktu-waktu tanpa memerlukan password ataupun token akun personal staf.

2. **Prosedur Standar Deployment Perdana**:
   ```bash
   # 1. Clone repositori ke direktori web server
   cd /var/www
   git clone git@github.com:<repo-owner>/<repo-name>.git magang_blka

   # 2. Masuk ke direktori kerja v2
   cd /var/www/magang_blka/v2

   # 3. Salin dan sesuaikan konfigurasi environment (.env)
   cp .env.example .env
   nano .env # (Isi kredensial DB, Azure Entra ID, dan Session Key)

   # 4. Pasang pustaka PHP via Composer (Mode Production)
   composer install --no-dev --optimize-autoloader

   # 5. Konfigurasi hak akses direktori penyimpanan dokumen dan log
   mkdir -p storage/transkrip logs cache
   chown -R www-data:www-data storage logs cache
   chmod -R 775 storage logs cache
   ```

---

### VI. TUGAS TERJADWAL OTOMATIS (CRON JOB)

Aplikasi memiliki mekanisme pelepasan kuota reservasi kedaluwarsa (*expired locking*) secara otomatis agar kuota yang tidak diselesaikan pendaftarannya kembali tersedia untuk mahasiswa lain.

Dimohonkan bantuan tim BSI untuk menambahkan entri crontab pada user sistem server (misal `www-data` atau `root`):

```bash
# Buka crontab user www-data
crontab -u www-data -e
```

Tambahkan baris tugas berikut:

```cron
# 1. Pembersihan slot reservasi kuota kedaluwarsa (Berjalan setiap 1 menit)
* * * * * php /var/www/magang_blka/v2/scripts/cleanup_reservasi.php >> /var/www/magang_blka/v2/logs/cron_cleanup.log 2>&1

# 2. Pencadangan basis data otomatis harian (Berjalan setiap pukul 02:00 WIB dini hari)
0 2 * * * /var/www/magang_blka/v2/scripts/backup_db.sh >> /var/www/magang_blka/v2/logs/cron_backup.log 2>&1
```

---

### VII. KEAMANAN & HARDENING WEB SERVER

Untuk memenuhi standar keamanan data perguruan tinggi dan kepatuhan sistem informasi, virtual host web server (Nginx) wajib menerapkan isolasi direktori:
1. **Penutupan Akses Publik Direktori Internal**:
   Direktori berikut **TIDAK BOLEH** dapat diakses melalui browser:
   - `/src/`, `/vendor/`, `/migrations/`, `/seeds/`, `/scripts/`, `/scratch/`, `/storage/`, `/logs/`, `/cache/`
2. **Pemblokiran Berkas Konfigurasi & Ekstensi Sensitif**:
   - Berkas berekstensi: `.env`, `.git`, `.gitignore`, `.json`, `.lock`, `.sql`, `.md`
   - Berkas skrip root: `phinx.php`, `seed.php`, `test_*.php`
3. **Penerapan Security Headers**:
   - `X-Frame-Options: SAMEORIGIN` (Mencegah serangan Clickjacking)
   - `X-Content-Type-Options: nosniff` (Mencegah MIME-sniffing)
   - `X-XSS-Protection: 1; mode=block`
   - `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (HSTS aktif pada HTTPS)
   - `Referrer-Policy: strict-origin-when-cross-origin`

*(Konfigurasi siap pakai terlampir pada Lampiran II)*.

---

### VIII. MATRIKS DUKUNGAN YANG DIMOHONKAN KEPADA BSI

Sebagai ringkasan tindak lanjut teknis, berikut pembagian peran kerja yang dimohonkan:

| No | Komponen Kerja Sama | Penanggung Jawab | Keterangan / Output yang Diharapkan |
| :---: | :--- | :---: | :--- |
| 1 | Penyediaan 1 Unit VM Server (Ubuntu 22.04 LTS, 4 vCPU, 8 GB RAM, 60-80 GB SSD) | **BSI ITPLN** | IP Server & Kredensial SSH. |
| 2 | Instalasi Nginx, PHP 8.2 FPM, ekstensi wajib, dan MySQL 8.0 | **BSI ITPLN** | Lingkungan runtime server siap pakai. |
| 3 | Penyediaan 1 Database MySQL (`blka_magang`) dan 1 Akun Pengguna | **BSI ITPLN** | Nama DB, Username, dan Password DB. |
| 4 | Alokasi Subdomain (`magang.itpln.ac.id` / `magang.karirku.itpln.ac.id`) + SSL | **BSI ITPLN** | Domain aktif mengarah ke IP Server. |
| 5 | Pendaftaran App Registration Azure AD (SSO Microsoft 365 ITPLN) & Admin Consent | **BSI ITPLN** | Client ID, Client Secret, dan Tenant ID (Panduan pada Lampiran I). |
| 6 | Pendaftaran SSH Deploy Key di Repositori GitHub & `git clone` | **BSI & Pengembang** | Kode sumber terpasang di `/var/www/magang_blka/v2`. |
| 7 | Import Data Awal Basis Data & Konfigurasi `.env` Production | **Pengembang BLKA** | Menggunakan `docs/backup_magang_itpln_v2_latest.sql`. |
| 8 | Pemasangan Cron Job (Pembersihan Kuota & Backup Harian) | **BSI & Pengembang** | Entri crontab aktif di server. |
| 9 | Pengujian Fungsional Sistem & User Acceptance Testing (UAT) | **BLKA & Pengembang** | Verifikasi login SSO, reservasi kuota, dan upload dokumen. |

---

### IX. PENUTUP

Demikian Nota Dinas permohonan penyediaan infrastruktur dan deployment teknis ini kami sampaikan. Dukungan dan sinergi dari Bagian Sistem Informasi (BSI) sangat kami harapkan demi kelancaran dan kesuksesan implementasi Program Magang Mahasiswa ITPLN x PT PLN (Persero).

Atas perhatian, kerja sama, dan bantuan Bapak/Ibu, kami ucapkan terima kasih.

<br><br>

Jakarta, 16 September 2026  
**Kepala Bagian Layanan Karir dan Alumni (BLKA)**  
Institut Teknologi PLN,

<br><br><br><br>

**( ____________________________________ )**  
NIP. ......................................................

---
<br>

**Tembusan Yth.:**
1. Wakil Rektor III Bidang Kemahasiswaan dan Fasilitas ITPLN
2. Arsip Bagian Layanan Karir dan Alumni (BLKA)

<div style="page-break-after: always;"></div>

---

# LAMPIRAN TEKNIS

---

### LAMPIRAN I: PANDUAN INTEGRASI DIRECT SSO MICROSOFT 365 ITPLN (AZURE ENTRA ID)

Autentikasi mahasiswa menggunakan Single Sign-On (SSO) akun resmi kampus `@itpln.ac.id` secara langsung via OAuth 2.0 Authorization Code Flow ke Microsoft Entra ID (tanpa pihak ketiga / tanpa Firebase).

Langkah-langkah yang dimohonkan untuk dieksekusi oleh Administrator Azure AD BSI ITPLN:

#### 1. Pendaftaran Aplikasi di Azure Portal
1. Masuk ke **[Azure Portal](https://portal.azure.com/)** menggunakan akun Global Admin / Cloud Application Admin ITPLN.
2. Buka menu **Microsoft Entra ID** (Azure Active Directory) → pilih **App registrations** → klik **+ New registration**.
3. Isi formulir registrasi:
   - **Name**: `Portal Magang ITPLN x PLN`
   - **Supported account types**: Pilih **Accounts in this organizational directory only (Institut Teknologi PLN only - Single tenant)**.
   - **Redirect URI**: Pilih platform **Web**, isi URL callback aplikasi:
     ```
     https://<domain-magang>/api/auth/azure/callback.php
     ```
     *(Contoh: `https://magang.itpln.ac.id/api/auth/azure/callback.php`)*
4. Klik **Register**.

#### 2. Pencatatan Kredensial Aplikasi
Pada halaman **Overview** aplikasi yang baru dibuat, catat:
- **Application (client) ID** → Kunci `AZURE_CLIENT_ID`
- **Directory (tenant) ID** → Kunci `AZURE_TENANT_ID` (Default ITPLN: `7b388d18-1900-418c-a5d3-e28d7a9a38e6`)

#### 3. Pembuatan Client Secret
1. Pada bilah navigasi kiri, pilih **Certificates & secrets** → tab **Client secrets** → klik **+ New client secret**.
2. Masukkan deskripsi (contoh: `Kredensial Produksi Portal Magang`) dan pilih masa berlaku (disarankan: **24 months**).
3. Klik **Add** dan segera salin nilai pada kolom **Value** (kunci `AZURE_CLIENT_SECRET`).

#### 4. Izin Akses Microsoft Graph (API Permissions)
1. Pilih menu **API permissions** → klik **+ Add a permission** → pilih **Microsoft Graph** → **Delegated permissions**.
2. Cari dan centang izin berikut:
   - `openid`
   - `profile`
   - `email`
   - `User.Read`
3. Klik **Add permissions**.
4. Klik tombol **"Grant admin consent for Institut Teknologi PLN"** dan konfirmasi.  
   *(Langkah ini sangat krusial agar mahasiswa tidak menerima prompt persetujuan izin berulang saat login pertama kali)*.

#### 5. Konfigurasi Token Claims (Nama Lengkap Mahasiswa)
1. Pilih menu **Token configuration** → klik **+ Add optional claim**.
2. Pilih token type **ID** → centang: `email`, `family_name`, `given_name`, dan `name`.
3. Klik **Add** (setujui jika diminta mengaktifkan profil claim).

---

### LAMPIRAN II: TEMPLATE KONFIGURASI NGINX SERVER BLOCK

Berkas konfigurasi ini dapat disimpan di `/etc/nginx/sites-available/magang-blka.conf` dan diaktifkan melalui tautan simbolik ke `/etc/nginx/sites-enabled/`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name magang.itpln.ac.id; # Atau magang.karirku.itpln.ac.id

    # Alihkan seluruh trafik HTTP ke HTTPS
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name magang.itpln.ac.id; # Atau magang.karirku.itpln.ac.id

    # Path root aplikasi
    root /var/www/magang_blka/v2;
    index index.html index.php;
    charset utf-8;

    # Sertifikat SSL
    ssl_certificate /etc/ssl/certs/itpln_wildcard.crt; # Sesuaikan path sertifikat kampus
    ssl_certificate_key /etc/ssl/private/itpln_wildcard.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Batasan Ukuran Upload (Mengakomodasi Portofolio PDF 5 MB & CV 1 MB)
    client_max_body_size 12M;

    # 1. Routing Frontend Statis
    location / {
        root /var/www/magang_blka/v2/public;
        try_files $uri $uri/ /index.html;
    }

    # 2. Routing API Backend PHP
    location /api/ {
        try_files $uri $uri/ =404;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_param HTTP_PROXY "";
            fastcgi_buffer_size 128k;
            fastcgi_buffers 4 256k;
            fastcgi_busy_buffers_size 256k;
            fastcgi_read_timeout 120s;
        }
    }

    # 3. Pengamanan Berkas Dokumen Mahasiswa (Transkrip, CV, Portofolio)
    # Berkas di /storage/ hanya boleh diakses melalui endpoint API terotentikasi
    location /storage/ {
        deny all;
        return 403;
    }

    # 4. Keamanan: Blokir Berkas Sensitif & Direktori Internal
    location ~ /\.(env|git|gitignore) {
        deny all;
        return 404;
    }

    location ~ /(migrations|seeds|scripts|scratch|src|vendor|cache|logs) {
        deny all;
        return 404;
    }

    location ~ \.(json|lock|sql|md)$ {
        deny all;
        return 404;
    }

    location ~ ^/(phinx|seed|test_.*)\.php$ {
        deny all;
        return 404;
    }

    # 5. Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://*.firebaseapp.com https://apis.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://unpkg.com https://cdn.jsdelivr.net https://*.googleapis.com https://*.firebaseio.com https://nominatim.openstreetmap.org https://photon.komoot.io https://wilayah.web.id; frame-src 'self' https://*.firebaseapp.com; worker-src 'self' blob:;" always;

    # 6. Kompresi Gzip
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml application/json;
    gzip_disable "MSIE [1-6]\.";
}
```

---

### LAMPIRAN III: TEMPLATE FILE KONFIGURASI ENVIRONMENT (`.env`)

Berkas `.env` disimpan pada root direktori `/var/www/magang_blka/v2/.env` (pastikan permission `chmod 600` agar hanya dapat dibaca oleh user web server):

```dotenv
# ========================================================
# KONFIGURASI SISTEM INFORMASI MAGANG BLKA ITPLN PRODUCTION
# ========================================================

# --- Database MySQL Server ---
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=blka_magang
DB_USER=app_magang
DB_PASS=Password_Kuat_Database_Disini

# --- Autentikasi Microsoft Azure Entra ID (SSO ITPLN) ---
AZURE_TENANT_ID=7b388d18-1900-418c-a5d3-e28d7a9a38e6
AZURE_CLIENT_ID=client_id_resmi_dari_bsi
AZURE_CLIENT_SECRET=client_secret_resmi_dari_bsi
AZURE_REDIRECT_URI=https://magang.itpln.ac.id/api/auth/azure/callback.php

# User khusus Phinx Migration CLI
DB_MIGRATION_USER=app_migration
DB_MIGRATION_PASS=Password_Kuat_Migrasi_Disini

# --- Kunci Sesi Server ---
# Dibuat dengan perintah: php -r "echo bin2hex(random_bytes(32));"
SESSION_KEY=a7f29c48b105d3e691823746c10928374a5b6c7d8e9f0123456789abcdef0123

# --- Lingkungan Aplikasi ---
APP_ENV=production
APP_URL=https://magang.itpln.ac.id
APP_DEBUG=false

# --- Aturan Bisnis & Kuota Magang ---
# Durasi penguncian slot reservasi unit (dalam satuan menit)
RESERVATION_MINUTES=5

# Batas maksimum pemilihan entitas/unit peminatan per mahasiswa
MAX_PEMINATAN=3
```

---

### LAMPIRAN IV: PETUNJUK INISIALISASI BASIS DATA & IMPORT DATA AWAL

Setelah database `blka_magang` dan user `app_magang` dibuat oleh administrator BSI, inisialisasi tabel, relasi referensial (*foreign keys*), dan data master awal (*master wilayah, entitas PLN, jurusan, admin*) dapat langsung dieksekusi dengan perintah:

```bash
# Masuk ke direktori proyek
cd /var/www/magang_blka/v2

# Import berkas SQL dump produksi terbaru ke dalam database
mysql -u app_magang -p blka_magang < docs/backup_magang_itpln_v2_latest.sql
```

Apabila di kemudian hari terdapat penambahan migrasi skema database baru dari tim pengembang, BSI cukup menjalankan perintah migrasi Phinx:

```bash
vendor/bin/phinx migrate
```

# ⚡ INTERN ITPLN — Rekrutmen Magang Terintegrasi
### Sistem Informasi Pendaftaran dan Penempatan Magang Mahasiswa
**Institut Teknologi PLN (BLKA) × PT PLN (Persero)**

---

[![PHP Version](https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/MySQL-8.0%2B%20%7C%20MariaDB%2010.6%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Web Server](https://img.shields.io/badge/Nginx-1.22%2B-009639?style=for-the-badge&logo=nginx&logoColor=white)](https://nginx.org/)
[![Auth](https://img.shields.io/badge/SSO-Microsoft%20Azure%20Entra%20ID-0078D4?style=for-the-badge&logo=microsoftazure&logoColor=white)](https://portal.azure.com/)
[![Architecture](https://img.shields.io/badge/Architecture-Decoupled%20API--Driven-orange?style=for-the-badge)](#arsitektur-sistem)
[![License](https://img.shields.io/badge/License-Proprietary%20ITPLN-red?style=for-the-badge)](#lisensi--hak-cipta)

---

## 📑 DAFTAR ISI

1. [Tentang Sistem](#-tentang-sistem)
2. [Arsitektur Sistem & Tech Stack](#-arsitektur-sistem--tech-stack)
3. [Matriks Peran & Hak Akses (RBAC)](#-matriks-peran--hak-akses-rbac)
4. [Fitur Lengkap Berdasarkan Peran](#-fitur-lengkap-berdasarkan-peran)
   - [Super Admin](#1-super-admin)
   - [Admin BLKA (Layanan Karir & Alumni)](#2-admin-blka)
   - [Admin Perusahaan / Unit PLN](#3-admin-perusahaan--mitra-unit-pln)
   - [Mahasiswa ITPLN](#4-mahasiswa-itpln)
5. [Alur Bisnis Utama (Core Business Logic)](#-alur-bisnis-utama-core-business-logic)
   - [Alur 1: Autentikasi SSO Microsoft Azure Entra ID](#alur-1-autentikasi-sso-microsoft-azure-entra-id)
   - [Alur 2: War Kuota & Concurrency Locking Reservasi](#alur-2-war-kuota--concurrency-locking-reservasi)
   - [Alur 3: Multi-Peminatan & Upload Berkas Digital](#alur-3-multi-peminatan--upload-berkas-digital)
   - [Alur 4: Seleksi, Penilaian, & Penetapan Peserta](#alur-4-seleksi-penilaian--penetapan-peserta)
   - [Alur 5: Mekanisme Pemindahan Peserta (Inter-Unit Transfer)](#alur-5-mekanisme-pemindahan-peserta-inter-unit-transfer)
   - [Alur 6: Mesin Generator Surat Pengantar Resmi (.docx/.pdf)](#alur-6-mesin-generator-surat-pengantar-resmi-docxpdf)
6. [Struktur Direktori Proyek](#-struktur-direktori-proyek)
7. [Panduan Instalasi & Setup Lokal](#-panduan-instalasi--setup-lokal)
8. [Database & Skema Migrasi (Phinx)](#-database--skema-migrasi-phinx)
9. [Tugas Terjadwal (Background Cron Jobs)](#-tugas-terjadwal-background-cron-jobs)
10. [Konfigurasi Environment (.env)](#-konfigurasi-environment-env)
11. [Panduan Deployment Produksi & Security Hardening](#-panduan-deployment-produksi--security-hardening)
12. [Lisensi & Kepemilikan](#-lisensi--kepemilikan)

---

## 🎯 TENTANG SISTEM

**INTERN ITPLN** adalah platform enterprise yang dikembangkan khusus untuk mengelola siklus pendaftaran, seleksi, penempatan, dan administrasi magang mahasiswa **Institut Teknologi PLN (ITPLN)** di lingkungan **PT PLN (Persero)** beserta seluruh Holding, Subholding, Unit Induk, Unit Pelaksana, dan Anak Perusahaan di seluruh Indonesia.

### Permasalahan yang Diselesaikan:
- **Menggantikan Proses Manual**: Menghilangkan penggunaan Google Form / spreadsheet terpisah yang rentan duplikasi data, kebocoran kuota, dan sulit diverifikasi.
- **Keadilan Alokasi Kuota (*War Kuota*)**: Menerapkan sistem penguncian slot berbasis waktu (*locking slot timeout*) dengan proteksi *race condition* berbasis transaksi database ACID.
- **Validasi Terpusat**: Mahasiswa login menggunakan Single Sign-On (SSO) akun resmi kampus (`@itpln.ac.id`), memastikan hanya mahasiswa aktif yang berhak mendaftar.
- **Otomatisasi Dokumen**: Surat Pengantar Resmi Kampus berformat Word (.docx) bertanda tangan digital dan ber-QR Code dicetak otomatis secara instan.

---

## 🏗 ARSITEKTUR SISTEM & TECH STACK

Aplikasi menggunakan arsitektur **Decoupled API-Driven**, di mana antarmuka pengguna (*Frontend*) terpisah bersih dari logika bisnis (*Backend API*).

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           CLIENT / BROWSER                              │
│   (Mahasiswa ITPLN, Admin BLKA, Super Admin, HRD Unit Perusahaan PLN)   │
└────────────────────────────────────┬────────────────────────────────────┘
                                     │ HTTPS / RESTful API / JSON
┌────────────────────────────────────▼────────────────────────────────────┐
│                         NGINX WEB SERVER 1.22+                          │
│  - SSL/TLS Termination & HTTP/2                                         │
│  - Reverse Proxy & FastCGI Socket Routing                               │
│  - Security Hardening: Blokir Akses ke /src, /vendor, .env, /storage    │
└──────────────────┬───────────────────────────────────────┬──────────────┘
                   │ Static Files                          │ FastCGI
┌──────────────────▼───────────────┐     ┌─────────────────▼──────────────┐
│       FRONTEND STATIS            │     │       BACKEND RUNTIME          │
│  - Vanilla HTML5 + Modern CSS    │     │  - PHP 8.2 / 8.3 FPM           │
│  - Modern JavaScript (ES6+ Fetch)│     │  - PSR-4 Clean Architecture    │
│  - Responsive & Mobile Friendly  │     │  - Session & RBAC Auth         │
│  - No Node.js runtime needed     │     │  - PhpSpreadsheet & PhpWord    │
└──────────────────────────────────┘     └─────────────────┬──────────────┘
                                                           │ PDO MySQL
┌──────────────────────────────────────────────────────────▼──────────────┐
│                    DATABASE MYSQL 8.0+ / MARIADB 10.6+                  │
│  - Storage Engine: InnoDB (Transaksi ACID, Row-Level Locking)           │
│  - Charset: utf8mb4_unicode_ci                                          │
│  - Migrasi Skema: Phinx 0.16 (33 Skrip Migrasi Terversi)                │
└─────────────────────────────────────────────────────────────────────────┘
```

### Rincian Tumpukan Teknologi:

| Komponen | Pilihan Teknologi | Alasan Pemilihan & Keunggulan |
| :--- | :--- | :--- |
| **Backend Runtime** | **PHP 8.2 / 8.3 FPM** | Kecepatan eksekusi FastCGI, pengetikan ketat (*strict types*), dan performa memori tinggi. |
| **Pola Desain** | **Service / Helper & Clean MVC** | Struktur `App\` berbasis namespace PSR-4, kode terorganisir tanpa dependensi framework yang membengkak (*zero bloated dependencies*). |
| **Database** | **MySQL 8.0+ / MariaDB 10.6+** | Dukungan transaksi baris (`FOR UPDATE`), indexing komprehensif, dan integritas *foreign key*. |
| **Migration Tool** | **Phinx 0.16** (`robmorgan/phinx`) | Skema database terkelola penuh via *code-as-configuration*, mendukung migrasi dan *rollback* otomatis. |
| **Autentikasi Mahasiswa** | **Microsoft Azure Entra ID** | SSO native Microsoft 365 (`@itpln.ac.id`) via OAuth 2.0 Authorization Code Flow. Tanpa pihak ketiga / tanpa Firebase. |
| **Autentikasi Admin** | **Native Session & Password Hashing** | Bcrypt / Argon2id dengan proteksi CSRF token ganda, *session fixation defense*, dan *rate limiting*. |
| **Frontend UI** | **Vanilla HTML5, CSS3, ES6+ JS** | Ringan, cepat, waktu muat instan (*zero compile/build step*), dan mudah dirawat jangka panjang. |
| **Document Engine** | **PhpOffice/PhpSpreadsheet & PhpWord** | Pembuatan berkas ekspor data Excel (.xlsx) dan kompilasi template dokumen Surat Pengantar (.docx). |

---

## 👥 MATRIKS PERAN & HAK AKSES (RBAC)

Sistem menerapkan **Role-Based Access Control (RBAC)** ketat yang memisahkan otoritas setiap entitas pengguna:

| Modul / Fitur Sistem | Mahasiswa | Admin Perusahaan | Admin BLKA | Super Admin |
| :--- | :---: | :---: | :---: | :---: |
| **Login SSO Akun Kampus (`@itpln.ac.id`)** | ✅ | ❌ | ❌ | ❌ |
| **Login Kredensial Email/Password Khusus** | ❌ | ✅ | ✅ | ✅ |
| **Pendaftaran & War Kuota Peminatan** | ✅ | ❌ | ❌ | ❌ |
| **Upload CV, Portofolio, & Transkrip Nilai** | ✅ | ❌ | ❌ | ❌ |
| **Tracking Status Seleksi & Riwayat Pendaftaran** | ✅ | ❌ | ❌ | ❌ |
| **Unduh Berkas Bukti Pendaftaran Mandiri** | ✅ | ❌ | ❌ | ❌ |
| **Kelola Kuota Unit Pelaksana (Milik Sendiri)** | ❌ | ✅ | ✅ | ✅ |
| **Seleksi & Pemeringkatan Pelamar Unit Sendiri** | ❌ | ✅ | ✅ | ✅ |
| **Ubah Status Peserta (Diterima / Ditolak / Cadangan)** | ❌ | ✅ | ✅ | ✅ |
| **Ajukan Pemindahan Peserta (Approval BLKA)** | ❌ | ✅ | ✅ | ✅ |
| **Force Pemindahan Peserta (Tanpa Approval)** | ❌ | ❌ | ✅ | ✅ |
| **Unduh Dokumen Pendaftar (CV, Porto, Transkrip)** | ❌ | ✅ | ✅ | ✅ |
| **Kelola Periode Pendaftaran & Jam Batas WIB** | ❌ | ❌ | ✅ | ✅ |
| **Kelola Master Data (Jurusan, Entitas PLN, Unit)** | ❌ | ❌ | ✅ | ✅ |
| **Generator Surat Pengantar Resmi (.docx/.pdf)** | ❌ | ❌ | ✅ | ✅ |
| **Penetapan Akhir & Pengumuman Penempatan** | ❌ | ❌ | ✅ | ✅ |
| **Kelola Akun Admin (BLKA & Perusahaan)** | ❌ | ❌ | ❌ | ✅ |
| **Pengaturan Global Sistem & Konfigurasi Surat** | ❌ | ❌ | ❌ | ✅ |
| **Audit Trail & Histori Log Aktivitas Sistem** | ❌ | ❌ | ✅ | ✅ |

---

## 🚀 FITUR LENGKAP BERDASARKAN PERAN

### 1. Super Admin
Peran tertinggi sistem dengan otoritas penuh atas infrastruktur data dan tata kelola akun:
* **Manajemen Akun Pengguna**:
  * Membuat, memperbarui, mengaktifkan, dan menonaktifkan akun **Admin BLKA** dan **Admin Perusahaan**.
  * Menghubungkan akun Admin Perusahaan dengan entitas induk PLN terkait (`entitas_id`).
  * Fitur *Reset Password* dan *Force Password Change* saat pengguna pertama kali login.
* **Pengaturan Konfigurasi Sistem**:
  * Mengatur parameter global aplikasi: durasi reservasi kuota (*timeout lock* dalam menit), batas maksimal peminatan peserta, dan status pendaftaran.
  * Konfigurasi template surat pengantar: nomor surat dinas, NIP penandatangan, jabatan, token publik validasi surat, dan logo resmi.
* **Audit Trail & Pengawasan**:
  * Memantau log aktivitas sistem secara *realtime* (siapa melakukan apa, kapan, dan dari IP mana).

---

### 2. Admin BLKA
Pengelola operasional pendaftaran dan penempatan dari Bagian Layanan Karir dan Alumni ITPLN:
* **Manajemen Periode Magang**:
  * Membuat siklus periode magang baru dengan status: `persiapan`, `aktif`, `selesai`, atau `ditutup`.
  * Menentukan rentang tanggal pembukaan pendaftaran dan jam selesai (WIB) yang otomatis ditutup oleh sistem cron job.
  * Menentukan batas angkatan mahasiswa yang *eligible* mendaftar (misal: angkatan 2021, 2022).
  * Menentukan visibilitas pengumuman kelulusan ke publik (`pengumuman_dibuka`).
* **Manajemen Master Data Entitas & Hirarki PLN**:
  * Mengelola struktur 6 tingkat organisasi PLN: **Holding, Subholding, Anak Perusahaan, Unit Induk, Unit Pelaksana, dan Unit Layanan**.
  * Mengaktifkan/menonaktifkan status entitas apakah menerima kuota magang pada periode aktif.
  * Mengelola data PIC Narahubung tiap entitas (nama, jabatan, no. telepon/WhatsApp, email resmi).
* **Manajemen Program Studi / Jurusan**:
  * Mengelola master jurusan ITPLN, jenjang pendidikan (D3, S1, S2), dan pemetaan kualifikasi ke peminatan.
* **Monitoring & Rekapitulasi Pendaftar**:
  * Melihat seluruh pendaftar lintas periode dan unit secara komprehensif.
  * Filter multi-parameter: berdasarkan status pendaftaran, unit pilihan, prodi, dan status kelulusan.
  * Ekspor seluruh data pendaftar ke format Microsoft Excel (.xlsx) siap olah.
* **Penetapan & Pemindahan Darurat (*Force Override*)**:
  * Menetapkan kelulusan akhir peserta dan menetapkan nomor Surat Keputusan (SK).
  * Melakukan *force pemindahan* mahasiswa ke unit pelaksana lain jika terjadi kebutuhan mendesak tanpa perlu persetujuan berjenjang.
* **Approval Permohonan Pemindahan Peserta**:
  * Memvalidasi permohonan pemindahan peserta yang diajukan oleh Admin Perusahaan (disetujui atau ditolak dengan catatan alasan).
* **Generator Surat Pengantar Resmi**:
  * Mengompilasi data pendaftar yang diterima menjadi dokumen Surat Pengantar Resmi (.docx) lengkap dengan nomor surat otomatis, tabel daftar mahasiswa, penandatangan, dan QR Code verifikasi dokumen.

---

### 3. Admin Perusahaan / Mitra Unit PLN
Akses khusus bagi perwakilan HRD / pembimbing magang dari masing-masing unit kerja PLN (misal: PT PLN Indonesia Power, PLN UID Jakarta Raya, PLN Icon Plus):
* **Dashboard Kinerja Unit Terisolasi**:
  * Akses data terisolasi ketat hanya untuk entitas perusahaan miliknya (`entitas_id`).
* **Pengaturan Kuota Unit Pelaksana**:
  * Menentukan alokasi kuota penerimaan magang pada unit pelaksana dan peminatan yang dibuka.
  * Mengatur kuota khusus per program studi / jurusan yang dibutuhkan unit.
* **Verifikasi & Seleksi Berkas Pelamar**:
  * Melihat berkas lengkap pelamar yang memilih unitnya.
  * Mengunduh dokumen digital: **Curriculum Vitae (PDF)**, **Portofolio Mahasiswa (PDF)**, dan **Transkrip Nilai Akademik (PDF)**.
* **Pembaruan Status Kelulusan Mahasiswa**:
  * Mengubah status pelamar: `review`, `diterima`, `ditolak`, atau `cadangan`.
  * Fitur **Bulk Update Status**: Memperbarui status puluhan pelamar terpilih secara sekaligus dengan 1 kali klik.
* **Pengajuan Pemindahan Peserta Antar Unit**:
  * Mengajukan pemindahan pelamar yang dinilai kompeten namun kuota unit telah terpenuhi ke unit pelaksana lain yang masih membutuhkan.
  * Menyertakan alasan pemindahan dan menunggu konfirmasi dari BLKA / unit tujuan.
* **Ekspor Data Unit**:
  * Mengunduh daftar pelamar dan rekapitulasi seleksi unit ke dalam format file Excel (.xlsx).
* **Profil Narahubung**:
  * Memperbarui informasi kontak PIC dan unit untuk mempermudah koordinasi dengan tim BLKA.

---

### 4. Mahasiswa ITPLN
Pengguna utama peserta program magang dari kalangan mahasiswa aktif ITPLN:
* **Single Sign-On (SSO) Resmi Kampus**:
  * Login dengan 1 klik menggunakan akun Microsoft 365 resmi kampus (`@itpln.ac.id`).
  * Sistem otomatis menyinkronkan nama lengkap, email, NIM, dan mendeteksi jurusan serta angkatan mahasiswa.
* **Pengecekan Kelayakan (*Eligibility Check*)**:
  * Sistem otomatis memvalidasi apakah angkatan dan prodi mahasiswa memenuhi syarat periode magang yang aktif sebelum mengizinkan pendaftaran.
* **War Kuota & Reservasi Realtime**:
  * Memilih unit pelaksana dan peminatan magang yang diinginkan.
  * Slot kuota langsung dikunci (*reserved*) selama **5 menit** untuk memberi waktu mahasiswa melengkapi berkas dengan tenang tanpa takut kuota diserobot mahasiswa lain.
  * Dilengkapi indikator *live countdown timer* durasi penguncian slot.
* **Pemilihan Multi-Peminatan**:
  * Mahasiswa dapat memilih hingga **3 prioritas peminatan** pada unit terkait untuk memperbesar peluang kelulusan.
* **Unggah Persyaratan Berkas Digital**:
  * Upload Transkrip Nilai Akademik (Format PDF, maksimal 2 MB).
  * Upload Curriculum Vitae / CV (Format PDF, maksimal 1 MB).
  * Upload Dokumen Portofolio Pendukung (Format PDF, maksimal 5 MB).
  * Validasi tipe MIME sisi server untuk mencegah file berbahaya (*tampering protection*).
* **Tracking Status Realtime**:
  * Memantau status pendaftaran: *Draft*, *Menunggu Verifikasi*, *Sedang Direview*, *Diterima*, atau *Ditolak*.
  * Mengetahui unit penempatan definitif jika dinyatakan diterima.
* **Pengumuman Penempatan Publik**:
  * Melihat rekapitulasi nama mahasiswa yang diterima pada unit penempatan melalui portal publik transparan.

---

## 🔄 ALUR BISNIS UTAMA (CORE BUSINESS LOGIC)

### Alur 1: Autentikasi SSO Microsoft Azure Entra ID
```
Mahasiswa              Browser            INTERN Backend            Microsoft Azure
   │                      │                        │                          │
   │── Klik Login SSO ───►│                        │                          │
   │                      │── Redirect Auth URL ──►│                          │
   │                      │◄── URL Login Azure ────│ (Generate State CSRF)    │
   │                      │                                                   │
   │                      │──────────── Redirect ke Microsoft Login ─────────►│
   │                      │◄─────────── Masukkan Akun @itpln.ac.id ───────────│
   │                      │                                                   │
   │                      │◄─────────── Redirect Callback + Auth Code ────────│
   │                      │                                                   │
   │                      │── Kirim Auth Code ────►│                          │
   │                      │                        │── Tukar Token (Secret) ─►│
   │                      │                        │◄── Access Token + ID ────│
   │                      │                        │                          │
   │                      │                        │── Get User Profile ─────►│ (Graph API /me)
   │                      │                        │◄── Nama, Email, NIM ─────│
   │                      │                        │                          │
   │                      │                        │── Buat/Update Mahasiswa  │
   │                      │                        │── Buat Sesi Login Aman   │
   │                      │◄── Set-Cookie Session ─│ (SameSite=Lax, HttpOnly) │
   │                      │                        │                          │
   │◄─ Redirect Portal ───│ (Halaman Daftar/Status)│                          │
```

---

### Alur 2: War Kuota & Concurrency Locking Reservasi
Untuk mencegah *race condition* saat ribuan mahasiswa memperebutkan kuota di detik yang sama, sistem menggunakan transaksi database dengan mekanisme **Pessimistic Row Locking (`SELECT ... FOR UPDATE`)**:

```
Mahasiswa Klik Pilihan Unit
             │
             ▼
Database Transaction Dimulai (`BEGIN`)
             │
             ▼
Lock Baris Kuota Unit:
`SELECT kuota, kuota_terisi FROM unit_pelaksana_periode WHERE id = :id FOR UPDATE;`
             │
             ▼
Hitung Sisa Kuota Realtime:
`Sisa = Kuota - (Pendaftar Tetap + Reservasi Aktif)`
             │
    ┌────────┴────────┐
    ▼                 ▼
[Sisa > 0]       [Sisa <= 0]
    │                 │
    │                 └──► Rollback Transaksi -> Return HTTP 409 "Kuota Penuh"
    ▼
Buat Baris Reservasi Baru (`status = 'aktif'`, `expired_at = NOW() + 5 MENIT`)
             │
             ▼
Commit Transaksi (`COMMIT`)
             │
             ▼
Return HTTP 200 { status: 'success', expired_at: '...' }
(Mahasiswa Memiliki Slot Aman Selama 5 Menit)
```

---

### Alur 3: Multi-Peminatan & Upload Berkas Digital
```
   Step 1: Data Diri (Auto-fill dari Microsoft 365: NIM, Nama, Prodi, No. HP)
                         │
                         ▼
   Step 2: Pilih Unit Pelaksana & Kunci Kuota (Reservasi Aktif 5 Menit)
                         │
                         ▼
   Step 3: Pilih Prioritas Peminatan (Pilihan 1, Pilihan 2, Pilihan 3)
                         │
                         ▼
   Step 4: Upload Dokumen Digital (Validasi Ekstensi PDF & Validasi MIME-Type)
           ├─ Transkrip Nilai (Max 2MB)  -> Disimpan di /storage/transkrip/
           ├─ Curriculum Vitae (Max 1MB) -> Disimpan di /storage/transkrip/
           └─ Portofolio (Max 5MB)       -> Disimpan di /storage/transkrip/
                         │
                         ▼
   Step 5: Konfirmasi Final & Submit:
           - Reservasi diubah menjadi Pendaftaran Tetap (`status = 'submitted'`).
           - Kuota unit terkunci permanen untuk mahasiswa tersebut.
```

---

### Alur 4: Seleksi, Penilaian, & Penetapan Peserta
```
Mahasiswa Submit Pendaftaran
             │
             ▼
Admin Perusahaan Membuka Menu Pendaftar Unit
             │
             ├── Review Profil, CV, Portofolio, & Transkrip
             │
             ├── Update Status Seleksi Individu / Bulk:
             │   - Diterima (Lolos Seleksi Tahap Unit)
             │   - Ditolak (Tidak Memenuhi Kualifikasi)
             │   - Cadangan (Menunggu Kuota Tambahan)
             │
             ▼
Admin BLKA Melakukan Validasi Akhir & Penetapan
             │
             ├── Mengisi Nomor Surat Keputusan (SK)
             │
             ├── Klik "Tetapkan Penempatan"
             │   - Data pendaftar berstatus 'diterima' dikunci.
             │   - Kuota unit difinalisasi.
             │
             ▼
Publikasi Penempatan & Unduh Surat Pengantar Resmi
```

---

### Alur 5: Mekanisme Pemindahan Peserta (Inter-Unit Transfer)
Sistem menyediakan solusi jika pelamar berkualitas tidak tertampung di unit awal namun dibutuhkan di unit lain:

1. **Pengajuan oleh Admin Perusahaan**:
   - Admin Perusahaan asal mengajukan nama peserta ke unit pelaksana tujuan dengan mengisi form alasan pemindahan.
   - Status tercatat pada tabel `pemindahan_peserta` sebagai `menunggu_approval`.
2. **Verifikasi oleh Admin BLKA**:
   - Admin BLKA menerima notifikasi di menu `/admin/pemindahan.html`.
   - BLKA dapat **Menyetujui** (*Approve*) atau **Menolak** (*Reject*).
   - Jika disetujui, pendaftaran peserta otomatis dipindahkan ke unit baru, kuota unit lama dikembalikan, dan kuota unit baru dipotong secara atomik dalam transaksi database.
3. **Fitur Force Pemindahan (BLKA Khusus)**:
   - Admin BLKA dapat langsung memindahkan peserta secara mandiri (*force override*) untuk kebutuhan darurat tanpa melalui tahap pengajuan perusahaan.

---

### Alur 6: Mesin Generator Surat Pengantar Resmi (.docx/.pdf)
Modul `src/SuratGenerator.php` mengotomatisasi pembuatan dokumen resmi kampus:
- Membaca template master Microsoft Word (`.docx`).
- Menggantikan variabel dinamis secara otomatis: `${NOMOR_SURAT}`, `${TANGGAL_SURAT}`, `${NAMA_PERUSAHAAN}`, `${ALAMAT_PERUSAHAAN}`, `${TABEL_MAHASISWA}`, `${PEJABAT_NAMA}`, `${PEJABAT_NIP}`.
- Membuat tabel daftar mahasiswa (NIM, Nama, Program Studi) secara proporsional.
- Menyisipkan **QR Code Verifikasi Resmi** yang dapat dipindai oleh pihak eksternal untuk membuktikan keaslian surat penempatan magang.
- Mengompilasi dan mengemas ulang berkas dokumen menjadi `.docx` siap cetak atau konversi PDF.

---

## 📁 STRUKTUR DIREKTORI PROYEK

```text
magang_blka/v2/
├── api/                                # Seluruh Endpoint RESTful API Backend
│   ├── admin/                          # API Khusus Otoritas Super Admin & BLKA
│   │   ├── auth/                       # Login & logout admin internal
│   │   ├── cv/, porto/, transkrip/     # Unduh berkas digital mahasiswa
│   │   ├── entitas/                    # CRUD hirarki unit dan entitas PLN
│   │   ├── jurusan/                    # CRUD master jurusan ITPLN
│   │   ├── narahubung/                 # CRUD data PIC unit PLN
│   │   ├── pemindahan/                 # Approval pemindahan peserta antar unit
│   │   ├── pendaftar/                  # Rekapitulasi & manipulasi data pelamar
│   │   ├── penetapan/                  # Penetapan kelulusan & nomor SK
│   │   ├── periode/                    # CRUD siklus periode magang
│   │   ├── status.php                  # Status autentikasi sesi admin
│   │   └── surat/                      # Generator & template surat pengantar
│   ├── auth/                           # API Autentikasi Pengguna
│   │   ├── azure/                      # Direct Microsoft Azure Entra ID SSO
│   │   │   ├── callback.php            # OAuth2 callback & token exchange
│   │   │   ├── login.php               # Redirector ke halaman login Microsoft
│   │   │   └── mock.php                # Simulator auth untuk testing lokal
│   │   ├── logout.php                  # Penghancuran sesi login aman
│   │   └── status.php                  # Pengecekan sesi aktif mahasiswa
│   ├── jurusan/                        # API publik data program studi
│   ├── mahasiswa/                      # API Transaksi Pendaftaran Mahasiswa
│   │   ├── batal_reservasi.php         # Pembatalan slot kuota oleh user
│   │   ├── profil.php                  # Data diri mahasiswa hasil SSO
│   │   ├── reservasi.php               # Locking reservasi kuota 5 menit
│   │   ├── status_pendaftaran.php      # Tracking status & hasil seleksi
│   │   ├── submit.php                  # Final submit pendaftaran magang
│   │   └── transkrip/, cv/, porto/     # Endpoint upload dokumen PDF pendaftar
│   ├── peminatan/                      # API daftar kategori peminatan
│   ├── pengaturan/                     # API konfigurasi sistem & surat
│   ├── periode/                        # API informasi periode magang aktif
│   ├── perusahaan/                     # API Khusus Portal Admin Perusahaan
│   │   ├── auth/                       # Login & first login password reset
│   │   ├── dashboard/                  # Ringkasan statistik kuota & pelamar
│   │   ├── kuota/                      # Pengaturan kuota unit pelaksana
│   │   ├── pendaftar/                  # Seleksi pelamar & bulk status update
│   │   └── pemindahan/                 # Pengajuan transfer pelamar antar unit
│   ├── public/                         # API Publik Tanpa Sesi (Penempatan, dll)
│   ├── unit/                           # API pencarian unit & kuota realtime
│   └── wilayah.php                     # Proxy data provinsi/kabupaten Indonesia
│
├── docs/                               # Dokumentasi Teknis & Berkas Administrasi
│   ├── backup_magang_itpln_v2_latest.sql # Master SQL dump siap impor
│   ├── DEPLOY_NGINX.md                 # Konfigurasi Virtual Host Nginx
│   ├── NOTA_DINAS_DEPLOYMENT_TEKNIS.md # Surat Nota Dinas resmi untuk BSI
│   └── RINGKASAN_INFRASTRUKTUR_MEETING_BSI.md # Panduan singkat rapat server
│
├── migrations/                         # 33 Skrip Migrasi Skema Basis Data (Phinx)
│   ├── 20260717000001_create_jurusan.php
│   ├── ...
│   └── 20260917000034_create_pemindahan_peserta_table.php
│
├── public/                             # Root Direktori Web Publik (Static Assets)
│   ├── admin/                          # Antarmuka Dashboard Admin BLKA & Super Admin
│   │   ├── index.html                  # Dashboard metrik & analitik
│   │   ├── pendaftar.html              # Manajemen & tabel rekap pendaftar
│   │   ├── periode.html                # Manajemen periode & tanggal batas
│   │   ├── penetapan.html              # Penetapan kelulusan peserta
│   │   ├── pemindahan.html             # Manajemen transfer peserta antar unit
│   │   ├── hasilkan-surat.html         # Generator surat pengantar resmi
│   │   ├── kelola-admin.html           # Manajemen akun admin (Super Admin)
│   │   ├── pengaturan.html             # Pengaturan konfigurasi sistem
│   │   └── holding.html, unit.html, dll# Manajemen master entitas PLN
│   ├── perusahaan/                     # Portal Admin Perusahaan (HRD Unit PLN)
│   │   └── index.html                  # Single Page Application Admin Mitra
│   ├── css/                            # File Stylesheet CSS Terstruktur
│   ├── js/                             # Logika Frontend (ES6+ Modular Scripts)
│   ├── assets/                         # Logo ITPLN, Logo PLN, Icons, SVG
│   ├── index.html                      # Landing Page Publik Informasi Magang
│   ├── login.html                      # Halaman Login Terpadu (SSO Mahasiswa & Admin)
│   ├── daftar.html                     # Wizard Pendaftaran & War Kuota Mahasiswa
│   ├── status.html                     # Dashboard Tracking Pendaftaran Mahasiswa
│   └── penempatan.html                 # Pengumuman Publik Rekapitulasi Kelulusan
│
├── scripts/                            # Skrip CLI & Tugas Otomatis Server
│   ├── cleanup_reservasi.php           # Cron job pembersihan kuota kadaluarsa
│   ├── cron_close_periode.php          # Cron job penutupan periode otomatis
│   └── backup_db.sh                    # Skrip bash auto-backup basis data harian
│
├── seeds/                              # Skrip Seeder Master Data Awal (Phinx)
│   ├── AdminSeeder.php                 # Akun default administrator sistem
│   ├── JurusanSeeder.php               # Data seluruh program studi ITPLN
│   └── EntitasPerusahaanSeeder.php     # Master data entitas & unit kerja PLN
│
├── src/                                # Logika Bisnis Utama (PSR-4 Namespace: App\)
│   ├── Auth.php                        # Autentikasi sesi, RBAC, proteksi CSRF
│   ├── AzureAuth.php                   # Integrasi Direct Microsoft Entra ID SSO
│   ├── Database.php                    # Singleton PDO connection & transaksi
│   ├── PenetapanHelper.php             # Logika penetapan, kuota & pemindahan peserta
│   ├── PeriodeHelper.php               # Logika siklus periode magang
│   ├── ReservasiHelper.php             # Logika locking kuota & race condition
│   └── SuratGenerator.php              # Kompilasi dokumen Word (.docx) & QR Code
│
├── storage/                            # Penyimpanan Berkas Berisi Data Privat
│   └── transkrip/                      # Dokumen CV, Porto, dan Transkrip Nilai
│                                       # (Wajib ditutup dari akses web langsung!)
├── composer.json                       # Dependensi Pustaka PHP & Autoloading
├── phinx.php                           # Konfigurasi Basis Data untuk Phinx
├── router.php                          # Router emulator untuk Local Built-in Server
└── .env.example                        # Template Variabel Environment Sistem
```

---

## 💻 PANDUAN INSTALASI & SETUP LOKAL

Ikuti langkah-langkah berikut untuk menjalankan sistem di lingkungan pengembangan (*local development*):

### 1. Prasyarat Sistem:
- **PHP >= 8.2** (dengan ekstensi: `pdo_mysql`, `curl`, `mbstring`, `zip`, `gd`, `xml`, `bcmath`, `fileinfo`).
- **Composer 2.x**.
- **MySQL 8.0+** atau **MariaDB 10.6+**.
- **Git**.

### 2. Kloning Repositori & Pasang Dependensi:
```bash
# Clone repositori
git clone https://github.com/gaangsarr/magang_blka_v2.git
cd magang_blka_v2

# Pasang dependensi PHP via Composer
composer install
```

### 3. Konfigurasi File Environment:
Salin berkas template konfigurasi:
```bash
cp .env.example .env
```
Buka file `.env` dan sesuaikan parameter database lokal Anda:
```ini
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=magang_itpln
DB_USER=root
DB_PASS=

# Pengaturan sesi lokal
SESSION_KEY=d08479e0a471f54a86b0a88b56f2dc98b48ef7e7f12a34bc56de78fa90bc12de
APP_ENV=local
APP_URL=http://localhost:8000
APP_DEBUG=true
```

### 4. Migrasi & Seeder Database:
Pastikan database kosong (misal: `magang_itpln`) sudah dibuat di MySQL Anda. Jalankan:
```bash
# Jalankan seluruh skrip migrasi skema tabel
vendor/bin/phinx migrate

# Isi master data awal (Jurusan, Entitas PLN, Admin Default)
vendor/bin/phinx seed:run
```

### 5. Jalankan Server Lokal:
Aplikasi telah dilengkapi skrip `router.php` untuk mengemulasikan Nginx di PHP Built-in Server:
```bash
php -S localhost:8000 router.php
```
Buka browser Anda dan akses:
- **Halaman Utama**: `http://localhost:8000/`
- **Portal Pendaftaran**: `http://localhost:8000/daftar.html`
- **Login Mahasiswa / Admin**: `http://localhost:8000/login.html`
- **Dashboard Admin BLKA**: `http://localhost:8000/admin/`
- **Portal Admin Perusahaan**: `http://localhost:8000/perusahaan/`

> 💡 **Tips Pengujian SSO di Lokal**:  
> Di lingkungan lokal (`APP_ENV=local`), Anda dapat menguji login SSO tanpa akun Microsoft melalui simulator mock di `http://localhost:8000/api/auth/azure/mock.php`.

---

## 🗄 DATABASE & SKEMA MIGRASI (PHINX)

Proyek ini menggunakan **Phinx** untuk mengelola perubahan skema secara terstandarisasi industri.

### Perintah Utama Phinx:
```bash
# Menjalankan migrasi yang belum dieksekusi
vendor/bin/phinx migrate

# Membatalkan (rollback) migrasi terakhir
vendor/bin/phinx rollback

# Melihat status migrasi yang sudah dan belum berjalan
vendor/bin/phinx status

# Menjalankan seeder pengisian master data
vendor/bin/phinx seed:run

# Menjalankan seeder tertentu saja (contoh: JurusanSeeder)
vendor/bin/phinx seed:run -s JurusanSeeder
```

### Daftar Tabel Utama Basis Data:
1. `admin`: Pengguna internal (Super Admin, Admin BLKA, Admin Perusahaan).
2. `mahasiswa`: Data mahasiswa pendaftar hasil sinkronisasi Microsoft SSO.
3. `jurusan`: Master program studi dan jenjang pendidikan ITPLN.
4. `periode`: Siklus penerimaan magang, tanggal buka/tutup, dan eligibilitas angkatan.
5. `entitas_perusahaan`: Hirarki organisasi PLN (Holding s/d Unit Layanan).
6. `unit_pelaksana_periode`: Alokasi kuota per unit pelaksana pada periode aktif.
7. `unit_peminatan`: Bidang/divisi penempatan yang dibuka pada unit pelaksana.
8. `peminatan_jurusan`: Relasi kualifikasi jurusan yang diizinkan pada peminatan.
9. `reservasi`: Penguncian slot kuota sementara (*lock 5 menit*) anti *race condition*.
10. `pendaftaran`: Berkas pendaftaran tetap, status verifikasi, dan status seleksi.
11. `pendaftaran_peminatan`: Rekam pilihan multi-peminatan (Prioritas 1, 2, 3).
12. `pemindahan_peserta`: Riwayat dan permohonan transfer peserta antar unit pelaksana.
13. `konfigurasi_surat`: Pengaturan nomor surat, NIP, penandatangan, dan token publik.
14. `log_aktivitas`: Rekam jejak audit keamanan seluruh aksi penting pengguna.

---

## ⏱ TUGAS TERJADWAL (BACKGROUND CRON JOBS)

Sistem bergantung pada 2 tugas latar belakang (*cron job*) krusial untuk menjamin keadilan kuota dan ketepatan waktu periode:

Buka crontab user web server di terminal server (`crontab -u www-data -e`):

```cron
# 1. CLEANUP RESERVASI KADALUARSA (Setiap 1 Menit)
# Melepaskan kunci reservasi kuota pendaftar yang tidak menyelesaikan formulir dalam 5 menit,
# sehingga slot kuota otomatis kembali tersedia bagi mahasiswa lain.
* * * * * /usr/bin/php /var/www/magang_blka/v2/scripts/cleanup_reservasi.php >> /var/www/magang_blka/v2/logs/cron_cleanup.log 2>&1

# 2. PENUTUPAN OTOMATIS PERIODE MAGANG (Setiap 1 Menit)
# Mengubah status periode dari 'aktif' menjadi 'selesai' secara otomatis dan tepat waktu
# ketika jam batas pendaftaran (WIB) server tercapai.
* * * * * /usr/bin/php /var/www/magang_blka/v2/scripts/cron_close_periode.php >> /var/www/magang_blka/v2/logs/cron_close_periode.log 2>&1

# 3. PENCADANGAN DATABASE OTOMATIS (Setiap Pukul 02:00 WIB Dini Hari)
0 2 * * * /bin/bash /var/www/magang_blka/v2/scripts/backup_db.sh >> /var/www/magang_blka/v2/logs/cron_backup.log 2>&1
```

---

## ⚙️ KONFIGURASI ENVIRONMENT (.ENV)

Berikut adalah panduan lengkap setiap parameter dalam berkas `.env`:

| Nama Variabel | Contoh Nilai | Keterangan & Tujuan |
| :--- | :--- | :--- |
| `DB_HOST` | `127.0.0.1` | Alamat host server MySQL / MariaDB. |
| `DB_PORT` | `3306` | Port koneksi database MySQL. |
| `DB_NAME` | `blka_magang` | Nama database aplikasi. |
| `DB_USER` | `app_magang` | Username database untuk operasional web (cukup DML: `SELECT, INSERT, UPDATE, DELETE`). |
| `DB_PASS` | `Password_Kuat_Disini` | Password akun database pengguna web. |
| `DB_MIGRATION_USER` | `app_migration` | Username khusus untuk eksekusi migrasi skema Phinx (membutuhkan hak DDL: `CREATE, ALTER, DROP`). |
| `DB_MIGRATION_PASS` | `Password_Kuat_Disini` | Password akun migrasi database. |
| `AZURE_TENANT_ID` | `7b388d18-1900-418c...` | Tenant ID direktori Microsoft Entra ID ITPLN. |
| `AZURE_CLIENT_ID` | `xxxxxxxx-xxxx-xxxx...` | Application (Client) ID hasil App Registration di Azure Portal. |
| `AZURE_CLIENT_SECRET`| `xxxxxxxxxxxxxxxxxxxx` | Value Client Secret aktif dari Azure Portal. |
| `AZURE_REDIRECT_URI` | `https://.../callback.php` | URL Callback OAuth2 yang didaftarkan pada platform Web di Azure Portal. |
| `SESSION_KEY` | `64_hex_chars...` | Kunci enkripsi sesi 64 karakter hex. Dibuat via: `php -r "echo bin2hex(random_bytes(32));"` |
| `APP_ENV` | `production` / `local` | Lingkungan aplikasi (`production` untuk server live, `local` untuk pengujian developer). |
| `APP_URL` | `https://magang.itpln.ac.id` | Domain root resmi sistem magang. |
| `APP_DEBUG` | `false` | Matikan (`false`) di server produksi agar detail stack trace error tidak bocor ke publik. |
| `RESERVATION_MINUTES`| `5` | Batas durasi penguncian slot kuota (*locking timer*) sebelum kadaluarsa (dalam menit). |
| `MAX_PEMINATAN` | `3` | Jumlah maksimal pilihan peminatan per pendaftaran mahasiswa. |

---

## 🛡 PANDUAN DEPLOYMENT PRODUKSI & SECURITY HARDENING

### 1. Rekomendasi Virtual Host Nginx (`/etc/nginx/sites-available/intern.conf`):

```nginx
server {
    listen 80;
    server_name magang.itpln.ac.id; # Atau magang.karirku.itpln.ac.id
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name magang.itpln.ac.id;

    root /var/www/magang_blka/v2;
    index index.html index.php;
    charset utf-8;

    # SSL Certificate (Sertifikat Wildcard ITPLN atau Let's Encrypt)
    ssl_certificate /etc/ssl/certs/itpln_wildcard.crt;
    ssl_certificate_key /etc/ssl/private/itpln_wildcard.key;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Batasan Maksimal Body Upload (CV 1MB, Portofolio 5MB, Transkrip 2MB)
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

    # 3. PROTEKSI PRIVASI DOKUMEN MAHASISWA
    # Direktori /storage/ WAJIB ditutup total dari akses web langsung!
    location /storage/ {
        deny all;
        return 403;
    }

    # 4. SECURITY HARDENING: Blokir Folder & Berkas Sensitif
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

    # 6. Kompresi Gzip
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml application/json;
}
```

### 2. Hak Akses Berkas & Direktori (Permissions):
Jalankan perintah berikut di server produksi:
```bash
# Berikan kepemilikan kepada user web server (www-data)
sudo chown -R www-data:www-data /var/www/magang_blka/v2

# Folder yang membutuhkan izin tulis:
sudo chmod -R 775 /var/www/magang_blka/v2/storage
sudo chmod -R 775 /var/www/magang_blka/v2/logs
sudo chmod -R 775 /var/www/magang_blka/v2/cache

# Lindungi file konfigurasi sensitif (.env) agar hanya bisa dibaca web server
sudo chmod 600 /var/www/magang_blka/v2/.env
```

---

## 📄 LISENSI & KEPEMILIKAN

Sistem ini dikembangkan secara eksklusif untuk kepentingan institusional:
* **Pemilik Hak Cipta**: Bagian Layanan Karir dan Alumni (BLKA) — Institut Teknologi PLN.
* **Mitra Kerjasama Strategis**: PT PLN (Persero) beserta Subholding dan Anak Perusahaan.
* **Status Distribusi**: *Proprietary / Closed-source*. Penggandaan, pendistribusian ulang, atau pemanfaatan sebagian/seluruh kode sumber di luar lingkungan resmi ITPLN tanpa izin tertulis dilarang keras.

---

<p align="center">
  <b>Institut Teknologi PLN</b><br>
  <i>Menara PLN, Jl. Lingkar Luar Barat, Duri Kosambi, Cengkareng, Jakarta Barat 11750</i><br>
  🌐 <a href="https://itpln.ac.id">itpln.ac.id</a> | 💼 <a href="https://karirku.itpln.ac.id">karirku.itpln.ac.id</a>
</p>

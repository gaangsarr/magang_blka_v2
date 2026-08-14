# BUKU PANDUAN LENGKAP SISTEM INFORMASI MAGANG BLKA
### **Badan Pengelola Latihan Kerja & Alumni (BLKA) — Institut Teknologi PLN × PT PLN (Persero)**

---

## DAFTAR ISI
1. [Pendahuluan & Gambaran Umum Sistem](#1-pendahuluan--gambaran-umum-sistem)
2. [Penjelasan Hak Akses & Role Pengguna](#2-penjelasan-hak-akses--role-pengguna)
3. [Panduan Lengkap Role Mahasiswa](#3-panduan-lengkap-role-mahasiswa)
   - [3.1. Login & Autentikasi Single Sign-On (SSO)](#31-login--autentikasi-single-sign-on-sso)
   - [3.2. Panduan Langkah Alur Pendaftaran Magang (4-Step Wizard)](#32-panduan-langkah-alur-pendaftaran-magang-4-step-wizard)
   - [3.3. Dashboard Mahasiswa & Cek Status Pendaftaran](#33-dashboard-mahasiswa--cek-status-pendaftaran)
   - [3.4. Memahami Makna & Arti Hasil Seleksi Magang](#34-memahami-makna--arti-hasil-seleksi-magang)
   - [3.5. Panduan Logout & Keamanan Akun](#35-panduan-logout--keamanan-akun)
4. [Panduan Lengkap Role Admin (Admin BLKA & Super Admin)](#4-panduan-lengkap-role-admin-admin-blka--super-admin)
   - [4.1. Access Control & Autentikasi Admin](#41-access-control--autentikasi-admin)
   - [4.2. Dashboard Utama Admin (Overview & Analitik)](#42-dashboard-utama-admin-overview--analitik)
   - [4.3. Modul Pengelolaan Periode Magang](#43-modul-pengelolaan-periode-magang)
   - [4.4. Modul Pengelolaan Unit Pelaksana & Peminatan Bidang](#44-modul-pengelolaan-unit-pelaksana--peminatan-bidang)
   - [4.5. Modul Pengelolaan Data Pendaftar & Verifikasi Berkas](#45-modul-pengelolaan-data-pendaftar--verifikasi-berkas)
   - [4.6. Modul Penetapan Unit Magang & Pemindahan (Relokasi Pendaftar)](#46-modul-penetapan-unit-magang--pemindahan-relokasi-pendaftar)
   - [4.7. Modul Histori Periode & Laporan Detail (Export Excel)](#47-modul-histori-periode--laporan-detail-export-excel)
   - [4.8. Modul Pengaturan Sistem & Pengelolaan Hak Akses Admin (Multi-Admin)](#48-modul-pengaturan-sistem--pengelolaan-hak-akses-admin-multi-admin)
5. [Panduan Troubleshooting, Operasional, & Serah Terima Jabatan](#5-panduan-troubleshooting-operasional--serah-terima-jabatan)

---

## 1. PENDAHULUAN & GAMBARAN UMUM SISTEM

**Sistem Informasi Pendaftaran & Penetapan Magang BLKA ITPLN** adalah platform digital resmi yang dirancang untuk memfasilitasi seluruh alur pendaftaran, verifikasi berkas, hingga alokasi penetapan unit magang mahasiswa Institut Teknologi PLN pada berbagai unit kerja **PT PLN (Persero)**.

### **Fitur Utama Sistem:**
1. **Single Sign-On (SSO) Microsoft Account Kampus**: Login seamless menggunakan akun email resmi kampus `@itpln.ac.id` via Microsoft Authentication.
2. **Pendaftaran Magang Bertahap (5-Step Wizard)**: Form intuitif yang memandu mahasiswa dari pemilihan program, data diri, lokasi domisili peta, pilihan unit PLN, hingga resume pendaftaran.
3. **Penyaringan Syarat Otomatis (IPK & SKS Validation)**: Sistem memvalidasi secara real-time kelayakan IPK & SKS mahasiswa untuk program 5 Bulan (KRS).
4. **Reservasi Kuota Real-Time**: Sistem menahan sementara kuota unit PLN pilihan selama 5 menit untuk memastikan kepastian alokasi tempat.
5. **Penetapan & Relokasi Unit Magang (Smart Placement)**: Sistem memfasilitasi penetapan mahasiswa pada unit pilihan awal atau memindahkan (*relocate*) pendaftar ke unit alternatif berdasarkan keputusan pertimbangan operasional BLKA ITPLN.
6. **Multi-Admin & Role Management**: Dukungan pengelolaan akun Admin BLKA dan Super Admin secara fleksibel melalui pengangkatan berbasis Email Kampus.
7. **Laporan & Export Excel**: Generasi laporan histori pendaftaran per periode yang dapat di-export langsung ke format Excel `.xlsx`.

---

## 2. PENJELASAN HAK AKSES & ROLE PENGGUNA

Aplikasi ini membagi hak akses menjadi 3 tingkatan utama:

| Role Pengguna | Metode Login | Deskripsi & Hak Akses |
| :--- | :--- | :--- |
| **Mahasiswa** | SSO Microsoft Email Kampus (`@itpln.ac.id`) | Menelusuri periode aktif, mengisi form pendaftaran magang 5-step wizard, menentukan domisili peta, memilih unit PLN, serta memantau status pengumuman hasil penetapan. |
| **Admin BLKA** | SSO Microsoft Email Kampus (Terdaftar di Database Admin) | Mengelola data unit pelaksana & peminatan, memverifikasi pendaftaran mahasiswa, melakukan penetapan unit magang, memindahkan (*relocate*) pendaftar, dan mengunduh laporan Excel pendaftaran. |
| **Super Admin** | SSO Microsoft Email Kampus (Role `super_admin`) | Memiliki seluruh hak akses **Admin BLKA**, ditambah hak khusus untuk mengatur syarat minimal IPK & SKS program KRS, serta mengelola hak akses tambah/cabut akun Admin lain. |

---

## 3. PANDUAN LENGKAP ROLE MAHASISWA

### **3.1. Login & Autentikasi Single Sign-On (SSO)**
1. Akses halaman utama aplikasi (`login.html`) di browser Anda.
2. Klik tombol **"Masuk"** yang memiliki **Logo Microsoft** (4 kotak warna: Merah, Hijau, Biru, Kuning).
3. Sistem akan mengarahkan Anda ke jendela autentikasi resmi **Microsoft Account Sign-In**.
4. Pilih atau masukkan **Email Kampus ITPLN** Anda (contoh: `gangsar2431170@itpln.ac.id`).
5. Masukkan kata sandi email kampus Anda.
6. Setelah autentikasi berhasil, Anda akan otomatis masuk ke **Dashboard Mahasiswa**.

---

### **3.2. Panduan Langkah Alur Pendaftaran Magang (5-Step Wizard)**

Pendaftaran dilakukan melalui 5 tahapan berurutan pada formulir `daftar.html`:

#### **Step 1: Pilih Program Magang**
- Pilih durasi/jenis program magang yang tersedia (misal: **Program 1 Bulan** atau **Program 5 Bulan KRS**).
- Untuk **Program 5 Bulan (KRS)**, sistem akan secara otomatis memverifikasi apakah IPK dan total SKS Anda memenuhi syarat minimal yang ditetapkan BLKA (Minimal IPK 3.00 & Minimal 110 SKS). Jika kurang, opsi program akan terkunci dengan pesan peringatan.

#### **Step 2: Isi Data Diri & Akademik**
- **Data Otomatis (Read-Only)**: Sistem menampilkan **Nama Lengkap**, **NIM**, **Jurusan**, **Angkatan**, dan **Email Kampus** yang diambil secara otomatis dari akun Microsoft SSO.
- **Data Isian Manual**:
  - Pilih **Jenis Kelamin** (Laki-Laki / Perempuan).
  - Masukkan **Nomor Handphone / WhatsApp** yang aktif.
  - Masukkan **IPK Terakhir**.
  - Masukkan **Jumlah SKS yang Sudah Ditempuh**.
  - Pilih **Peminatan** keahlian yang relevan (maksimal memilih 3 bidang peminatan).

#### **Step 3: Isi Lokasi Domisili (Peta Interaktif)**
- Tentukan posisi tempat tinggal Anda saat ini pada **Peta Interaktif (Leaflet)** atau gunakan fitur **Cari Lokasi**.
- **Penyesuaian Pin Manual**: Jika hasil pencarian lokasi tidak presisi atau tidak sesuai, Anda dapat menggeser peta secara manual dan **mengklik langsung titik lokasi yang tepat pada peta** untuk memindahkan pin marker.
- Sistem akan secara otomatis merekam koordinat **Latitude** dan **Longitude** terbaru secara presisi untuk menghitung jarak ke unit PLN.
- Lengkapi rincian **Alamat Lengkap**, **RT**, **RW**, **Kelurahan**, **Kecamatan**, **Kota/Kabupaten**, dan **Provinsi**.

#### **Step 4: Pilih Unit Pelaksana PLN & Reservasi Kuota**
- Pilih **1 Unit Pelaksana PLN** tujuan magang Anda dari daftar tabel unit yang menampilkan jarak domisili dan sisa kuota.
- Saat menekan tombol pilih unit, sistem melakukan **Reservasi Kuota Real-Time** (kuota ditahan sementara selama 5 menit).

#### **Step 5: Resume & Kirim Pendaftaran**
- Periksa kembali ringkasan data pendaftaran Anda (Nama, NIM, Program, No HP, Alamat, dan Unit PLN yang dipilih).
- Perhatikan **Timer Sisa Waktu Konfirmasi Reservasi**.
- Klik tombol **"Kirim Pendaftaran"** untuk menyelesaikan proses pendaftaran.

---

### **3.3. Dashboard Mahasiswa & Cek Status Pendaftaran**
Setelah mengirimkan pendaftaran, mahasiswa dapat memantau status pendaftaran dan alokasi unit magang secara *real-time* melalui halaman Pengumuman/Status (`status.html`).

---

### **3.4. Memahami Makna & Arti Hasil Seleksi Magang**

Berikut adalah penjelasan rinci mengenai 4 status hasil pendaftaran yang tampil pada halaman Pengumuman (`status.html`):

| Status Pendaftaran / Penetapan | Arti / Makna Status |
| :--- | :--- |
| **BERKAS DIAJUKAN** | Data pendaftaran telah berhasil direkam ke dalam sistem dan sedang dalam tahap peninjauan serta alokasi kuota oleh Tim BLKA ITPLN. |
| **DITERIMA** | Anda secara resmi **DITERIMA** melaksanakan magang pada Unit Pelaksana PLN pilihan utama Anda. |
| **DITETAPKAN (DIPINDAHKAN)** | Penempatan dialokasikan / dipindahkan ke Unit Pelaksana PLN alternatif berdasarkan keputusan resmi dan pertimbangan operasional BLKA ITPLN (bersifat mutlak / tidak dapat diganggu gugat). |
| **DITOLAK** | Pengajuan magang Anda belum dapat disetujui atau kuota seluruh unit pelaksana telah terpenuhi pada periode berjalan. |

---

### **3.5. Panduan Logout & Keamanan Akun**
1. Untuk mengakhiri sesi pendaftaran demi keamanan data Anda, klik tombol **"Keluar / Logout"** di pojok kanan atas dashboard.
2. Sistem akan menghapus cookie sesi dan mengembalikan Anda ke halaman utama.
3. Selalu pastikan Anda tidak meninggalkan akun Anda terbuka di komputer umum/fasilitas kampus.

---

## 4. PANDUAN LENGKAP ROLE ADMIN (ADMIN BLKA & SUPER ADMIN)

### **4.1. Access Control & Autentikasi Admin**
Admin dapat melakukan login melalui dua jalur autentikasi resmi:
1. **Login Kredensial Langsung (Portal Admin)**:
   - Akses halaman login admin di rute `/admin/login.html`.
   - Masukkan **Email Administrator** dan **Kata Sandi**.
   - Tekan tombol **"Masuk ke Portal Admin"**.
2. **Auto-Redirect SSO Microsoft**:
   - Jika pengguna terdaftar di tabel database `admin` (`aktif = 1`), saat melakukan login via SSO Microsoft pada halaman utama, sistem akan otomatis mengenali hak akses admin dan langsung mengarahkan pengguna ke **Dashboard Admin** (`/admin/index.html`).

---

### **4.2. Dashboard Utama Admin (Overview & Analitik)**
Halaman Utama Admin (`/admin/index.html`) menyajikan indikator kinerja utama (KPI) pendaftaran magang:
1. **Kartu Ringkasan Kuota & Pendaftar**:
   - Total Mahasiswa Pendaftar Periode Aktif
   - Total Kuota Keseluruhan Unit
   - Sisa Kuota Kuota Tersedia
2. **Grafik Sebaran Pendaftar Per Jurusan**: Visualisasi jumlah pendaftar berdasarkan program studi/jurusan mahasiswa.
3. **Grafik Top 10 Unit Pelaksana Favorit**: Visualisasi 10 kantor unit pelaksana PLN yang paling banyak dipilih oleh pendaftar.

---

### **4.3. Modul Pengelolaan Periode Magang**
Diakses melalui menu **"Periode Magang"** (`/admin/periode.html`).

#### **Fitur & Langkah Kerja:**
1. **Tambah Periode Magang Baru**:
   - Klik tombol **"+ Tambah Periode Magang"**.
   - Isi Nama Periode (misal: *Periode Semester Genap 2025/2026*).
   - Isi Tahun Akademik (misal: *2025/2026*).
   - Tentukan **Tanggal Mulai Pendaftaran** dan **Tanggal Selesai Pendaftaran**.
   - Isi Syarat **Angkatan Minimal** (misal: `2022`).
   - Klik **"Simpan Periode"**.
2. **Kelola Status Periode**:
   - Status periode dapat diatur menjadi:
     - **Dibuka (Aktif)**: Periode berjalan di mana mahasiswa dapat mengakses form pendaftaran.
     - **Ditutup**: Pendaftaran dikunci untuk mahasiswa. Admin tetap dapat melakukan peninjauan & penetapan unit.
     - **Nonaktif / Arsip**: Periode diarsipkan.

---

### **4.4. Modul Pengelolaan Unit Pelaksana & Peminatan (3 Tab)**
Diakses melalui menu **"Unit Pelaksana"** (`/admin/unit.html`). Modul ini terbagi menjadi 3 tab navigasi utama:

1. **Tab 1: Kuota Periode Aktif**:
   - Mengatur **Alokasi Kuota Total** per unit pelaksana PLN khusus pada periode magang yang sedang berjalan.
   - Mengubah status keikutsertaan unit (*Aktif / Nonaktif*) pada periode berjalan.
2. **Tab 2: Master Data Unit Pelaksana**:
   - Menambahkan kantor unit PLN baru via tombol **"+ Tambah Unit Baru"**.
   - Mengisi Nama Unit, Alamat Kantor, serta Koordinat **Latitude & Longitude** (digunakan oleh sistem untuk menghitung jarak domisili mahasiswa ke unit PLN secara presisi).
3. **Tab 3: Master Data Peminatan**:
   - Menambahkan kategori bidang peminatan keahlian baru via tombol **"+ Tambah Peminatan Baru"**.
   - Mengisi Nama Peminatan, Deskripsi, dan Status Aktif.

---

### **4.5. Modul Pengelolaan & Peninjauan Data Pendaftar**
Diakses melalui menu **"Data Pendaftar"** (`/admin/pendaftar.html`).

#### **Fitur & Langkah Kerja:**
1. Menampilkan daftar lengkap seluruh mahasiswa pendaftar pada periode aktif.
2. **Pencarian Cepat**: Menggunakan kolom pencarian filter berdasarkan Nama, NIM, atau Unit Penempatan.
3. **Peninjauan Detail**: Membuka modal informasi lengkap mahasiswa mencakup biodata, IPK, jumlah SKS ditempuh, kontak WA, alamat domisili, titik lokasi peta, pilihan peminatan, dan unit pelaksana yang dipilih.
4. **Export Excel Pendaftar**: Mengunduh rekapitulasi data pendaftar periode aktif format `.xlsx` via tombol **"Export Excel"**.

---

### **4.6. Modul Penetapan Unit Magang & Pemindahan (Relokasi Pendaftar)**
Diakses melalui menu **"Penetapan Unit"** (`/admin/penetapan.html`).

Modul ini adalah pusat kendali kelulusan dan alokasi unit tempat magang mahasiswa.

#### **1. Fitur Multi-Filter Grid:**
Admin dapat menyaring data pendaftar menggunakan kombinasi filter:
- Search Keyword (Nama / NIM / Email)
- Filter Jurusan
- Filter Unit Penempatan
- Filter Program (Magang 1 Bulan / Magang 5 Bulan KRS)
- Filter Status Penetapan (*Belum Ditetapkan, Diterima, Dipindahkan Paksa, Ditolak*)

#### **2. Aksi Penetapan & Pemindahan (Individual):**
- **Setujui Penetapan**: Menyetujui penempatan mahasiswa pada unit pilihan utama $\rightarrow$ Status berubah menjadi **DITERIMA**.
- **Pemindahan Paksa Unit (Relokasi)**:
  - Klik tombol **"Pindahkan Unit"**.
  - Pilih **Unit Tujuan Baru** dari dropdown unit yang memiliki kuota tersedia.
  - Isikan **Catatan Pemindahan** (misal: *"Penempatan dialokasikan ke Puslitbang berdasarkan pertimbangan operasional BLKA"*).
  - Klik **"Simpan Pemindahan"**. Status pendaftar berubah menjadi **DITETAPKAN (DIPINDAHKAN)**. System backend secara otomatis menyesuaikan sisa kuota unit asal dan unit baru.
- **Tolak Pengajuan**: Menolak pengajuan mahasiswa $\rightarrow$ Status berubah menjadi **DITOLAK**.

#### **3. Floating Bulk Toolbar (Aksi Masal):**
- Centang beberapa mahasiswa pada tabel menggunakan checkbox.
- Gunakan tombol aksi masal pada toolbar yang muncul:
  - **Setujui Terpilih**: Mengubah status seluruh mahasiswa terpilih menjadi *Diterima*.
  - **Pindahkan Terpilih**: Memindahkan seluruh mahasiswa terpilih secara bersamaan ke unit tujuan baru.
  - **Tolak Terpilih**: Mengubah status seluruh mahasiswa terpilih menjadi *Ditolak*.
- **Export Excel Penetapan**: Mengunduh rekapitulasi penetapan final per periode dalam format `.xlsx`.

---

### **4.7. Modul Histori Periode & Laporan Detail (Export Excel)**
Diakses melalui menu **"Histori Pendaftar"** (`/admin/histori.html`).

#### **Fitur & Langkah Kerja:**
1. Menampilkan arsip lengkap seluruh gelombang/periode magang terdahulu.
2. Klik tombol **"Lihat Detail & Laporan"** pada salah satu periode untuk membuka halaman `/admin/histori-detail.html`.
3. Menampilkan ringkasan statistik penetapan, grafik sebaran, serta tabel pendaftar final.
4. Klik tombol **"Export Excel Histori"** untuk mendownload laporan archive `.xlsx`.

---

### **4.8. Modul Pengaturan Sistem & Pengelolaan Hak Akses Admin (Multi-Admin)**
Diakses melalui menu **"Pengaturan"** (`/admin/pengaturan.html`) & **"Kelola Hak Akses Admin"** (`/admin/kelola-admin.html`).

*Catatan: Modul Kelola Hak Akses Admin khusus diakses oleh **Super Admin**.*

#### **1. Konfigurasi Syarat Program Magang 5 Bulan (KRS):**
- Mengatur Nilai **Minimal IPK** (misal: `3.00`).
- Mengatur **Minimal SKS yang Sudah Ditempuh** (misal: `110`).
- Klik **"Simpan Pengaturan"**. Syarat ini menjadi validator otomatis saat mahasiswa memilih Program 5 Bulan KRS.

#### **2. Pengelolaan Hak Akses Admin Baru (Multi-Admin System):**
- Masuk ke halaman `/admin/kelola-admin.html`.
- **Form Tambah Admin Baru (via Email Kampus)**:
  - Masukkan **Email Kampus (@itpln.ac.id)** calon admin.
  - Masukkan **Nama Lengkap & Gelar**.
  - Pilih **Role Akses**:
    - **Admin BLKA**: Dapat mengelola periode, unit, data pendaftar, dan penetapan unit.
    - **Super Admin**: Memiliki seluruh akses Admin BLKA + Pengaturan Syarat KRS + Kelola Hak Akses Admin.
  - Klik **"+ Tambah"**.
- **Mencabut Hak Akses Admin**:
  - Pada tabel Daftar Admin & Super Admin Aktif, klik tombol merah **"Cabut Akses"** pada baris pengelola yang ingin dinonaktifkan.
  - Hak akses pengguna tersebut akan langsung dinonaktifkan (`aktif = 0`).

---

## 5. PANDUAN TROUBLESHOOTING, OPERASIONAL, & SERAH TERIMA JABATAN

Dokumen ini disusun untuk memudahkan kontinuitas operasional BLKA ITPLN saat terjadi pergantian pimpinan, pengelola, atau staf administrasi.

### **Prosedur Serah Terima Pengelola Baru:**
1. **Pemberian Hak Akses Super Admin**: Super Admin lama mendaftarkan Email Kampus ITPLN pengelola baru melalui modul **Kelola Hak Akses Admin** (`/admin/kelola-admin.html`).
2. **Pemeriksaan Periode Aktif**: Memastikan periode magang berjalan telah memiliki tanggal mulai/selesai yang tepat dan status `dibuka`.
3. **Pemeriksaan Kuota Unit PLN**: Mengatur kuota alokasi unit pelaksana PLN pada menu *Unit Pelaksana -> Kuota Periode Aktif*.

### **Solusi Masalah Umum (Troubleshooting):**
- **Kendala: Admin Baru Tidak Bisa Login (403 Forbidden / Akses Ditolak)**
  - *Penyebab*: Email kampus pengelola belum terdaftar di tabel `admin` atau statusnya nonaktif (`aktif = 0`).
  - *Solusi*: Minta Super Admin aktif mendaftarkan email kampus tersebut via menu *Pengaturan -> Kelola Hak Akses Admin*.
- **Kendala: Mahasiswa Tidak Bisa Mendaftar Program 5 Bulan KRS**
  - *Penyebab*: IPK atau total SKS mahasiswa di bawah batas minimal yang diatur pada menu Pengaturan.
  - *Solusi*: Mahasiswa dapat mendaftar Program 1 Bulan atau memperbarui nilai akademik pada sistem semester berikutnya.
- **Kendala: Kuota Unit PLN Terkunci / Tidak Berkurang**
  - *Penyebab*: Terdapat mahasiswa yang sedang melakukan reservasi kuota temporer (berdurasi 5 menit).
  - *Solusi*: Tunggu 5 menit hingga masa reservasi mahasiswa berakhir atau pendaftaran disubmit.

---
*Buku Panduan ini berlaku secara resmi untuk Sistem Informasi Magang BLKA ITPLN × PT PLN (Persero).*

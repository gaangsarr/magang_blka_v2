# Panduan Operasional Super Admin

## INTERN ITPLN: Sistem Magang Terpadu Institut Teknologi PLN dan PT PLN (Persero)

---

## 1. Tentang Panduan Ini

Dokumen ini disusun sebagai panduan operasional komprehensif bagi pemegang role **Super Admin** pada aplikasi **INTERN ITPLN**. Super Admin memegang otoritas tata kelola tertinggi, mencakup konfigurasi sistem, penetapan kuota dan seleksi pendaftar, penerbitan dokumen resmi, hingga manajemen akun administrator seluruh level.

Penanda seperti **[Gambar 1: ...]** disiapkan sebagai panduan penempatan tangkapan layar antarmuka saat dokumen ini dikonversi ke format Microsoft Word atau buku cetak resmi.

---

## 2. Mengenal Hak Akses: Super Admin vs Admin BLKA

Aplikasi INTERN ITPLN membedakan peran administrator kampus ke dalam dua tingkatan utama:

| Aspek                        | Super Admin                                                                                                | Admin BLKA                                                                      |
| ---------------------------- | ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| **Cakupan Akses**            | Seluruh 15 modul tanpa batasan                                                                             | 13 modul proses bisnis harian                                                   |
| **Proses Seleksi & Periode** | Akses penuh (Periode, Kuota, Pendaftar, Penetapan, Pemindahan, Surat)                                      | Akses penuh (menjalankan tugas operasional seleksi yang sama)                   |
| **Master Data & Hierarki**   | Akses penuh (Prodi, Peminatan, PIC Narahubung, Hierarki Unit)                                              | Akses penuh                                                                     |
| **Pengaturan Sistem**        | **Penuh** (Syarat IPK/SKS 5 Bulan, Berkas Panduan/Log Book, Saklar Notifikasi Email Otomatis, Ganti Sandi) | **Terkunci** (Hanya melihat tampilan terbatas tanpa kartu konfigurasi sensitif) |
| **Kelola Admin Multi-Role**  | **Penuh** (Buat/reset akun PLN, tambah/cabut Admin BLKA, buat/reset akun Admin Prodi, Reset PIC darurat)   | **Terkunci** (Menu tidak tampil di bilah navigasi)                              |

Dalam skema operasional, Admin BLKA sudah mencukupi untuk menangani seluruh alur kerja seleksi dan pelayanan mahasiswa harian. Super Admin berperan sebagai penanggung jawab kebijakan, pengendali parameter teknis, dan pengelola otoritas akun.

---

## 3. Akses Masuk dan Autentikasi

### 3.1 Login via Single Sign-On (SSO) Akun Kampus

Super Admin masuk ke aplikasi menggunakan akun resmi Microsoft Kampus ITPLN (`@itpln.ac.id`). Email Anda harus sudah terdaftar pada basis data admin dengan peran `super_admin`.

Langkah login:

1. Buka peramban web (Google Chrome, Microsoft Edge, atau Mozilla Firefox versi terbaru).
2. Akses alamat resmi: **https://intern.itpln.ac.id/admin/login.html**
3. Klik tombol **Masuk via Akun Kampus (@itpln.ac.id)** yang berlogo Microsoft.
4. Anda akan diarahkan ke halaman login resmi Microsoft. Masukkan email kampus dan kata sandi akun ITPLN Anda.
5. Lakukan verifikasi keamanan dua langkah (jika diaktifkan oleh kampus).
6. Setelah autentikasi berhasil, sistem akan mendeteksi peran Anda dan langsung membuka Dashboard Super Admin.

**[Gambar 1: Halaman Login Admin dengan Tombol Masuk SSO Akun Kampus]**

---

## 4. Alur Kerja Operasional Magang (SOP 4 Fase)

Setiap gelombang program magang di ITPLN dijalankan melalui siklus empat fase terstruktur:

```
[FASE 1: PRA-PENDAFTARAN]
- Memeriksa Master Data (Prodi, Peminatan, Hierarki Unit, PIC Narahubung)
- Buat Periode Baru dengan status default: Persiapan
- Admin PLN melakukan konfigurasi kesediaan kuota unit
             │
             ▼
[FASE 2: PENDAFTARAN AKTIF]
- Ubah status periode menjadi: Dibuka
- Mahasiswa mendaftar dan memilih unit PLN
- Monitor volume pendaftar secara live
             │
             ▼
[FASE 3: SELEKSI & PENETAPAN UNIT]
- Periode otomatis / manual berubah menjadi: Ditutup
- Seleksi berkas: Terima, Pindahkan, atau Tolak
- Tangani permohonan pemindahan antar-unit PLN (Monitoring Pemindahan)
             │
             ▼
[FASE 4: ADMINISTRASI SURAT & PENGUMUMAN]
- Konfigurasi nomor pada modul Surat Pengantar Mahasiswa (Syarat Mutlak)
- Hasilkan dan unduh Surat Penempatan per Unit PLN (DOCX/ZIP)
- Buka Publikasi Pengumuman Mahasiswa
- Ekspor rekapitulasi akhir ke Excel
```

---

## 5. Panduan Teknis Mendalam Modul Operasional

### 5.1 Modul 1: Dashboard Utama

Dashboard berfungsi sebagai pusat pemantauan ringkas terhadap aktivitas sistem yang sedang berjalan.

Elemen pada Dashboard:

- **Bilah Header Administrator:** Menampilkan nama pengguna, peran (_Super Admin BLKA_), avatar inisial, dan pintasan profil.
- **Kartu Ringkasan Aktivitas:** Menampilkan angka total mahasiswa pendaftar pada periode aktif, total unit PLN yang membuka kuota, jumlah berkas menunggu verifikasi, serta persentase keterisian kuota nasional.
- **Grafik Tren Pendaftaran:** Memvisualisasikan laju pendaftaran mahasiswa dari hari ke hari.

**[Gambar 2: Dashboard Utama Super Admin]**

---

### 5.2 Modul 2: Periode Magang

Modul ini adalah pengendali utama kalender kegiatan magang. Seluruh validasi form pendaftaran mahasiswa dan pembatasan form kuota unit PLN bergantung pada status periode di modul ini.

**[Gambar 3: Tabel Daftar Periode Magang]**

#### A. Membuat Periode Baru

1. Klik tombol **+ Buat Periode Baru** di kanan atas tabel.
2. Isi formulir pembuatan periode:
   - **Nama Periode:** Masukkan nama resmi periode (contoh: _Semester Ganjil 2026/2027_).
   - **Tanggal Mulai dan Tanggal Selesai:** Tentukan rentang kalender aktif.
   - **Jam Tutup (WIB):** Atur jam dan menit batas akhir pendaftaran dalam format 24 jam (default: 23:59 WIB). Sistem akan otomatis menutup pendaftaran pada waktu ini.
   - **Angkatan Mahasiswa:** Centang tahun angkatan yang diizinkan mendaftar. Jika angkatan belum ada dalam pilihan, ketik tahun pada kolom tambah angkatan (misal: _2027_) lalu klik **+ Tambah Angkatan**.
   - **Program yang Tersedia:** Pilih salah satu durasi magang:
     - Magang 1 Bulan
     - Magang 3 Bulan
     - Magang 4 Bulan
     - Magang 5 Bulan (KRS)
   - **Syarat Berkas yang Wajib Diunggah:** Centang berkas PDF yang wajib diunggah mahasiswa:
     - Transkrip Nilai (Maksimal 512 KB)
     - Curriculum Vitae / CV (Maksimal 2 MB)
     - Portofolio (Maksimal 5 MB)
     - Surat Pernyataan / Komitmen (Maksimal 1 MB)
3. Klik **Simpan Periode**. Periode baru akan tercipta dengan status default **Persiapan**.

**[Gambar 4: Formulir Pembuatan Periode Baru]**

#### B. Memahami dan Mengubah Status Periode

Setiap periode melewati tiga status:

1. **Persiapan:**
   - Mahasiswa **belum bisa** mendaftar.
   - Admin Unit PLN mulai masuk ke portal untuk mengatur kesediaan menerima magang, menentukan skema kuota (Pool/Breakdown), memilih prodi, dan peminatan.
   - Seluruh konfigurasi unit masih bebas disunting atau dihapus.
2. **Dibuka:**
   - Untuk mengaktifkan pendaftaran mahasiswa, Super Admin wajib **mengubah status secara manual** dari _Persiapan_ ke _Dibuka_ melalui tombol aksi status di tabel.
   - Mahasiswa yang memenuhi syarat angkatan dan program dapat melakukan pendaftaran.
   - Admin Unit PLN hanya diizinkan menambah kuota, prodi, atau peminatan. Mereka **tidak dapat mengurangi kuota atau menghapus prodi/peminatan** yang sudah disimpan saat fase persiapan.
3. **Ditutup:**
   - Pendaftaran mahasiswa otomatis ditutup saat waktu akhir terlampaui, atau dapat ditutup manual oleh Super Admin jika kuota nasional terpenuhi lebih cepat.
   - Mahasiswa tidak dapat lagi mengirimkan formulir baru.
   - Tahap seleksi berkas, verifikasi pendaftar, dan pemindahan dimulai penuh.

---

### 5.3 Modul 3: Kuota Unit Magang

Super Admin memiliki kewenangan mengatur dan mengoreksi kuota penerimaan seluruh unit PLN se-Indonesia. Tindakan ini dilakukan atas koordinasi dan persetujuan unit PLN terkait.

1. Buka menu **Kuota Unit Magang**.
2. Pilih periode aktif pada bilah filter.
3. Tabel menampilkan daftar seluruh unit kantor PLN, status kesediaan menerima, total kuota, skema kuota, dan daftar prodi yang dibuka.
4. Klik tombol **Atur Kuota** pada baris unit yang ingin dikonfigurasi.
5. Anda dapat mengaktifkan/menonaktifkan saklar penerimaan, mengganti mode antara **Kuota Gabungan (Pool)** atau **Kuota Rincian per Prodi (Breakdown)**, menambah jatah kuota per prodi, serta mencentang bidang peminatan unit tersebut.
6. Klik **Simpan Alokasi Kuota**.

**[Gambar 5: Halaman Pengaturan Kuota Unit Magang oleh Super Admin]**

---

### 5.4 Modul 4: Data Pendaftar

Modul ini difungsikan khusus untuk pengawasan dan penelusuran data calon peserta magang secara komprehensif tanpa risiko mengubah status kelulusan.

Fitur pada modul ini:

- **Pencarian Cepat:** Mencari data mahasiswa berdasarkan Nama, NIM, atau nomor kontak.
- **Filter Multi-Kriteria:** Menyaring mahasiswa berdasarkan status pendaftaran (_Diajukan, Diverifikasi, Diterima, Ditolak_), asal program studi, dan jenis program durasi.
- **Inspeksi Berkas:** Mengunduh dan memeriksa berkas pendaftaran (Transkrip, CV, Portofolio) langsung dari tabel.

> **Catatan Operasional:** Modul ini murni untuk monitoring data. Keputusan penerimaan, penolakan, dan pemindahan peserta dilakukan pada Modul Penetapan Unit.

**[Gambar 6: Tabel Data Pendaftar Mahasiswa]**

---

### 5.5 Modul 5: Penetapan Unit dan Publikasi Pengumuman

Modul ini adalah pusat eksekusi seleksi mahasiswa di tingkat administrator kampus. Di sini Super Admin memvalidasi penetapan mahasiswa atau mengambil alih pendaftar yang belum diverifikasi oleh unit PLN.

**[Gambar 7: Halaman Penetapan Unit dan Pengumuman]**

#### A. Aksi Keputusan Peserta

Setiap baris pendaftar memiliki tiga opsi keputusan:

1. **Terima (Setujui):** Menetapkan mahasiswa resmi diterima magang pada unit pilihan. Kuota unit berkurang satu.
2. **Pindahkan (Relokasi Pintar):** Memindahkan pendaftaran mahasiswa ke unit PLN lain yang lebih relevan.
   - Jendela pemindahan menampilkan profil mahasiswa, titik koordinat domisili, dan rekomendasi unit terdekat yang cocok jurusannya secara otomatis berdasarkan perhitungan jarak garis lurus.
   - Masukkan alasan pemindahan secara jelas.
3. **Tolak:** Menolak pendaftaran mahasiswa. Kuota unit otomatis dikembalikan (+1). Sistem akan mengirimkan notifikasi email otomatis jika saklar email penolakan aktif pada Pengaturan Sistem.

#### B. Aksi Massal (Bulk Action)

Jika terdapat puluhan berkas yang perlu diproses sekaligus:

1. Centang kotak pilihan pada mahasiswa yang dituju.
2. Bilah tindakan melayang (_sticky toolbar_) akan muncul di bagian atas layar.
3. Pilih salah satu aksi:
   - **Setujui Terpilih:** Menyetujui seluruh mahasiswa terpilih secara serentak.
   - **Pindahkan Terpilih:** Merelokasi rombongan mahasiswa ke satu unit tujuan yang sama.
   - **Tolak Terpilih:** Menolak seluruh pendaftar terpilih.

**[Gambar 8: Bilah Aksi Massal Penetapan Mahasiswa]**

#### C. Publikasi Pengumuman Kelulusan

Pada bagian atas halaman terdapat kartu kontrol **Akses Pengumuman Mahasiswa**.

Sebelum pengumuman dipublikasikan, status pendaftaran pada portal mahasiswa disamarkan menjadi _"Dalam Proses Seleksi"_.

**Syarat Mutlak Membuka Pengumuman:**

1. Seluruh pendaftar sudah memiliki status final (_Diterima, Dipindahkan, atau Ditolak_). Tidak boleh ada berkas yang masih menggantung atau berstatus diajukan.
2. **Nomor Pokok Surat Pengantar Mahasiswa sudah dikonfigurasi** pada modul _Surat Pengantar Mahasiswa_. Jika belum dikonfigurasi, sistem akan memblokir pembukaan pengumuman dan memunculkan peringatan kesalahan.

Cara membuka pengumuman:

1. Pastikan kedua syarat di atas terpenuhi.
2. Klik tombol **Publikasikan Sekarang**.
3. Konfirmasi pada kotak dialog peringatan.
4. Status banner berubah menjadi hijau (_SUDAH DIPUBLIKASIKAN_). Mahasiswa kini dapat melihat hasil resmi kelulusan, unit penempatan, serta kontak PIC narahubung area.

Jika di kemudian hari ditemukan kesalahan data yang fatal, Super Admin dapat mengklik tombol **Tutup / Sembunyikan** untuk menarik kembali pengumuman dari pandangan publik mahasiswa.

---

### 5.6 Modul 6: Monitoring Pemindahan

Modul ini dipakai untuk memantau permohonan pemindahan mahasiswa antar-unit PLN agar tidak ada berkas yang terhenti (_stuck_).

Status dalam alur pemindahan:

- **Menunggu Approval:** Unit asal telah mengajukan pemindahan, dan berkas sedang menunggu persetujuan dari unit tujuan.
- **Disetujui:** Unit tujuan menerima pemindahan tersebut.
- **Ditolak:** Unit tujuan menolak pemindahan, sehingga berkas mahasiswa otomatis kembali ke unit asal.
- **Ditetapkan BLKA (Force):** Administrator kampus mengambil tindakan intervensi langsung.

**Intervensi Administrator (Force Approval):**

Jika unit tujuan pemindahan tidak kunjung memberikan respon hingga mendekati batas akhir seleksi, Super Admin berhak melakukan intervensi:

1. Buka menu **Monitoring Pemindahan**.
2. Cari baris mahasiswa yang berstatus _Menunggu Approval_.
3. Klik tombol **Intervensi**.
4. Pilih tindakan persetujuan paksa (_Force Approve_) untuk menetapkan mahasiswa pada unit tersebut, atau batalkan pemindahan untuk mengembalikan ke unit asal.
5. Berikan catatan administratif intervensi, lalu klik **Konfirmasi Intervensi**.

**[Gambar 9: Tabel Monitoring Pemindahan dan Modal Intervensi Administrator]**

---

### 5.7 Modul 7: Hasilkan Surat

Modul ini digunakan setelah seluruh penetapan peserta selesai untuk memproduksi dokumen resmi Surat Pemberitahuan Penempatan Magang format Microsoft Word (`.docx`) untuk setiap kantor unit PLN Group, serta mengunduh paket arsip dalam format `.zip`.

**[Gambar 10: Halaman Hasilkan Surat Penempatan Unit]**

#### A. Konfigurasi Template Surat Per Periode

1. Pilih periode magang yang aktif pada bilah kendali periode.
2. Klik **Buka Formulir** pada bagian _Formulir Kustomisasi Dokumen Surat_.
3. Lengkapi parameter berikut:
   - **Format Template Nomor Surat:** Gunakan tag `{nomor}` untuk penomoran otomatis berkelanjutan (contoh: `{nomor}/Srt/1/D0/09/2026`), atau gunakan spasi kosong di depan jika nomor surat fisik akan diisi stempel pena manual (contoh: `      /Srt/1/D0/09/2026`).
   - **Nomor Awal / Mulai Dari:** Angka permulaan jika menggunakan tag `{nomor}` (default: 1).
   - **Tanggal Terbit Surat:** Masukkan tempat dan tanggal resmi (contoh: _Jakarta, 29 Agustus 2026_).
   - **Tahun Akademik:** Masukkan tahun ajaran program (contoh: _2026/2027_).
   - **Perihal Surat:** Isi pokok surat dinas (contoh: _Penyampaian Penempatan Mahasiswa Magang 5 Bulan PLN Group Tahun Ajaran 2026/2027_).
   - **Jabatan Penandatangan:** Masukkan jabatan pejabat kampus (contoh: _Wakil Rektor III Bidang Kemahasiswaan_).
   - **Nama & Gelar Penandatangan:** Nama lengkap pejabat kampus beserta gelar akademiknya.
   - **Daftar Tembusan Surat:** Ketik daftar tembusan, satu baris per instansi (contoh: _1. HTD PLN Kantor Pusat_).
   - **Tautan Aman Rekapitulasi Data Publik:** Sistem otomatis menghasilkan URL bertoken acak rahasia untuk disematkan di dalam surat (contoh: `https://intern.itpln.ac.id/penempatan.html?token=...`). Pejabat unit PLN dapat membuka tautan ini untuk mengunduh rekapitulasi data mahasiswa tanpa perlu login.
4. Klik **Simpan Konfigurasi Surat**.

#### B. Mengunduh Dokumen Surat

- **Unduh Surat Satuan:** Pada tabel daftar unit, klik tombol **Download (.docx)** pada baris kantor unit tertentu. Dokumen Word berisi surat pengantar resmi beserta lampiran tabel nama mahasiswa, NIM, prodi, dan peminatan langsung terunduh.
- **Unduh Massal (.ZIP):** Klik tombol hijau **Download Semua Surat (.ZIP)** di kanan atas. Sistem akan membundel seluruh dokumen surat untuk puluhan kantor unit ke dalam satu file arsip zip terkompresi.

---

### 5.8 Modul 8: Surat Pengantar Mahasiswa

Modul ini mengatur penerbitan berkas Surat Pengantar Mahasiswa resmi bertanda tangan digital yang dapat diunduh langsung oleh mahasiswa di portal akun mereka setelah dinyatakan lulus.

**Konfigurasi ini wajib diselesaikan sebelum pengumuman dibuka.**

Langkah pengaturan:

1. Buka menu **Surat Pengantar Mahasiswa**.
2. Pilih periode magang terkait.
3. Isi kolom pengaturan format:
   - **Nomor Pokok Surat (Wajib):** Masukkan nomor urut pokok surat (contoh: _0001_). Kolom ini tidak boleh kosong.
   - **Format Klasifikasi / Sufiks:** Standar klasifikasi naskah dinas kampus (default: `/Kt/2/D06`).
   - **Bulan Surat:** Dua digit angka bulan penerbitan (contoh: _10_ untuk Oktober).
   - **Tahun Surat:** Empat digit angka tahun (contoh: _2026_).
   - **Tanggal Terbit Surat:** Format teks tanggal (contoh: _Jakarta, 19 Oktober 2026_).
   - **Tahun Akademik & Perihal:** Sesuaikan tahun ajaran dan subjek surat pengantar.
   - **Data Pejabat Penandatangan:** Masukkan Jabatan, Nama Lengkap beserta Gelar, dan NIP/NIDN.
   - **Upload Tanda Tangan Pejabat:** Unggah file pindaian tanda tangan pejabat berlatar belakang putih bersih atau transparan (format PNG/JPG, maksimal 3 MB). File tanda tangan ini akan dicetak otomatis pada dokumen PDF surat pengantar mahasiswa.
4. Klik **Simpan Pengaturan Surat**.

**[Gambar 11: Formulir Pengaturan Surat Pengantar Mahasiswa dan Unggah Tanda Tangan]**

---

### 5.9 Modul 9: Master Data Program Studi

Modul ini mengelola program studi resmi ITPLN yang terdaftar di dalam sistem pendaftaran.

1. Buka menu **Master Program Studi**.
2. Untuk menambah program studi baru, klik tombol **+ Tambah Prodi**.
3. Isi kolom modal:
   - **Jenjang:** Pilih jenjang pendidikan (_S1, D3, D4, S2, S3, atau Profesi_).
   - **Kode Prodi (2 Digit):** Masukkan 2 digit angka kode prodi resmi (contoh: _31_ untuk S1 Teknik Informatika, _71_ untuk S1 Sistem Informasi). Angka ini wajib sesuai dengan digit ke-3 dan ke-4 pada format NIM mahasiswa ITPLN agar sistem dapat mendeteksi prodi mahasiswa secara otomatis saat login.
   - **Nama Program Studi:** Masukkan nama lengkap prodi.
   - **Status Program Studi:** Pilih _Aktif_ agar dapat dipilih pada periode magang, atau _Nonaktif_ jika prodi sudah ditutup/diarsipkan.
4. Klik **Simpan**.

**[Gambar 12: Formulir Tambah Program Studi Baru]**

---

### 5.10 Modul 10: Master Peminatan

Modul ini mengatur bidang keahlian atau divisi kerja yang tersedia di lingkungan unit PLN dan menghubungkannya dengan program studi yang relevan.

1. Buka menu **Master Peminatan**.
2. Klik tombol **+ Tambah Peminatan**.
3. Isi data formulir:
   - **Nama Peminatan:** Masukkan nama bidang penempatan (contoh: _Teknologi Informasi & Sistem_, _Operasi & Pemeliharaan Jaringan Distribusi_).
   - **Program Studi Terkait:** Centang satu atau beberapa prodi yang memiliki kompetensi sesuai dengan peminatan ini. Mahasiswa hanya dapat memilih peminatan yang terhubung dengan prodinya. Gunakan tombol _Pilih Semua_ atau _Kosongkan_ untuk mempercepat pemilihan.
   - **Deskripsi / Ruang Lingkup:** Rincian keahlian pendukung (contoh: _Software engineering, data analytics, jaringan, IT security_).
   - **Status:** Tentukan status _Aktif_ atau _Nonaktif_.
4. Klik **Simpan**.

**[Gambar 13: Formulir Tambah Peminatan dan Pemetaan Program Studi]**

---

### 5.11 Modul 11: Master PIC Narahubung

Modul ini mengatur data kontak penanggung jawab (_Person in Charge_) per Area HCBP (Human Capital Business Partner) dan Unit Induk PLN.

**Prinsip Pewarisan Kontak (Inheritance):**

Kontak PIC yang didaftarkan pada level Unit Induk (UID/UIW) akan secara otomatis diwariskan ke seluruh Unit Pelaksana (UP3/UPDL) dan Unit Layanan (ULP) di bawah naungannya. Saat mahasiswa dinyatakan diterima pada suatu UP3, sistem akan langsung menampilkan nomor kontak WhatsApp PIC area ini pada kartu pengumuman mahasiswa.

Langkah pengelolaan:

1. Buka menu **Master PIC Narahubung**.
2. Klik tombol **+ Tambah PIC**.
3. Isi parameter:
   - **Unit Induk / Entitas Perusahaan:** Pilih kantor Unit Induk, Holding, atau Anak Perusahaan yang menaungi wilayah kerja terkait.
   - **Area HCBP:** Masukkan nama area kerja SDM PLN (contoh: _HCBP Area 3_).
   - **Nama Lengkap PIC Narahubung:** Masukkan nama penanggung jawab resmi.
   - **Nomor WhatsApp / HP:** Masukkan nomor aktif berawalan angka 0 atau 62 (contoh: _082376376255_).
   - **Email:** Alamat surat elektronik resmi kantor.
   - **Keterangan Tambahan:** Informasi jam operasional atau arahan koordinasi peserta.
   - **Status:** Pilih _Aktif_.
4. Klik **Simpan**.
5. Untuk memeriksa unit mana saja yang menikmati kontak narahubung ini, klik tombol badge angka pada kolom **Unit Terhubung**. Jendela popup akan menampilkan seluruh daftar UP3 dan ULP yang mewarisi kontak tersebut.

**[Gambar 14: Tabel PIC Narahubung dan Modal Daftar Unit Terhubung]**

---

### 5.12 Modul 12: Hierarki Entitas PLN

Sistem mengorganisasikan seluruh struktur unit PT PLN (Persero) Group ke dalam 6 tingkatan hierarki berjenjang:

```
Tingkat 1: Holding (PLN Kantor Pusat)
    │
    ├── Tingkat 2: Subholding (mis. PLN Nusantara Power, PLN Indonesia Power, PLN Icon Plus)
    │
    ├── Tingkat 3: Anak Perusahaan (mis. PT Haleyora Power, PT PLN Nusa Daya)
    │
    └── Tingkat 4: Unit Induk / UID / UIW (mis. PLN UID Jawa Timur, PLN UID Sumatera Selatan)
            │
            └── Tingkat 5: Unit Pelaksana / UP3 / UPDL (mis. PLN UP3 Sidoarjo, PLN UP3 Bekasi)
                    │
                    └── Tingkat 6: Unit Layanan / ULP (mis. PLN ULP Krian, PLN ULP Rungkut)
```

Setiap tingkatan memiliki halaman manajemen masing-masing di bawah grup menu **Hierarki Entitas PLN** pada sidebar navigasi:

- `Holding (Pusat)`: Kantor pusat korporasi.
- `Subholding`: Entitas subholding bisnis energi dan utilitas.
- `Anak Perusahaan`: Afiliasi perseroan PLN.
- `Unit Induk (UID/UIW)`: Unit induk distribusi dan wilayah.
- `Unit Pelaksana (UP3/UPDL)`: Unit pelaksana pelayanan pelanggan dan unit pelaksana pendidikan.
- `Unit Layanan (ULP)`: Kantor unit tingkat kecamatan/pelayanan pelanggan langsung.

**Langkah Menambah Entitas Baru:**

1. Buka sub-menu hierarki yang sesuai dengan jenis unit kantor yang ingin dibuat.
2. Klik tombol **Tambah [Jenis Unit]** (contoh: _Tambah Unit Pelaksana_).
3. Isi formulir pembuatan entitas:
   - **Unit Induk / Parent Hierarki (Wajib):** Pilih entitas atasan yang menaunginya. Contoh: jika menambah _PLN UP3 SIDOARJO_, pilih parent _PLN Unit Induk Distribusi Jawa Timur_.
   - **Nama Resmi Entitas Unit:** Tuliskan nama lengkap kantor (contoh: _PLN UP3 Sidoarjo_).
   - **Singkatan / Alias:** Singkatan resmi kantor (contoh: _UP3 Sidoarjo_).
   - **Alamat Lengkap Kantor:** Alamat fisik gedung kantor unit.
   - **Titik Koordinat Peta (Latitude & Longitude):** Masukkan koordinat presisi kantor (contoh: Latitude _-7.4478_, Longitude _112.7183_). Titik ini krusial karena algoritma penempatan pendaftar menghitung jarak tempat tinggal mahasiswa ke titik GPS kantor ini.
4. Klik **Simpan**.
5. **Tindakan Wajib Lanjutan:** Setelah entitas unit berhasil dibuat pada menu hierarki, Super Admin **wajib membuka menu Kelola Admin** untuk membuatkan akun login unit tersebut agar unit dapat mengakses portal.

**[Gambar 15: Formulir Tambah Unit Pelaksana pada Hierarki Entitas PLN]**

---

### 5.13 Modul 13: Log Aktivitas dan Histori Periode

Modul ini mencatat seluruh rekam jejak audit sistem serta mengarsipkan data capaian magang dari periode-periode terdahulu.

Fitur utama:

- **Arsip Periode Terdahulu:** Setiap periode yang telah ditutup tersimpan rapi dalam bentuk kartu arsip.
- **Detail Analitik & Grafik:** Mengklik salah satu kartu periode akan membuka halaman detail analitik yang menampilkan:
  - Total pendaftar, jumlah peserta diterima, jumlah ditolak, dan jumlah dipindahkan.
  - Grafik lingkaran (_Pie Chart_) sebaran pendaftar per program studi.
- **Ekspor Excel Histori Pendaftaran:** Klik tombol hijau **Export Excel** di kanan atas untuk mengunduh seluruh basis data pendaftar pada periode tersebut dalam format spreadsheet `.xlsx` lengkap dengan biodata mahasiswa, IPK, SKS, unit penempatan, serta alasan seleksi.

**[Gambar 16: Halaman Detail Histori Periode dan Ekspor Excel]**

---

### 5.14 Modul 14: Kelola Hak Akses Admin (Multi-Admin)

_Modul eksklusif Super Admin._ Menu ini mengatur akun pengguna pengelola aplikasi yang dibagi ke dalam 3 tab:

**[Gambar 17: Antarmuka Kelola Hak Akses Multi-Admin]**

#### A. Tab 1: Akun Mitra Perusahaan / Unit PLN

Tab ini mengelola kredensial masuk untuk seluruh kantor unit PLN Group se-Indonesia.

Tabel menampilkan nama kantor, jenis unit, unit induk, username login, status akun, dan kontak PIC kantor.

Status akun unit PLN:

- **Sudah Memiliki Akun:** Akun sudah dibuat dan aktif.
- **Perlu Ganti Password (First-Login):** Akun sudah dibuat tetapi admin unit belum pernah login atau belum menyelesaikan penggantian kata sandi awal.
- **Belum Memiliki Akun:** Entitas unit baru ditambahkan di menu hierarki dan belum memiliki akun login.

**Tindakan pada Tab Perusahaan:**

1. **Buat Akun Satuan:** Klik tombol biru **Buat Akun** pada baris unit yang belum berakun. Sistem akan otomatis menetapkan username dan kata sandi sementara terstandar.
2. **Generate Akun Otomatis Massal:** Klik tombol **Generate Akun Otomatis** di bilah atas. Sistem akan mendeteksi seluruh unit yang belum memiliki akun, lalu membuatkan akun secara serentak dengan rumus kata sandi terstandar `PLN-{KODE}-2026`. Progress bar interaktif akan berjalan hingga seluruh akun selesai dibuat.
3. **Reset Sandi Unit:** Jika admin unit lupa kata sandi atau kredensialnya kedaluwarsa, klik tombol kuning **Reset Sandi**. Sistem akan memberikan kata sandi sementara baru dan mengembalikan status akun ke mode _Perlu First-Login_.
4. **Cetak PDF Kredensial Resmi:** Klik tombol **Cetak PDF Kredensial** untuk mencetak atau mengunduh surat resmi lampiran kredensial (Username & Password awal) per unit kantor yang dapat dibagikan secara resmi ke sekretariat PLN.
5. **Ekspor Excel:** Mengunduh seluruh daftar akun dan kontak PIC unit ke format Excel `.xlsx`.
6. **Reset Data PIC (Modal Bahaya Merah):** Tindakan pembersihan darurat jika kantor unit mengalami pergantian seluruh personil SDM atau pengisian data PIC salah:
   - Klik tombol merah **Reset PIC** pada baris unit yang bersangkutan.
   - Jendela bahaya berwarna merah akan muncul meminta konfirmasi penghapusan data nama, jabatan, nomor telepon, dan email PIC kantor tersebut.
   - Klik **Hapus & Reset PIC**. Data PIC akan dikosongkan dan unit kantor diwajibkan mengisi ulang profil PIC baru saat login berikutnya.

**[Gambar 18: Modal Merah Bahaya Reset Data PIC Kantor PLN]**

#### B. Tab 2: Admin Kampus (BLKA / Staf)

Tab ini digunakan untuk mengangkat staf kampus, dosen, atau tim BLKA menjadi administrator internal portal.

1. Buka sub-tab **Admin Kampus (BLKA / Staf)**.
2. Pada form bagian atas, masukkan:
   - **Email Kampus (@itpln.ac.id):** Email resmi akun ITPLN pengguna (contoh: *nama.staf@itpln.ac.id*).
   - **Nama Lengkap & Gelar:** Nama lengkap staf.
   - **Role Akses:** Pilih antara:
     - `Admin BLKA`: Diberikan kepada staf operasional yang menangani seleksi harian.
     - `Super Admin BLKA`: Diberikan kepada pimpinan atau penanggung jawab utama sistem.
3. Klik tombol **+ Tambah**.
4. Pengguna tersebut kini dapat langsung masuk ke Portal Admin menggunakan tombol SSO Akun Kampus pada halaman login tanpa memerlukan kata sandi terpisah.
5. **Mencabut Akses Admin:** Pada tabel daftar admin kampus aktif, klik tombol **Cabut Akses** untuk menghapus wewenang pengguna tersebut dari portal.

**[Gambar 19: Pengelolaan Admin Internal Kampus via SSO Email @itpln.ac.id]**

#### C. Tab 3: Admin Program Studi (Prodi)

Tab ini mengelola akun resmi bagi Kepala Program Studi (Kaprodi) atau staf administrasi fakultas untuk memantau data pendaftaran mahasiswa jurusannya masing-masing.

Tindakan:

- **Generate Semua Akun Prodi:** Klik tombol **Generate Semua Akun Prodi** untuk membuatkan username dan kata sandi otomatis bagi seluruh program studi yang terdaftar di master data.
- **Cetak Kredensial (PDF):** Mengunduh lembar resmi tanda terima akun login untuk diserahkan ke masing-masing sekretariat program studi.
- **Reset Sandi Prodi:** Mengatur ulang kata sandi akun program studi tertentu jika terjadi kendala akses.

**[Gambar 20: Tab Pengelolaan Akun Admin Program Studi]**

---

### 5.15 Modul 15: Pengaturan Sistem

_Modul eksklusif Super Admin._ Modul ini menyimpan konfigurasi global aplikasi.

**[Gambar 21: Antarmuka Pengaturan Sistem Global]**

#### A. Syarat Program Magang 5 Bulan (KRS)

Kartu ini mengatur ambang batas kelayakan akademik bagi mahasiswa yang mendaftar program Magang 5 Bulan. Program 1 bulan tidak memiliki batasan nilai.

- **Minimal IPK:** Atur standar minimal IPK mahasiswa (rentang 0.00 hingga 4.00, standar: _3.00_). Mahasiswa dengan IPK di bawah angka ini akan mendapati pilihan program 5 bulan terkunci otomatis pada formulir pendaftaran.
- **Minimal SKS yang Ditempuh:** Atur standar minimal SKS lulus (rentang 0 hingga 200, standar: _110_ SKS). Mahasiswa dengan beban SKS belum mencukupi tidak dapat memilih program 5 bulan.
- Klik **Simpan Pengaturan** untuk memberlakukan batas baru secara instan.

#### B. Dokumen & Panduan Magang (Beranda Mahasiswa)

Kartu ini mengelola file dokumen resmi yang tampil pada blok unduhan beranda publik mahasiswa:

1. **Buku Pedoman Magang** (Dokumen 1)
2. **Buku Catatan / Log Book Magang** (Dokumen 2)

Opsi penyediaan berkas:

- **Metode Unggah File:** Pilih radio button _Unggah File Baru_, pilih file PDF atau DOCX dari komputer (maksimal 10 MB), lalu klik **Unggah Sekarang**. Berkas otomatis tersimpan di server web dan siap diunduh publik.
- **Metode Tautan Eksternal Cloud (URL):** Pilih radio button _Tautan Eksternal Cloud_, masukkan URL berkas Google Drive / OneDrive kampus.
- **Kustomisasi Teks:** Buka menu rincian kustomisasi teks untuk mengubah Judul Kartu, Label Subjudul, Badge Format, Teks Tombol, dan Deskripsi kartu di beranda tanpa perlu menyentuh kodingan HTML.
- Klik **Simpan Pengaturan Dokumen**.

**[Gambar 22: Pengelolaan Berkas Panduan dan Format Log Book Mahasiswa]**

#### C. Pengaturan Pengiriman Email Notifikasi Otomatis

Kartu ini mengendalikan saklar pengiriman pesan email otomatis dari server kampus ke inbox email mahasiswa:

1. **Notifikasi Penolakan Berkas / Formasi (Wajib Aktif):**
   - Saklar ini **sangat disarankan selalu menyala**.
   - Saat status pendaftaran mahasiswa diubah menjadi _Ditolak_ pada modul penetapan, sistem langsung mengirimkan email pemberitahuan resmi ke inbox mahasiswa saat itu juga, tanpa perlu menunggu pengumuman massal dibuka.
   - Manfaat: Mahasiswa segera mengetahui kegagalan formasinya dan memiliki kesempatan mendaftar kembali ke unit PLN lain yang kuotanya masih tersedia sebelum periode ditutup.
2. **Notifikasi Pengumuman Hasil Seleksi & Penempatan (Opsional):**
   - Mengirimkan email massal kepada ribuan pendaftar saat tombol _Publikasikan Pengumuman_ ditekan.
   - Dapat dinonaktifkan untuk menjaga stabilitas antrean server email kampus pada periode dengan volume ribuan peserta. Jika nonaktif, mahasiswa tetap dapat melihat hasil kelulusan secara instan dengan membuka portal magang.
3. **Bukti Pendaftaran saat Mahasiswa Submit (Opsional):**
   - Mengirimkan email tanda terima registrasi pendaftaran setiap kali mahasiswa selesai mengirim formulir 5-step wizard.
   - Disarankan nonaktif saat hari pertama pendaftaran massal guna menghemat antrean pengiriman server web.

- Klik **Simpan Pengaturan Email**.

**[Gambar 23: Saklar Pengaturan Pengiriman Email Otomatis]**

#### D. Keamanan & Kata Sandi Akun Super Admin

Kartu ini digunakan untuk memperbarui kredensial kata sandi akun Super Admin internal.

1. Klik tombol **Ganti Password** pada kartu keamanan.
2. Jendela modal akan terbuka.
3. Masukkan **Kata Sandi Saat Ini**, ketik **Kata Sandi Baru** (minimal 8 karakter dengan kombinasi huruf dan angka), lalu konfirmasi ulang pada **Ulangi Kata Sandi Baru**.
4. Klik **Perbarui Kata Sandi**.

**[Gambar 24: Modal Pembaruan Kata Sandi Akun Super Admin]**

---

## 6. Tabel Matriks Otoritas Tindakan

Tabel di bawah ini merangkum hak wewenang Super Admin pada kondisi status periode yang berbeda:

| Tindakan Operasional                               | Periode: Persiapan | Periode: Dibuka      | Periode: Ditutup         | Pasca Pengumuman |
| -------------------------------------------------- | ------------------ | -------------------- | ------------------------ | ---------------- |
| Mengubah Tanggal & Jam Tutup Periode               | Boleh              | Boleh                | Boleh                    | Boleh            |
| Menambah Kuota Unit PLN                            | Boleh              | Boleh                | Boleh                    | Tidak Disarankan |
| Mengurangi Kuota Unit PLN                          | Boleh              | Tidak Boleh          | Tidak Boleh              | Tidak Boleh      |
| Mengubah Mode Kuota (Pool / Breakdown)             | Boleh              | Tidak Boleh          | Tidak Boleh              | Tidak Boleh      |
| Menambah / Mengarsipkan Prodi & Peminatan          | Boleh              | Boleh (Hanya Tambah) | Boleh                    | Boleh            |
| Memutuskan Kelulusan Peserta (Terima/Pindah/Tolak) | Tidak Boleh        | Boleh                | Boleh                    | Terkunci         |
| Intervensi Pemindahan (Force Approval BLKA)        | Tidak Boleh        | Boleh                | Boleh                    | Terkunci         |
| Konfigurasi Surat Pengantar Mahasiswa              | Boleh              | Boleh                | **Wajib Selesai**        | Boleh            |
| Hasilkan & Download Surat DOCX / ZIP Unit          | Belum Siap         | Belum Siap           | Boleh                    | Boleh            |
| Membuka Akses Publikasi Pengumuman                 | Terkunci           | Terkunci             | Boleh (Syarat Terpenuhi) | Sudah Aktif      |
| Menarik / Menyembunyikan Pengumuman                | -                  | -                    | -                        | Boleh (Darurat)  |
| Menambah Admin BLKA / Super Admin Baru             | Boleh              | Boleh                | Boleh                    | Boleh            |
| Reset Password & Reset PIC Kantor Unit PLN         | Boleh              | Boleh                | Boleh                    | Boleh            |
| Mengubah Syarat IPK / SKS Program 5 Bulan          | Boleh              | Tidak Disarankan     | Terkunci                 | Terkunci         |

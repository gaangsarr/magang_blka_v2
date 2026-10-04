# INTERN ITPLN Design System & Visual Specification (`DESIGN.md`)
> **Single Source of Truth untuk Desain Antarmuka INTERN ITPLN — Admin PLN & Portal Mitra Perusahaan**  
> Mengikuti standar kualitas **antislop** (Anti-AI Slop Enterprise Architecture).

---

## 1. Filosofi & Karakter Desain

Desain ini ditujukan untuk **jajaran pimpinan, staf SDM (BLKA ITPLN), dan Admin Unit PLN se-Indonesia** (Holding, Subholding, UID, UP3, hingga ULP).
Tujuan utama visual: **Memberikan impresi kelas enterprise B2B** yang sangat bersih, terstruktur, padat data (*high data density*), fungsional, dan bebas dari ornamen murahan (*anti-slop*).

- **Clean & Purposeful**: Setiap piksel, border, dan tombol memiliki fungsi nyata. Tidak ada efek dekoratif tanpa makna (tidak ada blob gradient kabur, tidak ada fake badge, tidak ada animasi berlebihan).
- **High Information Density**: Menampilkan data kandidat magang, kuota unit, dan histori persetujuan dengan ringkas, tabel terstruktur, dan angka tabulasi (*tabular numerals*).
- **Phase-Driven Journey**: Menghilangkan kebingungan navigasi dengan memandu admin melalui tahapan siklus magang yang sedang aktif.

---

## 2. Palet Warna (Color Tokens)

### Brand & Corporate Palette
| Token Name | Hex Code | Deskripsi & Peruntukan |
| :--- | :--- | :--- |
| `--color-brand-navy` | `#0B192C` | Deep Corporate Navy — Sidebar gelap, header eksekutif, elemen struktural utama |
| `--color-brand-cyan` | `#00A2B9` | **PLN Electric Cyan** — Aksen utama, link aktif, indikator fokus, tombol primer |
| `--color-brand-cyan-hover` | `#008C9E` | State hover aksen cyan |
| `--color-brand-blue` | `#005082` | Biru Korporat BUMN — Header kartu sekunder, header modal |

### Surface & Neutral (Slate Scale)
| Token Name | Hex Code | Deskripsi & Peruntukan |
| :--- | :--- | :--- |
| `--color-canvas-bg` | `#F8FAFC` | Latar belakang halaman (Slate-50) — Bersih, sejuk, mengurangi kelelahan mata |
| `--color-surface-white` | `#FFFFFF` | Permukaan kartu (*card*), modal, dan sel tabel |
| `--color-border-subtle` | `#E2E8F0` | Border pemisah tabel, kartu, dan pembagi baris |
| `--color-border-strong` | `#CBD5E1` | Border input form dan komponen kontrol |
| `--color-text-main` | `#0F172A` | Teks utama, judul, data angka (Slate-900) |
| `--color-text-muted` | `#64748b` | Teks sekunder, metadata, label form (Slate-500) |
| `--color-text-light` | `#94A3B8` | Placeholder dan teks helper non-kritis (Slate-400) |

### Semantic State Tokens (Status Seleksi & Indikator)
| Token Name | Hex Code | Penggunaan |
| :--- | :--- | :--- |
| `--color-state-success` | `#10B981` | Diterima / Lolos Penetapan / Kuota Terpenuhi |
| `--color-state-warning` | `#F59E0B` | Dalam Review / Menunggu Persetujuan / Permintaan Pemindahan |
| `--color-state-danger` | `#EF4444` | Ditolak / Kuota Penuh / Berkas Tidak Memenuhi Syarat |
| `--color-state-info` | `#0284C7` | Periode Pendaftaran Aktif / Informasi Sistem |

---

## 3. Tipografi (Typography Hierarchy)

- **Primary Font**: `Plus Jakarta Sans`, `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`.
- **Angka & Data Monospace**: Gunakan `font-variant-numeric: tabular-nums;` untuk semua data kuota, jumlah mahasiswa, tanggal, dan NIM agar angka tersusun rapi secara vertikal dalam tabel.

### Skala Tipografi:
- **Display / Page Title**: `1.25rem` (20px) — `font-weight: 800; letter-spacing: -0.02em;`
- **Section / Card Header**: `0.975rem` (15.6px) — `font-weight: 700;`
- **Body Regular**: `0.875rem` (14px) — `line-height: 1.5; color: var(--color-text-main);`
- **Compact Table Cell**: `0.8125rem` (13px) — `line-height: 1.4;`
- **Badge / Small Metadata**: `0.75rem` (12px) — `font-weight: 700; letter-spacing: 0.02em;`

---

## 4. Alur Kerja Admin PLN (Phase-Driven User Journey)

Admin Unit PLN di lapangan tidak bekerja secara acak, melainkan mengikuti **3 Siklus Utama Magang**:

```
┌─────────────────────────┐      ┌─────────────────────────┐      ┌─────────────────────────┐
│         FASE 1          │  ──► │         FASE 2          │  ──► │         FASE 3          │
│   Setup Kuota & Profil  │      │  Verifikasi & Seleksi   │      │ Finalisasi & Pelaksanaan│
│  (Pra-Pendaftaran)      │      │  (Pendaftaran Aktif)    │      │  (Pasca-Penetapan)      │
└─────────────────────────┘      └─────────────────────────┘      └─────────────────────────┘
```

### 1. Fase 1: Setup Kuota & Profil Unit (Pra-Pendaftaran)
- **Tujuan**: Memastikan unit siap menerima mahasiswa dengan kuota dan kriteria jurusan yang valid.
- **Komponen Utama**:
  - *Setup Checklist Card*: Status kelengkapan profil PIC & konfirmasi kuota total.
  - *Alokasi Jurusan*: Form pemilihan jurusan yang dibutuhkan beserta batasan kuota.

### 2. Fase 2: Verifikasi & Seleksi Kandidat (Pendaftaran Aktif)
- **Tujuan**: Memeriksa berkas mahasiswa yang melamar ke unit tersebut secara cepat dan efisien.
- **Komponen Utama**:
  - *Action Queue Banner*: Menampilkan jumlah berkas yang menunggu review segera.
  - *Compact Verification Table*: Checkbox seleksi massal, tombol Setujui / Rekomendasi Pindah Unit / Tolak.
  - *Quick Drawer / Modal Preview*: Memeriksa CV, transkrip, dan surat rekomendasi tanpa meninggalkan konteks halaman.

### 3. Fase 3: Finalisasi & Pelaksanaan Magang (Pasca-Penetapan)
- **Tujuan**: Mengelola daftar mahasiswa yang telah sah ditetapkan oleh BLKA ITPLN.
- **Komponen Utama**:
  - *Daftar Mahasiswa Ditetapkan*: Data kontak, prodi, periode magang aktif.
  - *Export Roster*: Cetak daftar hadir dan dokumen penetapan ke format Excel/PDF.

---

## 5. Komponen Kunci & Aturan Anti-Slop (Rules of Craft)

1. **Tombol (Buttons)**:
   - Primer: Latar belakang Cyan `#00A2B9`, teks putih, font-weight 700, padding vertikal 8-10px, radius 8px. Hover: brightness/elevate tipis 1px.
   - Sekunder: Outline `#CBD5E1`, latar putih, teks `#334155`.
   - Hindari tombol dengan gradien pelangi atau shadow berukuran raksasa.
2. **Kartu Metrik (KPI Cards)**:
   - Harus menampilkan angka nyata, label jelas, dan perbandingan kontekstual (misal: "12 / 15 Kuota Terisi").
   - Dilarang keras menggunakan angka persentase fiktif seperti *"+999% Synergy"*.
3. **Data Tables**:
   - Header tabel tegas dengan latar abu-abu netral (`#F1F5F9`), teks huruf kapital kecil berbobot 700 (`#475569`).
   - Baris tabel berdensitas tinggi (`padding: 10px 14px`), zebra striping halus atau border-bottom `1px solid #E2E8F0`.
   - Baris dapat di-hover untuk mempermudah membaca data panjang.
4. **Status Badges**:
   - Menggunakan format Pill (`border-radius: 999px`), padding `3px 8px`, kontras teks tinggi terhadap latar belakang pastelnya.

---

## 6. Delivery Gate Checklist (Pra-Rilis)

Sebelum fitur atau halaman dinyatakan selesai:
- [ ] **WCAG AA Contrast**: Semua teks utama memiliki kontras minimal 4.5:1 terhadap background.
- [ ] **Tabular Numerals**: Semua data kuota, NIM, dan tanggal menggunakan font tabular.
- [ ] **Responsive Reflow**: Antarmuka tetap fungsional di layar 1366x768 (laptop standar kantor PLN) dan tablet/mobile.
- [ ] **Zero AI Tropes**: Tidak ada teks berlebihan (*"Unlock the power of..."*), tidak ada logo sparkle palsu, tidak ada fake latency counters.

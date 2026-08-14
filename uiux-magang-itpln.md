# Panduan UI/UX — Sistem Pendaftaran Magang ITPLN x PT PLN Persero

Dokumen ini arah visual dan bahasa antarmuka, ditujukan untuk developer/desainer yang implementasi. Bukan cuma daftar warna, tapi alasan kenapa pilihan ini cocok untuk konteksnya: platform resmi kampus teknik yang terhubung ke BUMN kelistrikan, dipakai mahasiswa dan staf administrasi — bukan landing page startup.

## Kenapa bukan gaya default AI

Tiga pola yang paling sering muncul kalau desain dibuat asal cepat: (1) latar krem hangat dengan aksen terracotta, (2) latar nyaris hitam dengan satu aksen hijau/merah neon menyala, (3) layout ala koran dengan garis tipis dan kolom padat. Ketiganya bisa valid untuk brief lain, tapi untuk platform institusional yang menyandang nama PLN dan kampus, ketiganya terasa salah tempat — kesannya seperti produk konsumer, bukan layanan resmi. Kita ambil arah lain: identitas visual yang terasa seperti alat ukur teknik — presisi, tenang, dan jujur soal data.

## Palet Warna

| Nama | Hex | Peran |
|---|---|---|
| Kertas | `#F7F8FA` | Latar utama — putih kebiruan dingin, bukan krem hangat |
| Tinta | `#161C26` | Teks utama dan elemen struktural gelap |
| Biru Grid | `#0B3D6B` | Aksen utama — tombol primer, tautan aktif, header institusional |
| Kuning Sinyal | `#C97A0A` | Peringatan halus — kuota menipis, status "hampir penuh" |
| Hijau Tersedia | `#1F6B4E` | Status positif — kuota tersedia, pendaftaran terverifikasi |
| Abu Garis | `#CBD1D9` | Pembatas, border tabel, elemen non-aktif |

Aksen merah dihindari kecuali untuk error murni (mis. `#A32A1F`, dipakai sesempit mungkin) — biar tidak bentrok makna dengan Kuning Sinyal yang punya arti berbeda (perhatian, bukan kesalahan).

## Tipografi

- **Display/Judul:** Space Grotesk — geometris dengan karakter teknis, cocok buat judul tahap form dan heading dashboard, dipakai dengan berat sedang (medium/semibold), bukan bold tebal berlebihan.
- **Body:** IBM Plex Sans — sangat terbaca di ukuran kecil, punya nuansa institusional/teknis tanpa terasa dingin seperti Inter yang dipakai di mana-mana.
- **Data & Kode:** IBM Plex Mono — khusus untuk NIM, ID reservasi, angka kuota, dan koordinat lat/long. Ini yang bikin angka-angka penting terasa seperti pembacaan alat ukur, bukan teks biasa yang kebetulan berupa angka.

Ukuran heading jangan seragam antar halaman form — halaman "Data Diri" dan halaman "Resume" punya bobot informasi beda, jadi skala tipenya boleh beda juga, jangan dipaksa template yang sama persis.

## Prinsip Layout

**Form mahasiswa (wizard bertahap).** Progress indicator di bagian atas ditulis sebagai label tahap, bukan lingkaran angka 1-2-3-4 generik: "TAHAP A — DATA DIRI", "TAHAP B — DOMISILI", "TAHAP C — PEMILIHAN UNIT", "TAHAP D — RESUME". Ini masuk akal dipakai di sini karena urutannya memang benar-benar berurutan dan tidak bisa dilompati, beda dengan kasus di mana penomoran cuma dekorasi.

**Tabel unit pelaksana.** Ini elemen paling sering dilihat mahasiswa, jangan dibuat seperti kartu produk e-commerce. Gunakan tabel datar dengan garis pembatas tipis (Abu Garis), kolom kuota ditampilkan sebagai pecahan bergaya meteran — `03 / 10` dalam IBM Plex Mono — bukan progress bar warna-warni. Baris yang kuotanya penuh diberi warna teks pudar dan tidak bisa diklik, bukan disembunyikan; mahasiswa perlu tahu unit itu ada tapi penuh, bukan cuma hilang dari daftar.

**Peta domisili & radius.** Latar peta dibuat dengan estetika peta survei teknik: garis grid tipis, radius ditampilkan sebagai lingkaran putus-putus, warna aksen Biru Grid untuk titik domisili dan Hijau Tersedia untuk unit pelaksana dalam radius terdekat. Hindari pin marker default merah tetesan air (default Google Maps) yang bikin tampilan terasa seperti embed generik.

**Dashboard admin.** Data-dense, bukan kartu-kartu dengan bayangan lembut dan gradient. Angka kuota total, sisa, dan terpakai ditampilkan berdampingan dalam satu baris tabel ringkas per unit, bukan tersebar di kotak-kotak besar terpisah. Warna cuma dipakai untuk membawa makna status (Hijau Tersedia / Kuning Sinyal / abu untuk penuh), bukan dekorasi.

## Elemen Signature

Angka kuota yang ditampilkan format meteran (`03 / 10`, monospace, sejajar kanan di kolomnya) jadi elemen yang konsisten muncul di tabel mahasiswa maupun dashboard admin. Begitu orang melihat pola ini sekali, mereka langsung mengenali "ini status ketersediaan" di mana pun formatnya muncul di seluruh sistem — itu yang bikin produk ini terasa dirancang, bukan ditempel dari komponen generik.

## Bahasa Antarmuka (Microcopy)

Nol emoji di seluruh antarmuka, tanpa pengecualian. Ini platform yang menyandang nama BUMN dan institusi pendidikan, bukan aplikasi konsumer.

Tombol menyebut persis apa yang terjadi, bukan kata generik:

| Salah (generik) | Benar (spesifik) |
|---|---|
| "Submit" | "Kirim Pendaftaran" |
| "Next" | "Lanjut ke Domisili" |
| "OK" pada dialog konfirmasi kuota habis | "Pilih Unit Lain" |
| "Success!" | "Pendaftaran Tersimpan" |

Aturan lain:
- Satu tanda seru maksimum di seluruh aplikasi, itu pun kalau benar-benar perlu. Default-nya nol.
- Pesan error bilang apa yang salah dan bagaimana memperbaikinya, tanpa nada minta maaf. "Kuota unit ini sudah habis. Pilih unit pelaksana lain dari tabel." — bukan "Oops! Sepertinya unit ini sudah penuh :(".
- Halaman kosong (misalnya belum ada pendaftar di suatu unit) jadi ajakan bertindak, bukan cuma pernyataan kosong: "Belum ada mahasiswa terdaftar di unit ini" ditambah tombol relevan kalau ada aksi yang bisa diambil, jangan cuma ilustrasi dekoratif tanpa fungsi.
- Nama aksi konsisten dari tombol sampai notifikasi — kalau tombolnya "Kirim Pendaftaran", notifikasi sukses bilang "Pendaftaran Terkirim", bukan tiba-tiba jadi "Data Berhasil Disimpan".

## Aksesibilitas & Detail yang Sering Kelupaan

- Fokus keyboard harus terlihat jelas di semua elemen interaktif (border Biru Grid 2px), penting karena form ini panjang dan sebagian mahasiswa akan navigasi pakai keyboard/screen reader.
- Kontras teks-latar minimal rasio 4.5:1 terutama untuk Kuning Sinyal di atas Kertas — kombinasi ini gampang gagal kontras kalau tidak dicek, uji dengan alat kontras sebelum dipakai di teks kecil.
- Hormati preferensi "reduced motion" — animasi transisi antar tahap form dimatikan otomatis kalau pengguna sudah set preferensi itu di sistem operasinya.
- Semua elemen wajib responsif sampai lebar layar HP, termasuk tabel unit pelaksana yang berpotensi lebar — pertimbangkan tampilan kartu ringkas khusus di layar sempit, bukan tabel yang di-scroll horizontal.

## Checklist Sebelum Dianggap Selesai

- [ ] Tidak ada emoji di label tombol, notifikasi, atau pesan error
- [ ] Tidak ada pin marker atau ikon peta bawaan library tanpa disesuaikan
- [ ] Semua angka kuota ditampilkan format meteran monospace, konsisten di semua halaman
- [ ] Tidak ada kartu dashboard dengan bayangan/gradient dekoratif tanpa makna status
- [ ] Nama tombol dan notifikasi konsisten dari awal sampai akhir satu alur

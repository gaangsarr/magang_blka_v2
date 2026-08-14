# Spec & Best Practice — Sistem Pendaftaran Magang ITPLN x PLN Persero

**Cara pakai dokumen ini:** ini bukan sekali baca lalu dibuang. Tempel bagian yang relevan ke prompt AI coding assistant (Claude Code, dsb) atau jadikan checklist review sebelum tiap periode dibuka. Asumsi stack: PHP native (tanpa framework) di backend, vanilla JS di frontend, MySQL (InnoDB) sebagai database. Kalau stack berubah, prinsipnya tetap sama, cuma implementasi teknisnya yang perlu disesuaikan.

---

## 1. Keamanan

### Autentikasi
Jangan percaya klaim domain email dari client sama sekali. Alurnya harus begini: mahasiswa login lewat Firebase Auth (Google/Microsoft provider), frontend dapat ID token, token itu dikirim ke backend PHP di setiap request yang butuh autentikasi, dan backend memverifikasi tandatangan token pakai public key Google (library seperti `kreait/firebase-tokens` bisa dipakai, atau verifikasi manual JWT lewat endpoint JWKS Google). Baru setelah signature valid, cek claim `email` dan pastikan domainnya `@itpln.ac.id`. Kalau tidak, tolak dan hapus sesi.

Simpan session identifier di cookie dengan flag `HttpOnly`, `Secure`, dan `SameSite=Strict`. Jangan simpan token Firebase di localStorage — itu rawan kebaca lewat XSS.

### Injeksi & Output
Semua query ke MySQL wajib pakai PDO prepared statement dengan parameter terikat. Tidak ada pengecualian, termasuk untuk query internal admin yang "keliatan aman".

```php
$stmt = $pdo->prepare("SELECT * FROM unit_pelaksana WHERE id = :id AND periode_id = :periode");
$stmt->execute(['id' => $unitId, 'periode' => $periodeAktif]);
```

Untuk output ke HTML, semua data dari user (nama, alamat, dsb) harus lewat `htmlspecialchars()` sebelum ditampilkan. Tambahkan header `Content-Security-Policy` yang membatasi sumber script cuma dari domain sendiri.

### CSRF
Form multi-step (data diri, domisili, pemilihan unit, submit) itu target empuk untuk CSRF kalau session cookie dipakai buat autentikasi. Setiap form action butuh token CSRF yang di-generate per sesi dan divalidasi di server sebelum memproses apa pun, termasuk endpoint "next" yang memicu pengurangan kuota.

### Rate limiting
Dua titik yang paling perlu dibatasi: endpoint login (cegah brute force) dan endpoint pemilihan unit pelaksana/submit (cegah spam klik yang bisa memicu race condition atau menghabiskan kuota secara tidak wajar). Kalau pakai Nginx di depan PHP-FPM, `limit_req_zone` per IP sudah cukup untuk level pertama. Untuk pembatasan per akun mahasiswa (bukan per IP, karena banyak mahasiswa bisa berbagi jaringan kampus yang sama), simpan hitungan percobaan di tabel atau Redis dengan window waktu, misal maksimal 10 percobaan pemilihan unit per menit per akun.

### Least privilege di database
Buat user MySQL terpisah untuk aplikasi web yang cuma punya hak `SELECT, INSERT, UPDATE` pada tabel yang relevan — tidak ada `DROP`, `ALTER`, atau `DELETE` di production. Migrasi skema pakai user database yang berbeda, yang cuma dipakai oleh pipeline CI/CD, bukan oleh aplikasi yang jalan sehari-hari.

### Data pribadi
NIM, IPK, no HP, dan alamat domisili adalah data pribadi yang tunduk ke UU PDP. Batasi akses tabel pendaftar cuma untuk role admin BLKA yang memang berwenang (bukan semua staf), catat setiap akses/ekspor data di log aktivitas, dan tentukan kebijakan retensi — misalnya data periode yang sudah lebih dari 2 tahun diarsipkan terpisah dengan akses lebih ketat, bukan dibiarkan menumpuk di tabel aktif.

---

## 2. Race Condition Kuota

Ini bagian paling gampang salah kalau ditulis buru-buru. Pengurangan kuota harus jadi satu transaksi atomik dengan row lock, bukan baca-lalu-tulis terpisah.

```sql
START TRANSACTION;

SELECT kuota_tersisa FROM unit_pelaksana
WHERE id = :unit_id AND periode_id = :periode_id
FOR UPDATE;

-- di level aplikasi: kalau kuota_tersisa <= 0, ROLLBACK dan kembalikan error "kuota habis"

UPDATE unit_pelaksana
SET kuota_tersisa = kuota_tersisa - 1
WHERE id = :unit_id AND periode_id = :periode_id;

INSERT INTO reservasi (mahasiswa_id, unit_id, periode_id, expired_at, status)
VALUES (:mahasiswa_id, :unit_id, :periode_id, NOW() + INTERVAL 30 MINUTE, 'ditahan');

COMMIT;
```

`FOR UPDATE` mengunci baris itu sampai transaksi selesai, jadi request kedua yang datang bersamaan akan menunggu, bukan ikut membaca angka lama. Jaga transaksi ini tetap pendek — jangan taruh pemanggilan API eksternal atau pengiriman email di dalam blok transaksi, itu bikin lock ketahan lama dan bikin request lain antre.

**Reservasi punya masa berlaku.** Kalau mahasiswa klik next lalu menutup tab tanpa submit, kuota itu jangan hilang selamanya. Bikin cron job (lewat `cron` biasa yang memanggil script PHP CLI, jalan tiap 5 menit) yang mencari reservasi dengan status `ditahan` dan `expired_at < NOW()`, lalu mengembalikan kuota dan mengubah status jadi `kadaluarsa`, juga dalam satu transaksi.

**Cegah submit ganda.** Tambahkan unique constraint pada `(mahasiswa_id, periode_id)` di tabel pendaftaran final, supaya double-click atau retry jaringan tidak membuat dua pendaftaran aktif untuk mahasiswa yang sama di periode yang sama.

---

## 3. Traffic & Performa

Pola trafik sistem ini bukan rata sepanjang hari — bakal ada lonjakan tajam di jam-jam pertama pendaftaran dibuka, mirip war tiket konser. Beberapa hal yang perlu disiapkan sebelum hari-H:

- **Load test dulu, bukan pas kejadian.** Simulasikan jumlah mahasiswa yang realistis (bisa dicek dari jumlah pendaftar periode sebelumnya) pakai k6 atau JMeter, fokus ke endpoint pemilihan unit dan submit karena itu yang paling berat (transaksi + lock).
- **Cache data yang jarang berubah, jangan cache kuota.** Struktur holding/subholding/unit induk cocok di-cache (file cache atau Redis, TTL beberapa menit) karena jarang berubah dalam satu periode. Angka kuota tersisa sebaliknya harus selalu query langsung ke database — cache di sini justru bikin mahasiswa melihat kuota yang sudah basi dan gagal saat submit.
- **Tuning PHP-FPM dan MySQL.** Sesuaikan `pm.max_children` di PHP-FPM dengan kapasitas RAM server, dan pastikan `max_connections` MySQL cukup tapi tidak berlebihan (koneksi menganggur juga makan resource). Kalau server sering restart pas jam sibuk, ini biasanya penyebabnya.
- **Aplikasi harus stateless.** Simpan session di database atau Redis, bukan file session lokal PHP default, supaya kalau nanti perlu nambah server di belakang load balancer waktu jam sibuk, sesi mahasiswa tidak hilang berpindah server.
- **Aset statis (CSS/JS/gambar peta) dilayani dengan cache header yang benar**, idealnya lewat CDN, supaya server aplikasi cuma sibuk mengurus logika bisnis, bukan melayani file statis berulang-ulang.

---

## 4. CI/CD Supaya Bisa Dipakai Berulang Setiap Tahun

Poin utamanya: buka periode baru itu harus jadi satu perintah/skrip, bukan proses manual ubah-ubah data di phpMyAdmin.

**Migrasi skema versi-terkontrol.** Pakai tool migrasi seperti Phinx untuk PHP, supaya setiap perubahan struktur tabel tercatat sebagai file migrasi yang bisa dijalankan ulang di server manapun, dan bisa di-rollback kalau ada masalah.

**Skrip "buka periode baru".** Ini yang mengoperasionalkan rekomendasi "salin dari periode sebelumnya" yang kita bahas kemarin — satu skrip CLI yang admin (atau pipeline) jalankan sekali per tahun ajaran:

```
php scripts/buka_periode.php --dari=2025-genap --nama="2026-ganjil" --salin-struktur=ya
```

Skrip ini menyalin struktur unit pelaksana dari periode sebelumnya ke periode baru dengan kuota di-reset sesuai input admin, tanpa menyentuh data periode lama sama sekali.

**Pipeline dasar (GitHub Actions atau GitLab CI):**

```
lint & static analysis → unit test → build → deploy staging → migrasi staging
→ approval manual → deploy production → migrasi production (dengan backup otomatis sebelumnya)
```

Backup MySQL (`mysqldump` terjadwal, minimal harian, plus backup manual sebelum migrasi production) itu wajib, dan yang lebih penting: **restore-nya harus pernah dites**, bukan cuma diasumsikan jalan. Banyak insiden data hilang bukan karena backup-nya tidak ada, tapi karena baru ketahuan filenya corrupt pas benar-benar butuh.

Pisahkan environment local, staging, production dengan file `.env` masing-masing, dan jangan pernah commit `.env` ke repository — pakai `.env.example` sebagai template.

---

## 5. Observability

- Log aktivitas admin (perubahan kuota, tambah/hapus unit pelaksana, buka/tutup periode) disimpan permanen, bukan cuma error log biasa.
- Alert otomatis kalau kuota sebuah unit populer mendekati habis, atau kalau cron pembersihan reservasi gagal jalan (ini gampang luput dan efeknya baru ketahuan pas kuota kelihatan aneh berminggu-minggu kemudian).
- Endpoint health check sederhana buat monitoring uptime, terutama di hari pembukaan pendaftaran.

---

## Ringkasan checklist sebelum buka periode baru

- [ ] Struktur unit pelaksana & kuota sudah disalin dan disesuaikan dari periode sebelumnya
- [ ] Backup database dilakukan dan sudah dicoba di-restore
- [ ] Load test sudah dijalankan dengan estimasi jumlah pendaftar
- [ ] Cron pembersihan reservasi kadaluarsa aktif dan terpantau
- [ ] Rate limiting endpoint login & pemilihan unit aktif
- [ ] Environment production terpisah dari staging, `.env` tidak ke-commit

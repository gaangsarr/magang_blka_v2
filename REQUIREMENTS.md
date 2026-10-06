# Server Requirements & Dependencies — INTERN ITPLN (v2)

Dokumen ini berisi spesifikasi kebutuhan lingkungan server (*runtime environment*), ekstensi PHP, pustaka dependensi Composer, dan cron job yang dibutuhkan agar aplikasi Sistem Magang Terpadu INTERN ITPLN dapat berjalan 100% optimal di server BPTI.

---

## 1. Spesifikasi Server & Lingkungan

| Komponen | Rekomendasi / Versi Minimal | Keterangan |
| :--- | :--- | :--- |
| **Sistem Operasi** | Linux Ubuntu 22.04 / 24.04 LTS atau Debian 11/12 | Standard server environment |
| **Web Server** | **Nginx** (disarankan) atau **Apache 2.4** | Jika Apache: wajib aktifkan `mod_rewrite` & `mod_headers` |
| **PHP** | **PHP 8.1, 8.2, atau 8.3** | Disarankan PHP 8.2-FPM |
| **Database** | **MySQL 8.0+** atau **MariaDB 10.5+** | Collation: `utf8mb4_unicode_ci` |
| **Package Manager**| **Composer 2.x** | Untuk instalasi dependensi vendor |
| **Node.js / NPM** | *Tidak Diperlukan* | Frontend murni Vanilla JS, HTML5, CSS (tanpa build tools) |
| **Protokol Web** | **HTTPS (Wajib SSL)** | Diperlukan oleh Microsoft Azure Entra ID SSO |

---

## 2. Ekstensi PHP Wajib (*PHP Extensions*)

Pastikan ekstensi PHP berikut terpasang dan aktif di server:

| Ekstensi | Fungsi dalam Sistem |
| :--- | :--- |
| `php-pdo` & `php-mysql` | Koneksi database MySQL/MariaDB (PDO) |
| `php-mbstring` | Pemrosesan string multi-byte UTF-8 & dependensi PhpSpreadsheet / Dompdf |
| `php-openssl` | Enkripsi data sesi, token CSRF, & komunikasi SSL aman |
| `php-curl` | **Vital**: Integrasi OAuth token exchange SSO Microsoft Azure Entra ID |
| `php-xml` / `php-dom` | Parser XML untuk export Excel (`PhpSpreadsheet`) & Word (`PhpWord`) |
| `php-gd` | Pemrosesan grafis dokumen & barcode/render PDF (`Dompdf`) |
| `php-zip` | Pembacaan & pembuatan arsip file kompresi Office (.xlsx, .docx) |
| `php-json` | Handler REST API JSON |

### Perintah Instalasi Sekali Jalan (Ubuntu / Debian):
```bash
sudo apt update
sudo apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml php8.2-curl php8.2-gd php8.2-zip php8.2-cli composer
```
*(Sesuaikan versi `8.2` jika menggunakan `8.1` atau `8.3`)*.

---

## 3. Pustaka Dependensi PHP (Composer Packages)

Semua dependensi sudah terdaftar di `composer.json` dan terkunci di `composer.lock`:

```bash
composer install --no-dev --optimize-autoloader
```

Daftar paket yang digunakan:
* **`robmorgan/phinx`**: Manajemen migrasi skema tabel database bertahap.
* **`vlucas/phpdotenv`**: Manajemen variabel konfigurasi environment (`.env`).
* **`phpoffice/phpspreadsheet`**: Fitur export rekap data pendaftar & laporan ke format Excel (.xlsx).
* **`phpoffice/phpword`**: Fitur generate dokumen surat penempatan & nota dinas ke format Word (.docx).
* **`phpmailer/phpmailer`**: Pengiriman email notifikasi penerimaan/status magang otomatis via SMTP.
* **`dompdf/dompdf`**: Render laporan penempatan & dokumen ke format PDF.

---

## 4. Konfigurasi `php.ini` yang Disarankan

Edit `/etc/php/8.2/fpm/php.ini` (atau `cli/php.ini`):
```ini
memory_limit = 256M
max_execution_time = 120
date.timezone = Asia/Jakarta
```

---

## 5. Hak Akses Direktori (*File Permissions*)

Web server (`www-data` / `nginx`) hanya membutuhkan hak tulis ke folder `logs/`:
```bash
chmod -R 775 logs
chown -R www-data:www-data logs
```

---

## 6. Background Worker (Cron Job Wajib)

Sistem memiliki 1 skrip worker terpadu untuk:
1. Pembersihan reservasi kadaluarsa (pengembalian kuota 5 menit otomatis).
2. Penutupan periode otomatis saat jadwal pendaftaran berakhir.
3. Pengiriman antrean notifikasi email (*rate-limit safe*).

Tambahkan via `crontab -e`:
```bash
* * * * * php /var/www/magang_blka/v2/scripts/worker.php >> /var/www/magang_blka/v2/logs/worker.log 2>&1
```
*(Sesuaikan path `/var/www/...` dengan letak instalasi sebenarnya di server)*.

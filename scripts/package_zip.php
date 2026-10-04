<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$zipFilename = $root . '/INTERN_ITPLN_BPTI_RELEASE.zip';
$sqlFile = $root . '/magang_clean_production.sql';

echo "=== MEMBUAT PAKET ZIP INTERN ITPLN UNTUK BPTI ===\n";

if (!file_exists($sqlFile)) {
    echo "File SQL bersih belum ada. Menjalankan export_clean_sql.php terlebih dahulu...\n";
    require_once __DIR__ . '/export_clean_sql.php';
}

if (file_exists($zipFilename)) {
    @unlink($zipFilename);
}

$zip = new ZipArchive();
if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Gagal membuat file ZIP di: $zipFilename\n");
}

// Direktori yang sama sekali TIDAK BOLEH dimasuki (pruned instantly)
$ignoreDirs = [
    '.git',
    '.agents',
    '.vscode',
    '.idea',
    '.claude',
    'scratch',
    'cache',
    'logs',
];

$ignoreExactFiles = [
    '.env',
    'check_periode.php',
    'test_cookie.txt',
    'docs/backup_magang_itpln_v2_latest.sql',
    basename($zipFilename),
];

$dirIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);

$filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) use ($ignoreDirs, $ignoreExactFiles) {
    $filename = $current->getFilename();

    if ($iterator->hasChildren()) {
        if (in_array($filename, $ignoreDirs, true)) {
            return false; // PENTING: Skip seluruh sub-folder tanpa traverse ke dalamnya!
        }
    }

    if ($current->isFile()) {
        if (in_array($filename, $ignoreExactFiles, true)) {
            return false;
        }
        if (str_ends_with($filename, '.zip') || str_ends_with($filename, '.log')) {
            return false;
        }
        if (str_starts_with($filename, 'test_') && str_ends_with($filename, '.php')) {
            return false;
        }
    }

    return true;
});

$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

$fileCount = 0;
echo "Mengumpulkan file ke dalam arsip...\n";

foreach ($iterator as $item) {
    $filePath = $item->getRealPath();
    $relPath = ltrim(str_replace('\\', '/', substr($filePath, strlen($root))), '/');

    // Abaikan test scripts di folder scripts
    if (preg_match('/^scripts\/test_.*\.php$/', $relPath)) {
        continue;
    }

    // Abaikan file PDF/upload di storage (hanya sertakan .gitkeep)
    if (preg_match('/^storage\/(cv|porto|transkrip)\/.+/', $relPath)) {
        if ($item->getFilename() !== '.gitkeep') {
            continue;
        }
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relPath);
    } elseif ($item->isFile()) {
        $zip->addFile($filePath, $relPath);
        $fileCount++;
    }
}

// Tambahkan file README_DEPLOY_BPTI.txt ke dalam root ZIP
$deployInstructions = <<<TXT
================================================================================
PANDUAN DEPLOYMENT SISTEM MAGANG TERPADU (INTERN ITPLN v2) - BPTI KAMPUS
================================================================================

1. SPESIFIKASI & EKSTENSI PHP SERVER
   - PHP: 8.1 / 8.2 / 8.3
   - Ekstensi PHP Wajib:
     pdo_mysql, mbstring, openssl, curl, gd, zip, fileinfo
   - Pengaturan php.ini yang WAJIB disesuaikan (untuk upload berkas PDF mahasiswa):
     upload_max_filesize = 10M
     post_max_size = 25M
     memory_limit = 256M
     date.timezone = Asia/Jakarta

2. KONFIGURASI WEB SERVER
   - Arahkan Document Root ke root folder aplikasi ini.
   - Jika menggunakan Apache:
     * Pastikan modul rewrite & headers aktif:
       sudo a2enmod rewrite headers
     * Pastikan AllowOverride All aktif pada virtualhost agar file .htaccess berfungsi.
   - Jika menggunakan Nginx:
     * Rujukan konfigurasi lengkap virtualhost Nginx tersedia di folder docs/DEPLOY_NGINX.md.

3. IMPORT DATABASE BERSIH (CLEAN DUMP)
   File database bersih sudah disertakan di root paket ini:
     `magang_clean_production.sql`
   
   Cara import via terminal:
     mysql -u [db_user] -p [db_name] < magang_clean_production.sql

   Catatan:
   - Data pelamar dummy & periode pengujian telah dibersihkan secara steril.
   - Master data lengkap: 1.285 Unit & Anak Perusahaan PLN se-Indonesia, 14 Program Studi,
     38 Peminatan, pengaturan default, serta akun Super Admin BLKA resmi kampus.
   - Akun admin perusahaan dapat di-generate kapan saja via Dashboard Admin Super Admin.

4. KONFIGURASI ENVIRONMENT (.env)
   Salin template .env.example menjadi .env:
     cp .env.example .env

   Sesuaikan variabel utama:
     DB_HOST=127.0.0.1
     DB_PORT=3306
     DB_NAME=[nama_database]
     DB_USER=[db_user]
     DB_PASS=[db_password]
     APP_ENV=production
     APP_URL=https://[domain-resmi-kampus] (Gunakan protokol HTTPS)
     APP_DEBUG=false
     AZURE_TENANT_ID=[Tenant ID Entra ID Kampus ITPLN]
     AZURE_CLIENT_ID=[Client ID Azure App Registration]
     AZURE_CLIENT_SECRET=[Client Secret Azure App]
     SESSION_KEY=[Generate acak 64 karakter hex: php -r "echo bin2hex(random_bytes(32));"]
     SMTP_HOST, SMTP_USER, SMTP_PASS=[Kredensial email kampus untuk notifikasi otomatis]

   PENTING TERKAIT SSO ITPLN (AZURE ENTRA ID):
   Pastikan di Azure Portal (App Registrations -> Authentication -> Redirect URIs)
   telah didaftarkan URL persis:
     https://[domain-resmi-kampus]/api/auth/azure/callback.php

5. PERMISSION FOLDER STORAGE & LOGS
   Pastikan user web server (misal www-data) memiliki hak write:
     chmod -R 775 storage logs
     chown -R www-data:www-data storage logs

6. CRON JOB BACKGROUND WORKER (WAJIB)
   Sistem ini memerlukan 1 cron job yang berjalan setiap menit untuk:
   - Pengembalian kuota reservasi 5 menit yang kadaluarsa secara otomatis
   - Pengecekan otomatis penutupan periode magang sesuai batas jam
   - Pemrosesan antrean email notifikasi pelamar secara bertahap & aman rate-limit

   Tambahkan via crontab server (crontab -e):
     * * * * * php /var/www/magang_blka/v2/scripts/worker.php >> /var/www/magang_blka/v2/logs/worker.log 2>&1
   (Sesuaikan path direktori dengan path instalasi di server)

7. VENDOR (DEPENDENSI PHP)
   Folder `vendor/` sudah disertakan lengkap di dalam paket ZIP ini.
   Server tidak wajib menginstall Composer secara manual.

8. PULL UPDATE BERKALA VIA GITHUB
   Untuk update kode berikutnya, BPTI cukup menjalankan git pull pada branch `main`:
     git pull origin main
   Jika di kemudian hari ada pembaruan struktur tabel database baru:
     vendor/bin/phinx migrate

================================================================================
TXT;

$zip->addFromString('README_DEPLOY_BPTI.txt', $deployInstructions);

echo "Mengompresi dan menyimpan arsip ZIP (menulis $fileCount file)...\n";
$zip->close();

$zipSizeMb = round(filesize($zipFilename) / 1024 / 1024, 2);
echo "=== SELESAI! Paket ZIP berhasil dibuat ===\n";
echo "File : " . basename($zipFilename) . "\n";
echo "Ukuran : $zipSizeMb MB\n";
echo "Total File : $fileCount file\n";

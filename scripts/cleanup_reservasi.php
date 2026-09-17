<?php
declare(strict_types=1);

//
// Script Cleanup Reservasi Kadaluarsa
//
// BLOCKER-08: Jalankan via cronjob server di production, BUKAN via PowerShell loop!
//
// Setup di Linux production:
//   sudo nano /etc/cron.d/magang_cleanup
//   Isi: */5 * * * * www-data /usr/bin/php /var/www/html/scripts/cleanup_reservasi.php >> /var/log/magang_cleanup.log 2>&1
//
// Alternatif: MySQL Event Scheduler (lihat scripts/setup_cronjob.md)
//

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

try {
    $pdo = Database::getInstance();
    
    echo "[" . date('Y-m-d H:i:s') . "] Memulai cleanup reservasi...\n";

    Database::transaction(function (PDO $pdo) {
        $count = \App\ReservasiHelper::cleanupExpired($pdo);
        if ($count > 0) {
            echo "Berhasil membersihkan dan mengembalikan kuota untuk $count reservasi kadaluarsa.\n";
        } else {
            echo "Tidak ada reservasi yang kadaluarsa.\n";
        }
    });

} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error cleanup: " . $e->getMessage() . "\n";
}

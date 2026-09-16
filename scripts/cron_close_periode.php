<?php
declare(strict_types=1);

//
// Script Penutupan Otomatis Periode Magang Berdasarkan Jam Server (WIB)
//
// Setup di Linux production (Crontab per menit):
//   sudo crontab -e
//   * * * * * /usr/bin/php /var/www/html/scripts/cron_close_periode.php >> /var/log/magang_close_periode.log 2>&1
//
// Setup di Windows Task Scheduler:
//   Action: Start a program
//   Program/script: php.exe
//   Add arguments: c:\path\to\scripts\cron_close_periode.php
//   Trigger: Daily, repeat every 1 minute
//

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\PeriodeHelper;

$root = dirname(__DIR__);
Dotenv::createImmutable($root)->safeLoad();

try {
    $pdo = Database::getInstance();
    
    $nowStr = date('Y-m-d H:i:s');
    echo "[$nowStr] Menjalankan pengecekan penutupan otomatis periode magang...\n";

    $closedIds = PeriodeHelper::closeExpiredPeriodes($pdo);

    if (empty($closedIds)) {
        echo "[$nowStr] Tidak ada periode yang melewati waktu penutupan.\n";
    } else {
        echo "[$nowStr] Berhasil menutup " . count($closedIds) . " periode (ID: " . implode(', ', $closedIds) . ").\n";
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error penutupan periode: " . $e->getMessage() . "\n";
    exit(1);
}

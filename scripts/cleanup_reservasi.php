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
        // Ambil semua reservasi yang expired dan masih ditahan (FOR UPDATE)
        $stmt = $pdo->prepare("
            SELECT id, unit_pelaksana_periode_id 
            FROM reservasi 
            WHERE status = 'ditahan' AND expired_at < NOW() 
            FOR UPDATE
        ");
        $stmt->execute();
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expired)) {
            echo "Tidak ada reservasi yang kadaluarsa.\n";
            return;
        }

        $count = count($expired);
        $ids   = array_column($expired, 'id');
        $inQuery = implode(',', array_fill(0, count($ids), '?'));

        // Update status menjadi kadaluarsa (batch, 1 query)
        $pdo->prepare("UPDATE reservasi SET status = 'kadaluarsa' WHERE id IN ($inQuery)")->execute($ids);

        // PERF-01: Kembalikan kuota ke unit — batch UPDATE dengan GROUP BY + COUNT
        // Menggantikan loop N+1 yang lama (1 UPDATE per reservasi)
        $pdo->prepare("
            UPDATE unit_pelaksana_periode upp
            JOIN (
                SELECT unit_pelaksana_periode_id, COUNT(*) AS jumlah
                FROM reservasi
                WHERE id IN ($inQuery)
                GROUP BY unit_pelaksana_periode_id
            ) r ON upp.id = r.unit_pelaksana_periode_id
            SET upp.kuota_tersisa = upp.kuota_tersisa + r.jumlah
        ")->execute($ids);
        
        // Insert log
        $stmtLog = $pdo->prepare("
            INSERT INTO log_aktivitas (aksi, detail_json, ip_address) 
            VALUES ('cleanup_reservasi', ?, '127.0.0.1')
        ");
        $stmtLog->execute([json_encode(['total_dibersihkan' => $count, 'ids' => $ids])]);
        
        echo "Berhasil membatalkan dan mengembalikan kuota untuk $count reservasi.\n";
    });

} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error cleanup: " . $e->getMessage() . "\n";
}

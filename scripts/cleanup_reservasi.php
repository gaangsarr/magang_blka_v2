<?php
declare(strict_types=1);

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
        
        // Update status menjadi kadaluarsa
        $ids = array_column($expired, 'id');
        $inQuery = implode(',', array_fill(0, count($ids), '?'));
        
        $pdo->prepare("UPDATE reservasi SET status = 'kadaluarsa' WHERE id IN ($inQuery)")->execute($ids);
        
        // Kembalikan kuota ke masing-masing unit
        $stmtUpdateUnit = $pdo->prepare("
            UPDATE unit_pelaksana_periode 
            SET kuota_tersisa = kuota_tersisa + 1 
            WHERE id = ?
        ");
        
        foreach ($expired as $row) {
            $stmtUpdateUnit->execute([$row['unit_pelaksana_periode_id']]);
        }
        
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

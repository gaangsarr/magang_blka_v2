<?php
require 'vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

try {
    $pdo = App\Database::getInstance();
    
    $stmt = $pdo->query("SELECT r.*, e.nama FROM reservasi r JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id JOIN entitas_perusahaan e ON upp.entitas_id = e.id ORDER BY r.id DESC LIMIT 10");
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== RECENT RESERVATIONS ===\n";
    foreach ($reservations as $r) {
        echo "ID: {$r['id']} | Mhs: {$r['mahasiswa_id']} | Unit: {$r['nama']} | Status: {$r['status']} | Expired: {$r['expired_at']}\n";
    }

    // Recalculate kuota_tersisa for all unit_pelaksana_periode
    $pdo->exec("
        UPDATE unit_pelaksana_periode upp
        SET upp.kuota_tersisa = upp.kuota_total 
            - IFNULL((SELECT COUNT(*) FROM pendaftaran p WHERE p.unit_pelaksana_periode_id = upp.id AND p.status IN ('diajukan', 'diverifikasi', 'diterima', 'dipindahkan')), 0)
            - IFNULL((SELECT COUNT(*) FROM reservasi r WHERE r.unit_pelaksana_periode_id = upp.id AND r.status = 'ditahan' AND r.expired_at > NOW()), 0)
    ");
    echo "Kuota recalculated successfully!\n";

    $stmtUpp = $pdo->query("SELECT upp.id, e.nama, upp.kuota_total, upp.kuota_tersisa FROM unit_pelaksana_periode upp JOIN entitas_perusahaan e ON upp.entitas_id = e.id WHERE upp.kuota_tersisa < upp.kuota_total");
    $decreasedUnits = $stmtUpp->fetchAll(PDO::FETCH_ASSOC);
    echo "\n=== UNITS WITH REDUCED QUOTA AFTER RECALCULATION ===\n";
    foreach ($decreasedUnits as $u) {
        echo "UPP ID: {$u['id']} | Unit: {$u['nama']} | Kuota Total: {$u['kuota_total']} | Sisa: {$u['kuota_tersisa']}\n";
    }
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

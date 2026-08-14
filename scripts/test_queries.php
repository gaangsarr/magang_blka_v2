<?php
require 'vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->safeLoad();

try {
    $pdo = App\Database::getInstance();
    
    // Test entitas query
    $query = "
        SELECT 
            e.id, e.nama, e.alamat, e.latitude, e.longitude, e.aktif,
            GROUP_CONCAT(up.peminatan_id) as peminatan_ids
        FROM entitas_perusahaan e
        LEFT JOIN unit_peminatan up ON e.id = up.entitas_id
        WHERE e.tipe = 'unit_pelaksana'
        GROUP BY e.id
        ORDER BY e.nama ASC
    ";
    $stmt = $pdo->query($query);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Entitas query OK! Found " . count($data) . " rows\n";
    
    // Test dashboard query
    $periodeId = 1; // dummy
    $stmtSebaran = $pdo->prepare("
        SELECT pem.nama, COUNT(pp.peminatan_id) as jumlah
        FROM pendaftaran_peminatan pp
        JOIN pendaftaran p ON pp.pendaftaran_id = p.id
        JOIN peminatan pem ON pp.peminatan_id = pem.id
        WHERE p.periode_id = ?
        GROUP BY pem.id, pem.nama
        ORDER BY jumlah DESC
    ");
    $stmtSebaran->execute([$periodeId]);
    $sebaran = $stmtSebaran->fetchAll(PDO::FETCH_ASSOC);
    echo "Dashboard query OK! Found " . count($sebaran) . " rows\n";
    
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

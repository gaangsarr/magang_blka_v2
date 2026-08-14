<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

try {
    $pdo = Database::getInstance();
    $stmtPeriode = $pdo->query("SELECT id, nama FROM periode WHERE status = 'dibuka' LIMIT 1");
    $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    $periodeId = $periode ? $periode['id'] : null;

    if ($periodeId) {
        $stmtJurusan = $pdo->prepare("
            SELECT COALESCE(j.nama_jurusan, 'Belum Diisi') AS jurusan, COUNT(p.id) AS jumlah
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            LEFT JOIN jurusan j ON m.jurusan_id = j.id
            WHERE p.periode_id = ?
            GROUP BY j.id, j.nama_jurusan
            ORDER BY jumlah DESC
        ");
        $stmtJurusan->execute([$periodeId]);
        $sebaranJurusan = $stmtJurusan->fetchAll(PDO::FETCH_ASSOC);

        $stmtUnit = $pdo->prepare("
            SELECT e.nama AS unit, COUNT(p.id) AS jumlah
            FROM pendaftaran p
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            WHERE p.periode_id = ?
            GROUP BY e.id, e.nama
            ORDER BY jumlah DESC
            LIMIT 10
        ");
        $stmtUnit->execute([$periodeId]);
        $topUnit = $stmtUnit->fetchAll(PDO::FETCH_ASSOC);

        echo "Jurusan Data: " . json_encode($sebaranJurusan) . "\n";
        echo "Unit Data: " . json_encode($topUnit) . "\n";
    }

    echo "DASHBOARD STATS VALID!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil periode aktif
    $stmtPeriode = $pdo->query("SELECT id, nama FROM periode WHERE status = 'dibuka' LIMIT 1");
    $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    $periodeId = $periode ? $periode['id'] : null;
    
    if (!$periodeId) {
        echo json_encode([
            'ok' => true,
            'data' => [
                'total_pendaftar' => 0,
                'total_kuota' => 0,
                'sisa_kuota' => 0,
                'sebaran_jurusan' => [],
                'top_unit' => []
            ]
        ]);
        exit;
    }
    
    // Total pendaftar
    $stmtPendaftar = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE periode_id = ?");
    $stmtPendaftar->execute([$periodeId]);
    $totalPendaftar = (int)$stmtPendaftar->fetchColumn();
    
    // Total & Sisa Kuota
    $stmtKuota = $pdo->prepare("SELECT SUM(kuota_total) as total, SUM(kuota_tersisa) as sisa FROM unit_pelaksana_periode WHERE periode_id = ? AND aktif = 1");
    $stmtKuota->execute([$periodeId]);
    $kuota = $stmtKuota->fetch(PDO::FETCH_ASSOC);
    
    // 1. Grafik Pendaftar Per Jurusan
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

    // 2. Grafik Peminat Unit Pelaksana Paling Banyak (Top 10 Units)
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
    
    echo json_encode([
        'ok' => true,
        'periode_nama' => $periode['nama'],
        'data' => [
            'total_pendaftar' => $totalPendaftar,
            'total_kuota' => (int)($kuota['total'] ?? 0),
            'sisa_kuota' => (int)($kuota['sisa'] ?? 0),
            'sebaran_jurusan' => $sebaranJurusan,
            'top_unit' => $topUnit
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

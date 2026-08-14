<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil daftar semua periode untuk dropdown
    $stmtAll = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        // Cari periode dengan status dibuka atau persiapan terlebih dahulu
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    // Cek apakah ada periode yang aktif di sistem secara umum (dibuka atau persiapan)
    $stmtCheckAktif = $pdo->query("SELECT COUNT(*) FROM periode WHERE status IN ('dibuka', 'persiapan')");
    $hasPeriodeAktif = ((int)$stmtCheckAktif->fetchColumn()) > 0;
    
    $periodeId = $periode ? (int)$periode['id'] : null;
    
    // SESUDAH — tambah filter tipe = 'unit_pelaksana' dan tampilkan nama parent (UP3)
    $query = "
        SELECT 
            ep.id AS entitas_id,
            ep.nama,
            ep.singkatan,
            ep.alamat,
            parent.nama  AS nama_unit_induk,
            upp.id       AS upp_id,
            upp.kuota_total,
            upp.kuota_tersisa,
            upp.aktif
        FROM entitas_perusahaan ep
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        LEFT JOIN unit_pelaksana_periode upp 
            ON ep.id = upp.entitas_id AND upp.periode_id = :periode_id
        WHERE ep.aktif = 1 AND ep.tipe = 'unit_pelaksana'
        ORDER BY parent.nama ASC, ep.nama ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':periode_id', $periodeId, PDO::PARAM_INT);
    $stmt->execute();
    
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'has_periode_aktif' => $hasPeriodeAktif,
        'periode_terpilih' => $periode,
        'periode_aktif' => $periode,
        'all_periode' => $allPeriode,
        'data' => $units
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

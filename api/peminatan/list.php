<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::startSession(startPHP: true);
if (!Auth::isLoggedInMahasiswa() && !Auth::isLoggedInAdmin() && !Auth::isLoggedInPerusahaan()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // 1. Tentukan filter jurusan
    $targetJurusanIds = [];

    if (Auth::isLoggedInMahasiswa()) {
        $mhs = Auth::getMahasiswa();
        $mhsId = Auth::getMahasiswaId();
        $jurusanId = (int)($mhs['jurusan_id'] ?? 0);
        if (!$jurusanId && $mhsId) {
            $stmtM = $pdo->prepare("SELECT jurusan_id FROM mahasiswa WHERE id = ?");
            $stmtM->execute([$mhsId]);
            $jurusanId = (int)$stmtM->fetchColumn();
        }
        if ($jurusanId > 0) {
            $targetJurusanIds = [$jurusanId];
        }
    } elseif (!empty($_GET['jurusan_ids'])) {
        $rawJids = is_array($_GET['jurusan_ids']) ? $_GET['jurusan_ids'] : explode(',', (string)$_GET['jurusan_ids']);
        $targetJurusanIds = array_values(array_unique(array_filter(array_map('intval', $rawJids))));
    }

    if (!empty($targetJurusanIds)) {
        $placeholders = implode(',', array_fill(0, count($targetJurusanIds), '?'));
        $sql = "
            SELECT DISTINCT p.id, p.nama, p.deskripsi, p.aktif
            FROM peminatan p
            JOIN peminatan_jurusan pj ON p.id = pj.peminatan_id
            WHERE p.aktif = 1 AND pj.jurusan_id IN ($placeholders)
            ORDER BY p.nama ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($targetJurusanIds);
        $peminatan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("SELECT id, nama, deskripsi, aktif FROM peminatan WHERE aktif = 1 ORDER BY nama ASC");
        $stmt->execute();
        $peminatan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'ok' => true,
        'data' => $peminatan
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

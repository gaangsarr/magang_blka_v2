<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$id = isset($body['id']) ? (int)$body['id'] : null;
$nama = trim($body['nama'] ?? '');
$alamat = trim($body['alamat'] ?? '');
$lat = isset($body['latitude']) ? (float)$body['latitude'] : null;
$lng = isset($body['longitude']) ? (float)$body['longitude'] : null;
$aktif = isset($body['aktif']) ? (int)$body['aktif'] : 1;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids']) ? array_map('intval', $body['peminatan_ids']) : [];

if (!$id || empty($nama) || empty($alamat) || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Data tidak lengkap.']);
    exit;
}

if (empty($peminatanIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Minimal pilih 1 peminatan.']);
    exit;
}

try {
    Database::transaction(function (PDO $pdo) use ($id, $nama, $alamat, $lat, $lng, $aktif, $peminatanIds) {
        $stmt = $pdo->prepare("
            UPDATE entitas_perusahaan 
            SET nama = ?, alamat = ?, latitude = ?, longitude = ?, aktif = ?
            WHERE id = ? AND tipe = 'unit_pelaksana'
        ");
        $stmt->execute([$nama, $alamat, $lat, $lng, $aktif, $id]);
        
        // Update peminatan (hapus semua lalu insert ulang)
        $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
        $stmtDel->execute([$id]);
        
        $stmtPem = $pdo->prepare("INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)");
        foreach ($peminatanIds as $pid) {
            $stmtPem->execute([$id, $pid]);
        }
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Master Unit berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

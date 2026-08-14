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

$nama = trim($body['nama'] ?? '');
$alamat = trim($body['alamat'] ?? '');
$lat = isset($body['latitude']) ? (float)$body['latitude'] : null;
$lng = isset($body['longitude']) ? (float)$body['longitude'] : null;
$aktif = isset($body['aktif']) ? (int)$body['aktif'] : 1;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids']) ? array_map('intval', $body['peminatan_ids']) : [];

if (empty($nama) || empty($alamat) || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama, alamat, latitude, dan longitude wajib diisi.']);
    exit;
}

if (empty($peminatanIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Minimal pilih 1 peminatan.']);
    exit;
}

try {
    Database::transaction(function (PDO $pdo) use ($nama, $alamat, $lat, $lng, $aktif, $peminatanIds) {
        $stmt = $pdo->prepare("
            INSERT INTO entitas_perusahaan (tipe, nama, alamat, latitude, longitude, aktif) 
            VALUES ('unit_pelaksana', ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nama, $alamat, $lat, $lng, $aktif]);
        $entitasId = $pdo->lastInsertId();
        
        $stmtPem = $pdo->prepare("INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)");
        foreach ($peminatanIds as $pid) {
            $stmtPem->execute([$entitasId, $pid]);
        }
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Master Unit berhasil ditambahkan.'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

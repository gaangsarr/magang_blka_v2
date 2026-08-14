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

$id        = isset($body['id']) ? (int)$body['id'] : null;
$nama      = trim($body['nama'] ?? '');
$singkatan = trim($body['singkatan'] ?? '') ?: null;
$alamat    = trim($body['alamat'] ?? '') ?: null;
$lat       = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
$lng       = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
$aktif     = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids'])
    ? array_map('intval', $body['peminatan_ids'])
    : [];

if (!$id || empty($nama)) {
    http_response_code(400);
    echo json_encode(['error' => 'id dan nama wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil data existing untuk tahu tipenya
    $stmtGet = $pdo->prepare("SELECT id, tipe FROM entitas_perusahaan WHERE id = ?");
    $stmtGet->execute([$id]);
    $existing = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Entitas tidak ditemukan.']);
        exit;
    }

    $tipe = $existing['tipe'];

    // Validasi peminatan untuk unit_pelaksana
    if ($tipe === 'unit_pelaksana' && empty($peminatanIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unit pelaksana wajib memiliki minimal 1 peminatan.']);
        exit;
    }

    Database::transaction(function (PDO $pdo) use (
        $id, $tipe, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $peminatanIds
    ) {
        // Update entitas — tanpa mengubah tipe dan parent_id (hierarki tidak boleh digeser sembarangan)
        $stmt = $pdo->prepare("
            UPDATE entitas_perusahaan
            SET nama = ?, singkatan = ?, alamat = ?, latitude = ?, longitude = ?, aktif = ?
            WHERE id = ?
        ");
        $stmt->execute([$nama, $singkatan, $alamat, $lat, $lng, $aktif, $id]);

        // Update peminatan hanya untuk unit_pelaksana
        if ($tipe === 'unit_pelaksana') {
            $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDel->execute([$id]);

            $stmtPem = $pdo->prepare(
                "INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)"
            );
            foreach ($peminatanIds as $pid) {
                $stmtPem->execute([$id, $pid]);
            }
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Entitas berhasil diperbarui.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

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

if (!$id || empty($nama)) {
    http_response_code(400);
    echo json_encode(['error' => 'id dan nama wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil data existing
    $stmtGet = $pdo->prepare("SELECT id, tipe, menerima_magang FROM entitas_perusahaan WHERE id = ?");
    $stmtGet->execute([$id]);
    $existing = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Entitas tidak ditemukan.']);
        exit;
    }

    $menerimaMagang = isset($body['menerima_magang']) 
        ? (int)(bool)$body['menerima_magang'] 
        : (int)$existing['menerima_magang'];

    // Ambil peminatan_ids jika dikirim
    $hasPeminatanPayload = isset($body['peminatan_ids']);
    $peminatanIds = $hasPeminatanPayload && is_array($body['peminatan_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $body['peminatan_ids']))))
        : [];

    // Jika menerima magang, pastikan ada minimal 1 peminatan
    if ($menerimaMagang === 1) {
        if ($hasPeminatanPayload && empty($peminatanIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Entitas yang menerima magang wajib memiliki minimal 1 peminatan.']);
            exit;
        } elseif (!$hasPeminatanPayload) {
            // Cek apakah di DB sudah punya peminatan
            $stmtCekPem = $pdo->prepare("SELECT COUNT(*) FROM unit_peminatan WHERE entitas_id = ?");
            $stmtCekPem->execute([$id]);
            if ((int)$stmtCekPem->fetchColumn() === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Entitas yang menerima magang wajib memiliki minimal 1 peminatan. Pilih peminatan terlebih dahulu.']);
                exit;
            }
        }
    }

    Database::transaction(function (PDO $pdo) use (
        $id, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang, $hasPeminatanPayload, $peminatanIds
    ) {
        // Update entitas — tipe dan parent_id tetap dipertahankan
        $stmt = $pdo->prepare("
            UPDATE entitas_perusahaan
            SET nama = ?, singkatan = ?, alamat = ?, latitude = ?, longitude = ?, aktif = ?, menerima_magang = ?
            WHERE id = ?
        ");
        $stmt->execute([$nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang, $id]);

        // Update relasi peminatan jika payload dikirimkan
        if ($hasPeminatanPayload) {
            $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDel->execute([$id]);

            if ($menerimaMagang === 1 && !empty($peminatanIds)) {
                $stmtPem = $pdo->prepare(
                    "INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)"
                );
                foreach ($peminatanIds as $pid) {
                    $stmtPem->execute([$id, $pid]);
                }
            }
        } elseif ($menerimaMagang === 0) {
            // Jika menerima_magang dimatikan, bersihkan relasi peminatan
            $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDel->execute([$id]);
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Entitas berhasil diperbarui.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data entitas.')]);
}


<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PenetapanHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requirePerusahaanApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pemindahanId = isset($input['pemindahan_id']) ? (int)$input['pemindahan_id'] : 0;
$action = strtolower(trim((string)($input['action'] ?? ''))); // 'approve'/'terima' atau 'reject'/'tolak'
if ($action === 'terima') $action = 'approve';
if ($action === 'tolak')  $action = 'reject';
$catatan = trim((string)($input['catatan'] ?? ''));

if ($pemindahanId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID pemindahan dan aksi (approve/reject/terima/tolak) wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);

    if (!$entitasId) {
        throw new \RuntimeException('Entitas perusahaan tidak ditemukan.');
    }

    $isApprove = ($action === 'approve');

    Database::transaction(function (PDO $pdo) use ($pemindahanId, $isApprove, $catatan, $adminId, $entitasId) {
        PenetapanHelper::responPemindahan($pdo, $pemindahanId, $isApprove, $catatan, $adminId, $entitasId);
    });

    $msg = $isApprove 
        ? 'Pemindahan mahasiswa berhasil disetujui. Mahasiswa resmi ditempatkan di unit Anda.'
        : 'Pemindahan mahasiswa berhasil ditolak dan otomatis dikembalikan ke unit asal.';

    echo json_encode([
        'ok'      => true,
        'message' => $msg
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

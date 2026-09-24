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
Auth::requireAdminApi();
Auth::requireCsrfApi(); // BLOCKER-05: CSRF protection

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$pendaftaranId = isset($input['pendaftaran_id']) ? (int)$input['pendaftaran_id'] : 0;
$statusTarget = trim((string)($input['status'] ?? '')); // 'diterima', 'dipindahkan', 'ditolak', 'diajukan'
$newUppId = isset($input['new_unit_pelaksana_periode_id']) ? (int)$input['new_unit_pelaksana_periode_id'] : null;
$catatanAdmin = trim((string)($input['catatan_admin'] ?? $input['alasan_penolakan'] ?? $input['alasan_pemindahan'] ?? $input['catatan'] ?? $input['alasan'] ?? ''));

if ($pendaftaranId <= 0 || $statusTarget === '') {
    http_response_code(400);
    echo json_encode(['error' => 'ID Pendaftaran dan status wajib diisi.']);
    exit;
}

$validStatuses = ['diajukan', 'diverifikasi', 'diterima', 'dipindahkan', 'ditolak'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid.']);
    exit;
}

try {
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);

    Database::transaction(function (PDO $pdo) use ($pendaftaranId, $statusTarget, $newUppId, $catatanAdmin, $adminId) {
        if ($statusTarget === 'diterima') {
            PenetapanHelper::terimaPeserta($pdo, $pendaftaranId, $catatanAdmin, $adminId, 'admin_blka');
        } elseif ($statusTarget === 'ditolak') {
            PenetapanHelper::tolakPeserta($pdo, $pendaftaranId, $catatanAdmin, $adminId, 'admin_blka');
        } elseif ($statusTarget === 'dipindahkan') {
            if (!$newUppId || $newUppId <= 0) {
                throw new \RuntimeException("Unit pelaksana tujuan wajib dipilih untuk pemindahan paksa.");
            }
            PenetapanHelper::ajukanPemindahan($pdo, $pendaftaranId, $newUppId, $catatanAdmin, $adminId, 'admin_blka');
        } else {
            // Status biasa (diajukan / diverifikasi)
            $stmtUpd = $pdo->prepare("
                UPDATE pendaftaran 
                SET status = :status,
                    catatan_admin = :catatan,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':status'  => $statusTarget,
                ':catatan' => $catatanAdmin,
                ':id'      => $pendaftaranId
            ]);
        }
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Status penetapan berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

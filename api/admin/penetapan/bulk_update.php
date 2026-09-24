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

$pendaftaranIds = $input['pendaftaran_ids'] ?? [];
$statusTarget = trim((string)($input['status'] ?? $input['action'] ?? ''));
$newUppId = isset($input['new_unit_pelaksana_periode_id']) ? (int)$input['new_unit_pelaksana_periode_id'] : null;
$catatanAdmin = trim((string)($input['catatan_admin'] ?? $input['alasan_penolakan'] ?? $input['alasan_pemindahan'] ?? $input['catatan'] ?? $input['alasan'] ?? ''));

if (empty($pendaftaranIds) || !is_array($pendaftaranIds) || $statusTarget === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Pilih setidaknya 1 mahasiswa dan tentukan status penetapan.']);
    exit;
}

$validStatuses = ['diajukan', 'diverifikasi', 'diterima', 'dipindahkan', 'ditolak'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid.']);
    exit;
}

if ($statusTarget === 'dipindahkan' && (!$newUppId || $newUppId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unit pelaksana tujuan wajib dipilih untuk pemindahan paksa.']);
    exit;
}

try {
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);
    $processedCount = 0;

    Database::transaction(function (PDO $pdo) use ($pendaftaranIds, $statusTarget, $newUppId, $catatanAdmin, $adminId, &$processedCount) {
        foreach ($pendaftaranIds as $pid) {
            $pendaftaranId = (int)$pid;
            if ($pendaftaranId <= 0) continue;

            if ($statusTarget === 'diterima') {
                PenetapanHelper::terimaPeserta($pdo, $pendaftaranId, $catatanAdmin, $adminId, 'admin_blka');
            } elseif ($statusTarget === 'ditolak') {
                PenetapanHelper::tolakPeserta($pdo, $pendaftaranId, $catatanAdmin, $adminId, 'admin_blka');
            } elseif ($statusTarget === 'dipindahkan') {
                PenetapanHelper::ajukanPemindahan($pdo, $pendaftaranId, (int)$newUppId, $catatanAdmin, $adminId, 'admin_blka');
            } else {
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
            $processedCount++;
        }
    });

    echo json_encode([
        'ok' => true,
        'processed' => $processedCount,
        'message' => "Berhasil memproses {$processedCount} mahasiswa terpilih."
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PenetapanHelper;
use App\UserException;

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
$pendaftaranIds = $input['pendaftaran_ids'] ?? [];
$statusTarget = trim((string)($input['status'] ?? $input['action'] ?? ''));
$newUppId = isset($input['new_unit_pelaksana_periode_id']) ? (int)$input['new_unit_pelaksana_periode_id'] : null;
$catatan = trim((string)($input['alasan_penolakan'] ?? $input['alasan_pemindahan'] ?? $input['catatan'] ?? $input['alasan'] ?? $input['catatan_admin'] ?? ''));

if (!is_array($pendaftaranIds) || empty($pendaftaranIds) || $statusTarget === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Daftar ID pendaftaran dan status wajib disertakan.']);
    exit;
}

$validStatuses = ['diterima', 'ditolak', 'dipindahkan'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status bulk tidak valid.']);
    exit;
}

if ($statusTarget === 'dipindahkan' && (!$newUppId || $newUppId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unit pelaksana tujuan wajib dipilih untuk pemindahan massal.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);

    if (!$entitasId) {
        throw new UserException('Entitas perusahaan tidak ditemukan.');
    }

    $processed = 0;
    Database::transaction(function (PDO $pdo) use ($pendaftaranIds, $statusTarget, $newUppId, $catatan, $adminId, $entitasId, &$processed) {
        foreach ($pendaftaranIds as $pid) {
            $pId = (int)$pid;
            if ($pId <= 0) continue;

            if ($statusTarget === 'diterima') {
                PenetapanHelper::terimaPeserta($pdo, $pId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
            } elseif ($statusTarget === 'ditolak') {
                PenetapanHelper::tolakPeserta($pdo, $pId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
            } elseif ($statusTarget === 'dipindahkan') {
                PenetapanHelper::ajukanPemindahan($pdo, $pId, (int)$newUppId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
            }
            $processed++;
        }
    });

    echo json_encode([
        'ok'        => true,
        'processed' => $processed,
        'message'   => "Berhasil memproses {$processed} mahasiswa terpilih."
    ]);

} catch (UserException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan saat memproses data pendaftar.')]);
}

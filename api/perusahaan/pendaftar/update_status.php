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
$pendaftaranId = isset($input['pendaftaran_id']) ? (int)$input['pendaftaran_id'] : 0;
$statusTarget = trim((string)($input['status'] ?? ''));
$newUppId = isset($input['new_unit_pelaksana_periode_id']) ? (int)$input['new_unit_pelaksana_periode_id'] : null;
$catatan = trim((string)($input['alasan_penolakan'] ?? $input['alasan_pemindahan'] ?? $input['catatan'] ?? $input['alasan'] ?? $input['catatan_admin'] ?? ''));

if ($pendaftaranId <= 0 || $statusTarget === '') {
    http_response_code(400);
    echo json_encode(['error' => 'ID Pendaftaran dan status penetapan wajib diisi.']);
    exit;
}

$validStatuses = ['diterima', 'ditolak', 'dipindahkan'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid. Harus diterima, ditolak, atau dipindahkan.']);
    exit;
}

if ($statusTarget === 'dipindahkan' && (!$newUppId || $newUppId <= 0)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unit pelaksana tujuan wajib dipilih untuk pemindahan peserta.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);

    if (!$entitasId) {
        throw new UserException('Entitas perusahaan tidak ditemukan pada akun ini.');
    }

    Database::transaction(function (PDO $pdo) use ($pendaftaranId, $statusTarget, $newUppId, $catatan, $adminId, $entitasId) {
        if ($statusTarget === 'diterima') {
            PenetapanHelper::terimaPeserta($pdo, $pendaftaranId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
        } elseif ($statusTarget === 'ditolak') {
            PenetapanHelper::tolakPeserta($pdo, $pendaftaranId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
        } elseif ($statusTarget === 'dipindahkan') {
            PenetapanHelper::ajukanPemindahan($pdo, $pendaftaranId, (int)$newUppId, $catatan, $adminId, 'admin_perusahaan', $entitasId);
        }
    });

    $messages = [
        'diterima'    => 'Mahasiswa berhasil ditetapkan diterima pada unit Anda.',
        'ditolak'     => 'Mahasiswa berhasil ditolak. Kuota telah dikembalikan.',
        'dipindahkan' => 'Permohonan pemindahan mahasiswa berhasil diajukan dan menunggu persetujuan unit penerima.'
    ];

    echo json_encode([
        'ok'      => true,
        'message' => $messages[$statusTarget] ?? 'Status pendaftaran berhasil diperbarui.'
    ]);

} catch (UserException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan saat memperbarui status pendaftar.')]);
}

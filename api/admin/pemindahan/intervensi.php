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

Auth::requireAdminApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$pemindahanId = isset($input['pemindahan_id']) ? (int)$input['pemindahan_id'] : 0;
$action = trim((string)($input['action'] ?? '')); // 'force_approve' atau 'force_reject'
$catatan = trim((string)($input['catatan'] ?? ''));

if ($pemindahanId <= 0 || !in_array($action, ['force_approve', 'force_reject'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'ID pemindahan dan tindakan intervensi wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $admin = Auth::getAdmin();
    $adminId = (int)($admin['id'] ?? 0);

    // Ambil info pemindahan untuk mendapatkan entitas tujuan
    $stmt = $pdo->prepare("
        SELECT pem.id, upp.entitas_id
        FROM pemindahan_peserta pem
        JOIN unit_pelaksana_periode upp ON pem.unit_tujuan_id = upp.id
        WHERE pem.id = ?
    ");
    $stmt->execute([$pemindahanId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new UserException('Data pemindahan tidak ditemukan.');
    }

    $targetEntitasId = (int)$row['entitas_id'];
    $isApprove = ($action === 'force_approve');
    $note = $catatan ? "Intervensi Admin BLKA: {$catatan}" : "Diproses melalui intervensi Administrator BLKA.";
    $overrideStatus = $isApprove ? 'force_blka' : null;

    Database::transaction(function (PDO $pdo) use ($pemindahanId, $isApprove, $note, $adminId, $targetEntitasId, $overrideStatus) {
        PenetapanHelper::responPemindahan($pdo, $pemindahanId, $isApprove, $note, $adminId, $targetEntitasId, $overrideStatus);
    });

    echo json_encode([
        'ok'      => true,
        'message' => $isApprove ? 'Pemindahan berhasil disetujui secara paksa oleh BLKA.' : 'Pemindahan berhasil dibatalkan oleh BLKA dan peserta dikembalikan ke unit asal.'
    ]);

} catch (UserException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan saat memproses intervensi pemindahan.')]);
}

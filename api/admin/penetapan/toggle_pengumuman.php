<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// 1. Auth Guard
Auth::requireAdminApi();

// 2. Method Guard
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan. Gunakan POST.']);
    exit;
}

// 3. CSRF Guard
Auth::requireCsrfApi();

// 4. Parse JSON Body
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format payload JSON tidak valid.']);
    exit;
}

$periodeId = isset($body['periode_id']) ? (int)$body['periode_id'] : 0;
$pengumumanDibuka = !empty($body['pengumuman_dibuka']) ? 1 : 0;

if ($periodeId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID Periode wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Pastikan periode ada
    $stmt = $pdo->prepare("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE id = ?");
    $stmt->execute([$periodeId]);
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        http_response_code(404);
        echo json_encode(['error' => 'Periode magang tidak ditemukan.']);
        exit;
    }

    // Update status pengumuman
    $stmtUpdate = $pdo->prepare("UPDATE periode SET pengumuman_dibuka = ? WHERE id = ?");
    $stmtUpdate->execute([$pengumumanDibuka, $periodeId]);

    // Catat log aktivitas
    $adminId = Auth::getAdminId();
    $adminNama = Auth::getAdminNama();
    $statusText = $pengumumanDibuka === 1 ? 'MEMPUBLIKASIKAN' : 'MENUTUP / MENONAKTIFKAN';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $stmtLog = $pdo->prepare("
        INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
        VALUES (?, 'toggle_pengumuman', 'periode', ?, ?, ?, NOW())
    ");
    $stmtLog->execute([
        $adminId > 0 ? $adminId : null,
        $periodeId,
        json_encode([
            'admin_nama'        => $adminNama,
            'status_aksi'       => $statusText,
            'periode_id'        => $periodeId,
            'periode_nama'      => $periode['nama'],
            'pengumuman_dibuka' => (bool)$pengumumanDibuka,
        ]),
        $ipAddress
    ]);

    $message = $pengumumanDibuka === 1
        ? "Pengumuman hasil penetapan periode '{$periode['nama']}' berhasil dipublikasikan kepada seluruh mahasiswa."
        : "Pengumuman hasil penetapan periode '{$periode['nama']}' telah dinonaktifkan (kembali ke status pending).";

    echo json_encode([
        'ok'                => true,
        'periode_id'        => $periodeId,
        'pengumuman_dibuka' => (bool)$pengumumanDibuka,
        'message'           => $message
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengubah status pengumuman.')]);
}


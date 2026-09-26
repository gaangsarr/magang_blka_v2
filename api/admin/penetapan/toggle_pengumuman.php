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

    $emailStats = ['new_count' => 0, 'update_count' => 0, 'skipped_count' => 0, 'total_queued' => 0];
    $cancelledCount = 0;

    if ($pengumumanDibuka === 1) {
        try {
            $emailStats = \App\EmailQueue::pushPengumumanPeriode($pdo, $periodeId);
        } catch (\Throwable $e) {
            error_log('[Toggle Pengumuman] Gagal antrekan email pengumuman: ' . $e->getMessage());
        }

        if ($emailStats['total_queued'] > 0) {
            $details = [];
            if ($emailStats['new_count'] > 0) {
                $details[] = "{$emailStats['new_count']} email baru";
            }
            if ($emailStats['update_count'] > 0) {
                $details[] = "{$emailStats['update_count']} email pembaruan";
            }
            if ($emailStats['skipped_count'] > 0) {
                $details[] = "{$emailStats['skipped_count']} data tidak berubah (di-skip)";
            }
            $detailStr = implode(', ', $details);
            $message = "Pengumuman periode '{$periode['nama']}' berhasil dipublikasikan. Antrean email: {$detailStr}.";
        } else {
            $message = "Pengumuman hasil penetapan periode '{$periode['nama']}' berhasil dipublikasikan. Mahasiswa dapat melihat hasil seleksi secara langsung di portal magang.";
        }
    } else {
        try {
            $cancelledCount = \App\EmailQueue::cancelPendingForPeriode($pdo, $periodeId);
        } catch (\Throwable $e) {
            error_log('[Toggle Pengumuman] Gagal membatalkan antrean email: ' . $e->getMessage());
        }

        $message = "Pengumuman hasil penetapan periode '{$periode['nama']}' telah dinonaktifkan."
            . ($cancelledCount > 0 ? " {$cancelledCount} antrean email yang belum sempat terkirim telah dibatalkan." : "");
    }

    echo json_encode([
        'ok'                => true,
        'periode_id'        => $periodeId,
        'pengumuman_dibuka' => (bool)$pengumumanDibuka,
        'email_stats'       => $emailStats,
        'emails_cancelled'  => $cancelledCount,
        'message'           => $message
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengubah status pengumuman.')]);
}


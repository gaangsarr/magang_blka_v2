<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

// Guard Admin (super_admin, admin_blka, admin_perusahaan)
Auth::requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

try {
    $admin = Auth::getAdmin();
    if (!$admin) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sesi admin tidak valid.']);
        exit;
    }

    $pendaftaranId = isset($_GET['pendaftaran_id']) ? (int)$_GET['pendaftaran_id'] : 0;
    if ($pendaftaranId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Parameter pendaftaran_id wajib disertakan.']);
        exit;
    }

    $pdo = Database::getInstance();

    $stmt = $pdo->prepare(
        "SELECT p.id, p.cv_path, upp.entitas_id, m.nim, m.nama 
         FROM pendaftaran p 
         JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id 
         JOIN mahasiswa m ON p.mahasiswa_id = m.id 
         WHERE p.id = :pid 
         LIMIT 1"
    );
    $stmt->execute([':pid' => $pendaftaranId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Data pendaftaran tidak ditemukan.']);
        exit;
    }

    // Role-based Access Control:
    // Admin Perusahaan hanya boleh mengakses pendaftar pada entitas miliknya
    if ($admin['role'] === 'admin_perusahaan') {
        $entitasId = (int)($admin['entitas_id'] ?? Auth::getPerusahaanEntitasId() ?? 0);
        if ($entitasId <= 0 || (int)$row['entitas_id'] !== $entitasId) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Akses ditolak. CV ini bukan milik pendaftar di entitas perusahaan Anda.']);
            exit;
        }
    }

    if (empty($row['cv_path'])) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Pendaftar belum menyertakan tautan atau file CV.']);
        exit;
    }

    // Jika berupa tautan web publik (Google Drive, OneDrive, dll), redirect langsung
    if (str_starts_with($row['cv_path'], 'http://') || str_starts_with($row['cv_path'], 'https://')) {
        header('Location: ' . $row['cv_path']);
        exit;
    }

    $filePath = $root . '/storage/' . $row['cv_path'];

    // SECURITY: Validasi realpath untuk mencegah path traversal
    $storageRoot = realpath($root . '/storage');
    $realFilePath = realpath($filePath);
    if (!$realFilePath || !$storageRoot || !str_starts_with($realFilePath, $storageRoot)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Akses file tidak diizinkan.']);
        exit;
    }

    if (!is_readable($realFilePath)) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'File CV fisik tidak ditemukan di server.']);
        exit;
    }

    // Bersihkan buffer sebelum streaming PDF
    if (ob_get_level()) {
        ob_end_clean();
    }

    $nim = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$row['nim']);
    $namaClean = preg_replace('/[^a-zA-Z0-9_]/', '_', trim((string)$row['nama']));
    $downloadName = 'CV_' . ($nim ?: 'Pendaftar') . '_' . substr($namaClean, 0, 20) . '.pdf';

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string)filesize($filePath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($filePath);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Terjadi kesalahan sistem saat membuka CV.']);
}

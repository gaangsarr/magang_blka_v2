<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

// Guard Mahasiswa
Auth::requireMahasiswaApi();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

try {
    $mahasiswaId = Auth::getMahasiswaId();
    $pdo = Database::getInstance();

    $pendaftaranId = isset($_GET['pendaftaran_id']) ? (int)$_GET['pendaftaran_id'] : 0;

    if ($pendaftaranId > 0) {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.cv_path, m.nim 
             FROM pendaftaran p 
             JOIN mahasiswa m ON p.mahasiswa_id = m.id 
             WHERE p.id = :pid AND p.mahasiswa_id = :mid 
             LIMIT 1"
        );
        $stmt->execute([':pid' => $pendaftaranId, ':mid' => $mahasiswaId]);
    } else {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.cv_path, m.nim 
             FROM pendaftaran p 
             JOIN mahasiswa m ON p.mahasiswa_id = m.id 
             WHERE p.mahasiswa_id = :mid 
             ORDER BY p.id DESC 
             LIMIT 1"
        );
        $stmt->execute([':mid' => $mahasiswaId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Data pendaftaran tidak ditemukan atau Anda tidak memiliki akses.']);
        exit;
    }

    if (empty($row['cv_path'])) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Berkas CV belum disertakan untuk pendaftaran ini.']);
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
        echo json_encode(['error' => 'File CV tidak ditemukan di server.']);
        exit;
    }

    // Bersihkan buffer sebelum streaming PDF
    if (ob_get_level()) {
        ob_end_clean();
    }

    $nim = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$row['nim']);
    $downloadName = 'CV_' . ($nim ?: 'Mahasiswa') . '.pdf';

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

<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\UserException;
use App\SuratMahasiswaGenerator;

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

    // Jika tidak spesifik, ambil pendaftaran terbaru mahasiswa yang diterima
    if ($pendaftaranId <= 0) {
        $stmtFind = $pdo->prepare("
            SELECT p.id 
            FROM pendaftaran p
            JOIN periode pr ON p.periode_id = pr.id
            WHERE p.mahasiswa_id = :mid 
              AND p.status IN ('diterima', 'dipindahkan')
              AND pr.pengumuman_dibuka = 1
            ORDER BY p.id DESC 
            LIMIT 1
        ");
        $stmtFind->execute([':mid' => $mahasiswaId]);
        $pendaftaranId = (int)$stmtFind->fetchColumn();

        if ($pendaftaranId <= 0) {
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Belum ada surat rekomendasi magang yang tersedia untuk akun Anda.']);
            exit;
        }
    }

    // Generate PDF dengan IDOR guard dan status validation
    $res = SuratMahasiswaGenerator::generatePdf($pdo, $pendaftaranId, $mahasiswaId);

    // Stream PDF ke browser
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $res['filename'] . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($res['pdf']));

    echo $res['pdf'];
    exit;

} catch (UserException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400;
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (\Throwable $err) {
    error_log('[download_surat_pengantar] ' . $err->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Gagal memproses dokumen surat pengantar magang. Silakan coba beberapa saat lagi.']);
    exit;
}

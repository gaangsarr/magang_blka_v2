<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\UserException;
use App\SuratPengantarGenerator;

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

    // Generate PDF Surat Pengantar Unit Mahasiswa
    $res = SuratPengantarGenerator::generatePdfForMahasiswa($pdo, $pendaftaranId, $mahasiswaId);

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

    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'text/html')) {
        header('Content-Type: text/html; charset=utf-8');
        $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$msg} — INTERN ITPLN</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            box-sizing: border-box;
        }
        .error-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            max-width: 440px;
            width: 100%;
            padding: 32px 28px;
            text-align: center;
        }
        .error-icon {
            width: 56px;
            height: 56px;
            background: #fef3c7;
            color: #d97706;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }
        .error-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0b192c;
            margin: 0 0 10px 0;
        }
        .error-desc {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 24px 0;
        }
        .btn-back {
            display: inline-block;
            background: #0b192c;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn-back:hover {
            background: #1e3a8a;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h1 class="error-title">{$msg}</h1>
        <p class="error-desc">
            Dokumen surat pengantar resmi saat ini sedang dipersiapkan dan diadministrasikan oleh BLKA Institut Teknologi PLN. Silakan periksa kembali beberapa saat lagi.
        </p>
        <a href="/status.html" class="btn-back">Kembali ke Halaman Status</a>
    </div>
</body>
</html>
HTML;
        exit;
    }

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

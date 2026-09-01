<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\SuratGenerator;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::requireAdminApi();

$periodeId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
$targetType = trim((string)($_GET['target_type'] ?? ''));
$targetId = isset($_GET['target_id']) ? (int)$_GET['target_id'] : 0;

// Fallback untuk backward compatibility jika parameter unit_id dikirimkan
if ($targetType === '' && isset($_GET['unit_id'])) {
    $targetType = 'holding_unit';
    $targetId = 0;
}

if ($periodeId <= 0 || $targetType === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parameter periode_id dan target_type diperlukan.';
    exit;
}

try {
    $pdo = Database::getInstance();

    $docxPath = SuratGenerator::generateDocxByTarget($pdo, $periodeId, $targetType, $targetId);

    if (!file_exists($docxPath)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gagal menghasilkan file dokumen surat.';
        exit;
    }

    $downloadFileName = basename($docxPath);

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $downloadFileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($docxPath));

    readfile($docxPath);

    // Cleanup temp file
    @unlink($docxPath);
    @rmdir(dirname($docxPath));
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat mendownload surat.');
}

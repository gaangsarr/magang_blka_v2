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

if ($periodeId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parameter periode_id diperlukan.';
    exit;
}

try {
    $pdo = Database::getInstance();
    $zipPath = SuratGenerator::generateAllZip($pdo, $periodeId);

    if (!file_exists($zipPath)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gagal menghasilkan file arsip ZIP.';
        exit;
    }

    $downloadFileName = 'Surat_Penempatan_Seluruh_Unit_Periode_' . $periodeId . '_' . date('Ymd_His') . '.zip';

    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadFileName . '"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($zipPath));

    readfile($zipPath);

    // Cleanup temp zip
    @unlink($zipPath);
    @rmdir(dirname($zipPath));
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat membuat arsip ZIP surat penempatan.');
}

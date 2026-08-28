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
$unitId = isset($_GET['unit_id']) ? (int)$_GET['unit_id'] : 0;

if ($periodeId <= 0 || $unitId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Parameter periode_id dan unit_id diperlukan.';
    exit;
}

try {
    $pdo = Database::getInstance();
    $config = SuratGenerator::getConfig($pdo, $periodeId);
    $units = SuratGenerator::getUnitsSummary($pdo, $periodeId);

    $targetUnit = null;
    $unitIndex = 0;
    foreach ($units as $idx => $u) {
        if ((int)$u['unit_id'] === $unitId) {
            $targetUnit = $u;
            $unitIndex = $idx;
            break;
        }
    }

    if (!$targetUnit) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Data unit tidak ditemukan atau tidak memiliki mahasiswa berstatus diterima pada periode ini.';
        exit;
    }

    $students = SuratGenerator::getStudentsForUnit($pdo, $periodeId, $unitId);
    if (empty($students)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Tidak ada data mahasiswa diterima untuk unit ini.';
        exit;
    }

    $docxPath = SuratGenerator::generateDocxForUnit($config, $targetUnit, $students, $unitIndex);

    if (!file_exists($docxPath)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gagal menghasilkan file dokumen surat.';
        exit;
    }

    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $targetUnit['unit_nama']);
    $downloadFileName = 'Surat_Penempatan_' . $safeName . '_' . date('Ymd') . '.docx';

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

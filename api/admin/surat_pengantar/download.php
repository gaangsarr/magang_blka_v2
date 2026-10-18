<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\UserException;
use App\SuratPengantarGenerator;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::requireBlkaApi();

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
    $res = SuratPengantarGenerator::generatePdfForUnit($pdo, $periodeId, $unitId);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $res['filename'] . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($res['pdf']));

    echo $res['pdf'];
    exit;
} catch (UserException $e) {
    http_response_code($e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat membuat dokumen PDF surat pengantar.');
}

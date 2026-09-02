<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\Models\PicNarahubung;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $currentPicId = isset($_GET['current_pic_id']) ? (int)$_GET['current_pic_id'] : null;
    $units = PicNarahubung::getAvailableUnits($currentPicId);

    echo json_encode([
        'ok'   => true,
        'data' => $units
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat opsi unit entitas.')]);
}

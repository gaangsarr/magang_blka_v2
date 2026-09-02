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
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$id = (int)($body['id'] ?? 0);

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID PIC Narahubung tidak valid.']);
    exit;
}

try {
    $existing = PicNarahubung::getById($id);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Data PIC Narahubung tidak ditemukan.']);
        exit;
    }

    PicNarahubung::delete($id);

    echo json_encode([
        'ok'      => true,
        'message' => 'Data PIC Narahubung berhasil dihapus.'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menghapus data PIC Narahubung.')]);
}

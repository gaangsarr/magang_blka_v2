<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\SuratGenerator;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan. Gunakan POST.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['periode_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Data input tidak lengkap (periode_id diperlukan).']);
    exit;
}

$periodeId = (int)$input['periode_id'];
if ($periodeId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID Periode tidak valid.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $saved = SuratGenerator::saveConfig($pdo, $periodeId, $input);

    if ($saved) {
        $updatedConfig = SuratGenerator::getConfig($pdo, $periodeId);
        echo json_encode([
            'ok' => true,
            'message' => 'Konfigurasi surat berhasil disimpan.',
            'config' => $updatedConfig
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal menyimpan konfigurasi surat.']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat menyimpan konfigurasi surat.')]);
}

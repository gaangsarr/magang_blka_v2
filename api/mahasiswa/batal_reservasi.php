<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['reservasi_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'reservasi_id wajib diisi.']);
    exit;
}

$reservasiId = (int)$body['reservasi_id'];
$mahasiswaId = Auth::getMahasiswaId();

try {
    $result = Database::transaction(function (PDO $pdo) use ($mahasiswaId, $reservasiId) {
        return \App\ReservasiHelper::batalkanReservasi($pdo, $reservasiId, $mahasiswaId);
    });

    if (!($result['ok'] ?? false)) {
        http_response_code(400);
        echo json_encode(['error' => $result['error'] ?? 'Gagal membatalkan reservasi.']);
        exit;
    }

    echo json_encode([
        'ok'           => true,
        'already_done' => $result['already_done'] ?? false,
        'message'      => $result['message'] ?? 'Reservasi berhasil dibatalkan dan kuota dikembalikan.'
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

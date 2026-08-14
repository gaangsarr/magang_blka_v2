<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::startSession(startPHP: true);
if (!Auth::isLoggedInMahasiswa() && !Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil daftar peminatan
    $stmt = $pdo->prepare("SELECT id, nama, deskripsi FROM peminatan WHERE aktif = 1 ORDER BY nama ASC");
    $stmt->execute();
    $peminatan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'data' => $peminatan
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

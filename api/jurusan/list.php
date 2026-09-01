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

try {
    $pdo = Database::getInstance();
    
    // Ambil daftar jurusan/prodi aktif
    $stmt = $pdo->query("SELECT id, kode, nama_jurusan, aktif FROM jurusan WHERE aktif = 1 ORDER BY nama_jurusan ASC");
    $jurusan = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'data' => $jurusan
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem saat memuat data program studi.']);
}

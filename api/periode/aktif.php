<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Gunakan requireMahasiswaApi jika otentikasi wajib.
Auth::requireMahasiswaApi();

try {
    $pdo = Database::getInstance();
    
    // Cari periode yang sedang dibuka
    $stmt = $pdo->prepare("SELECT id, nama, program_1_bulan, program_5_bulan FROM periode WHERE status = 'dibuka' LIMIT 1");
    $stmt->execute();
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$periode) {
        http_response_code(404);
        echo json_encode(['error' => 'Saat ini tidak ada periode pendaftaran yang dibuka.']);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'periode' => [
            'id' => (int) $periode['id'],
            'nama' => $periode['nama'],
            'program_1_bulan' => (bool) $periode['program_1_bulan'],
            'program_5_bulan' => (bool) $periode['program_5_bulan'],
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

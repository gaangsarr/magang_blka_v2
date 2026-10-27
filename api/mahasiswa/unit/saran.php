<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PengajuanUnitHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard auth mahasiswa
Auth::requireMahasiswaApi();
$mhsId = Auth::getMahasiswaId();
Auth::rateLimitByIdentity((string)$mhsId, 'mhs_saran_unit', 30, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));

// Minimal 3 karakter untuk memicu saran fuzzy
if (mb_strlen($q, 'UTF-8') < 3) {
    echo json_encode([
        'ok'   => true,
        'data' => []
    ]);
    exit;
}

try {
    $pdo = Database::getInstance();
    $candidates = PengajuanUnitHelper::findSimilar($pdo, $q, 8);

    // Sanitasi data: pastikan tidak membocorkan data PIC atau hal sensitif
    $sanitized = array_map(function ($item) {
        return [
            'sumber'           => $item['sumber'], // 'entitas' | 'usulan'
            'id'               => (int)$item['id'],
            'nama'             => $item['nama'],
            'singkatan'        => $item['singkatan'] ?? null,
            'tipe'             => $item['tipe'],
            'alamat'           => $item['alamat'],
            'skor'             => (float)$item['skor'],
            'jumlah_pendukung' => (int)$item['jumlah_pendukung'],
        ];
    }, $candidates);

    echo json_encode([
        'ok'   => true,
        'data' => $sanitized
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mencari saran unit.')]);
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();

try {
    $mahasiswa = Auth::getMahasiswa();
    
    if (!$mahasiswa) {
        http_response_code(401);
        echo json_encode(['error' => 'Data mahasiswa tidak ditemukan.']);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'data' => [
            'nim' => $mahasiswa['nim'],
            'nama' => $mahasiswa['nama'],
            'email' => $mahasiswa['email'],
            'angkatan' => $mahasiswa['angkatan'],
            'jurusan' => $mahasiswa['jurusan_nama'],
            'needs_nama' => (bool)$mahasiswa['needs_nama']
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

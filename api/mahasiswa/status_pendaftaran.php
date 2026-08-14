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

try {
    $mahasiswaId = Auth::getMahasiswaId();
    $pdo = Database::getInstance();
    
    $stmt = $pdo->prepare("
        SELECT 
            p.status, 
            p.submitted_at, 
            p.program,
            p.nama_snapshot AS nama,
            m.nim,
            p.is_dipindahkan,
            p.catatan_admin,
            upp.kuota_tersisa,
            e.nama AS nama_unit,
            e_asal.nama AS nama_unit_asal
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN periode pr ON p.periode_id = pr.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        WHERE p.mahasiswa_id = :mid AND pr.status = 'dibuka'
        LIMIT 1
    ");
    $stmt->execute([':mid' => $mahasiswaId]);
    $pendaftaran = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$pendaftaran) {
        echo json_encode(['ok' => true, 'terdaftar' => false]);
        exit;
    }
    
    echo json_encode([
        'ok' => true,
        'terdaftar' => true,
        'data' => [
            'status' => $pendaftaran['status'],
            'program' => $pendaftaran['program'],
            'nama' => $pendaftaran['nama'],
            'nim' => $pendaftaran['nim'],
            'nama_unit' => $pendaftaran['nama_unit'],
            'nama_unit_asal' => $pendaftaran['nama_unit_asal'],
            'is_dipindahkan' => (bool)$pendaftaran['is_dipindahkan'],
            'catatan_admin' => $pendaftaran['catatan_admin'],
            'submitted_at' => $pendaftaran['submitted_at']
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

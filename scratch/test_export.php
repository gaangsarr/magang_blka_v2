<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

try {
    $pdo = Database::getInstance();

    // Query 1: Pendaftar
    $stmt1 = $pdo->query("
        SELECT 
            p.id, m.nim, p.nama_snapshot AS nama, m.email, p.no_hp, j.nama_jurusan AS jurusan, p.program, e.nama AS unit_nama, p.status, p.submitted_at
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
    ");
    $rows1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);
    echo "Pendaftar rows: " . count($rows1) . "\n";

    // Query 2: Penetapan
    $stmt2 = $pdo->query("
        SELECT 
            p.id, m.nim, p.nama_snapshot AS nama, m.email, p.no_hp, j.nama_jurusan AS jurusan, p.program, p.status, p.is_dipindahkan, p.catatan_admin, p.submitted_at, e.nama AS unit_nama, e_asal.nama AS unit_asal_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
    ");
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "Penetapan rows: " . count($rows2) . "\n";

    echo "ALL EXPORT QUERIES VALID!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

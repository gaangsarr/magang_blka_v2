<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (empty($body['nama']) || empty($body['tanggal_mulai']) || empty($body['tanggal_selesai'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama, tanggal mulai, dan tanggal selesai wajib diisi.']);
    exit;
}

$nama = trim($body['nama']);
$tglMulai = $body['tanggal_mulai'];
$tglSelesai = $body['tanggal_selesai'];
$prog1 = isset($body['program_1_bulan']) ? (int)(bool)$body['program_1_bulan'] : 0;
$prog5 = isset($body['program_5_bulan']) ? (int)(bool)$body['program_5_bulan'] : 0;
$copyFromPeriodeId = isset($body['copy_from_periode_id']) ? (int)$body['copy_from_periode_id'] : null;

// Parse angkatan_eligible (bisa array atau string dipisah koma)
$angkatanInput = $body['angkatan_eligible'] ?? null;
$angkatanEligible = null;
if (is_array($angkatanInput)) {
    $angkatanEligible = implode(',', array_filter(array_map('trim', $angkatanInput)));
} elseif (is_string($angkatanInput)) {
    $angkatanEligible = trim($angkatanInput);
}

$adminId = Auth::getAdminId();

try {
    Database::transaction(function (PDO $pdo) use ($nama, $tglMulai, $tglSelesai, $prog1, $prog5, $angkatanEligible, $copyFromPeriodeId, $adminId) {
        // Tutup periode lain yang sedang dibuka atau persiapan (hanya 1 periode yang boleh aktif)
        $pdo->query("UPDATE periode SET status = 'ditutup' WHERE status IN ('dibuka', 'persiapan')");

        $stmt = $pdo->prepare("
            INSERT INTO periode (nama, tanggal_mulai, tanggal_selesai, status, program_1_bulan, program_5_bulan, angkatan_eligible) 
            VALUES (?, ?, ?, 'persiapan', ?, ?, ?)
        ");
        $stmt->execute([$nama, $tglMulai, $tglSelesai, $prog1, $prog5, $angkatanEligible]);
        
        $periodeId = $pdo->lastInsertId();
        
        // Fitur Salin Kuota
        if ($copyFromPeriodeId) {
            $stmtCopy = $pdo->prepare("
                INSERT INTO unit_pelaksana_periode (periode_id, entitas_id, kuota_total, kuota_tersisa, aktif)
                SELECT ?, entitas_id, kuota_total, kuota_total, aktif
                FROM unit_pelaksana_periode
                WHERE periode_id = ?
            ");
            $stmtCopy->execute([$periodeId, $copyFromPeriodeId]);
        }

        $stmtLog = $pdo->prepare("INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address) VALUES (?, 'buat_periode', 'periode', ?, ?, '127.0.0.1')");
        $stmtLog->execute([$adminId, $periodeId, json_encode(['nama' => $nama, 'angkatan_eligible' => $angkatanEligible, 'copied_from' => $copyFromPeriodeId])]);
    });
    
    echo json_encode([
        'ok' => true,
        'message' => 'Periode berhasil dibuat (status draft).'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireAdminApi(); // QUALITY-01: standardisasi auth guard
Auth::requireCsrfApi();  // BLOCKER-05: CSRF protection

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
$jamSelesai = !empty($body['jam_selesai']) ? trim((string)$body['jam_selesai']) : '23:59:00';
if (preg_match('/^\d{2}:\d{2}$/', $jamSelesai)) {
    $jamSelesai .= ':00';
}
if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $jamSelesai)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format jam selesai tidak valid (contoh: 23:59).']);
    exit;
}

$prog1 = isset($body['program_1_bulan']) ? (int)(bool)$body['program_1_bulan'] : 0;
$prog5 = isset($body['program_5_bulan']) ? (int)(bool)$body['program_5_bulan'] : 0;
$syaratTranskrip = isset($body['syarat_transkrip']) ? (int)(bool)$body['syarat_transkrip'] : 1;
$syaratCv = isset($body['syarat_cv']) ? (int)(bool)$body['syarat_cv'] : 0;
$syaratPorto = isset($body['syarat_porto']) ? (int)(bool)$body['syarat_porto'] : 0;
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
    Database::transaction(function (PDO $pdo) use ($nama, $tglMulai, $tglSelesai, $jamSelesai, $prog1, $prog5, $syaratTranskrip, $syaratCv, $syaratPorto, $angkatanEligible, $copyFromPeriodeId, $adminId) {
        // Tutup periode lain yang sedang dibuka atau persiapan (hanya 1 periode yang boleh aktif)
        $pdo->query("UPDATE periode SET status = 'ditutup' WHERE status IN ('dibuka', 'persiapan')");

        $stmt = $pdo->prepare("
            INSERT INTO periode (nama, tanggal_mulai, tanggal_selesai, jam_selesai, status, program_1_bulan, program_5_bulan, syarat_transkrip, syarat_cv, syarat_porto, angkatan_eligible) 
            VALUES (?, ?, ?, ?, 'persiapan', ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$nama, $tglMulai, $tglSelesai, $jamSelesai, $prog1, $prog5, $syaratTranskrip, $syaratCv, $syaratPorto, $angkatanEligible]);
        
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

        // Inisialisasi token & konfigurasi surat otomatis untuk periode baru
        \App\SuratGenerator::getConfig($pdo, (int)$periodeId);
    });
    
    echo json_encode([
        'ok' => true,
        'message' => 'Periode berhasil dibuat (status persiapan).'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal membuat periode.')]);
}


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

if (empty($body['id']) || empty($body['nama']) || empty($body['tanggal_mulai']) || empty($body['tanggal_selesai'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID, Nama, tanggal mulai, dan tanggal selesai wajib diisi.']);
    exit;
}

$id = (int)$body['id'];
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
$prog3 = isset($body['program_3_bulan']) ? (int)(bool)$body['program_3_bulan'] : 0;
$prog4 = isset($body['program_4_bulan']) ? (int)(bool)$body['program_4_bulan'] : 0;
$prog5 = isset($body['program_5_bulan']) ? (int)(bool)$body['program_5_bulan'] : 0;
$syaratTranskrip = isset($body['syarat_transkrip']) ? (int)(bool)$body['syarat_transkrip'] : 1;
$syaratCv = isset($body['syarat_cv']) ? (int)(bool)$body['syarat_cv'] : 0;
$syaratPorto = isset($body['syarat_porto']) ? (int)(bool)$body['syarat_porto'] : 0;

$angkatanInput = $body['angkatan_eligible'] ?? null;
$angkatanEligible = null;
if (is_array($angkatanInput)) {
    $angkatanEligible = implode(',', array_filter(array_map('trim', $angkatanInput)));
} elseif (is_string($angkatanInput)) {
    $angkatanEligible = trim($angkatanInput);
}

$adminId = Auth::getAdminId();

try {
    $pdo = Database::getInstance();
    
    $stmtCur = $pdo->prepare("SELECT status FROM periode WHERE id = ?");
    $stmtCur->execute([$id]);
    $currentPeriod = $stmtCur->fetch(PDO::FETCH_ASSOC);
    if (!$currentPeriod) {
        http_response_code(404);
        echo json_encode(['error' => 'Periode tidak ditemukan.']);
        exit;
    }

    $curStatus = (string)$currentPeriod['status'];
    $targetStatus = !empty($body['status']) ? (string)$body['status'] : $curStatus;
    $validStatus = ['draft', 'persiapan', 'dibuka', 'ditutup', 'diarsipkan'];
    if (!in_array($targetStatus, $validStatus, true)) {
        $targetStatus = $curStatus;
    }

    $isExpired = \App\PeriodeHelper::isPeriodeExpired([
        'tanggal_selesai' => $tglSelesai,
        'jam_selesai'     => $jamSelesai
    ]);

    // Jika waktu penutupan sudah lewat, status TIDAK BISA 'dibuka'
    if ($isExpired && $targetStatus === 'dibuka') {
        $targetStatus = 'ditutup';
    }

    // Jika status adalah dibuka atau persiapan, pastikan hanya 1 periode yang aktif
    if (in_array($targetStatus, ['dibuka', 'persiapan'], true)) {
        $stmtTutupLain = $pdo->prepare("UPDATE periode SET status = 'ditutup' WHERE status IN ('dibuka', 'persiapan') AND id != ?");
        $stmtTutupLain->execute([$id]);
    }

    $stmt = $pdo->prepare("
        UPDATE periode 
        SET 
            nama = ?, 
            tanggal_mulai = ?, 
            tanggal_selesai = ?, 
            jam_selesai = ?, 
            status = ?,
            program_1_bulan = ?, 
            program_3_bulan = ?, 
            program_4_bulan = ?, 
            program_5_bulan = ?, 
            syarat_transkrip = ?,
            syarat_cv = ?,
            syarat_porto = ?,
            angkatan_eligible = ? 
        WHERE id = ?
    ");
    $stmt->execute([$nama, $tglMulai, $tglSelesai, $jamSelesai, $targetStatus, $prog1, $prog3, $prog4, $prog5, $syaratTranskrip, $syaratCv, $syaratPorto, $angkatanEligible, $id]);

    // Jika periode ditutup karena expired, jalankan cleanup reservasi & kuota
    if ($isExpired) {
        \App\PeriodeHelper::closeExpiredPeriodes($pdo);
    }

    $msg = 'Data periode berhasil diperbarui.';
    if ($isExpired && !empty($body['status']) && $body['status'] === 'dibuka') {
        $msg = 'Data periode berhasil diperbarui. Status otomatis diset Ditutup karena waktu penutupan telah lewat.';
    } elseif ($targetStatus === 'dibuka') {
        $msg = 'Data periode berhasil diperbarui (Status: Dibuka).';
    }

    echo json_encode([
        'ok' => true,
        'message' => $msg
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data periode.')]);
}


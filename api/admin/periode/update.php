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
$prog1 = isset($body['program_1_bulan']) ? (int)(bool)$body['program_1_bulan'] : 0;
$prog5 = isset($body['program_5_bulan']) ? (int)(bool)$body['program_5_bulan'] : 0;

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
    
    $stmt = $pdo->prepare("
        UPDATE periode 
        SET 
            nama = ?, 
            tanggal_mulai = ?, 
            tanggal_selesai = ?, 
            program_1_bulan = ?, 
            program_5_bulan = ?, 
            angkatan_eligible = ? 
        WHERE id = ?
    ");
    $stmt->execute([$nama, $tglMulai, $tglSelesai, $prog1, $prog5, $angkatanEligible, $id]);

    echo json_encode([
        'ok' => true,
        'message' => 'Data periode berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data periode.')]);
}


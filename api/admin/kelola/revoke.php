<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireSuperAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$adminId = $input['admin_id'] ?? null;

if (!$adminId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter admin_id wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $adminId = (int)$adminId;
    $currentAdminId = Auth::getAdminId();

    if ($adminId === $currentAdminId) {
        http_response_code(400);
        echo json_encode(['error' => 'Anda tidak dapat mencabut hak akses admin milik diri sendiri.']);
        exit;
    }

    // Ambil info admin target
    $stmtCheck = $pdo->prepare("SELECT id, nama, email FROM admin WHERE id = :id LIMIT 1");
    $stmtCheck->execute([':id' => $adminId]);
    $targetAdmin = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$targetAdmin) {
        http_response_code(404);
        echo json_encode(['error' => 'Akun admin tidak ditemukan.']);
        exit;
    }

    // Nonaktifkan akses admin (soft delete / aktif = 0)
    $stmtUpd = $pdo->prepare("UPDATE admin SET aktif = 0, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([':id' => $adminId]);

    echo json_encode([
        'ok'      => true,
        'message' => "Hak akses admin untuk {$targetAdmin['nama']} ({$targetAdmin['email']}) berhasil dicabut.",
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mencabut hak akses admin: ' . $e->getMessage()]);
}

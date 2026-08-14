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

if (empty($body['id']) || empty($body['status'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID periode dan status wajib diisi.']);
    exit;
}

$id = (int)$body['id'];
$status = $body['status'];
$validStatus = ['draft', 'persiapan', 'dibuka', 'ditutup', 'diarsipkan'];

if (!in_array($status, $validStatus, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid.']);
    exit;
}

$adminId = Auth::getAdminId();

try {
    Database::transaction(function (PDO $pdo) use ($id, $status, $adminId) {
        if ($status === 'dibuka') {
            // Tutup periode lain yang sedang dibuka (hanya 1 yang boleh buka)
            $stmtTutup = $pdo->prepare("UPDATE periode SET status = 'ditutup' WHERE status = 'dibuka' AND id != ?");
            $stmtTutup->execute([$id]);
        }
        
        $stmt = $pdo->prepare("UPDATE periode SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        
        $stmtLog = $pdo->prepare("INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address) VALUES (?, 'ubah_status_periode', 'periode', ?, ?, '127.0.0.1')");
        $stmtLog->execute([$adminId, $id, json_encode(['status_baru' => $status])]);
    });
    
    echo json_encode([
        'ok' => true,
        'message' => 'Status periode berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

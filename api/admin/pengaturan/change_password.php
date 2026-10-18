<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

Auth::requireAdminApi();
Auth::requireSuperAdminApi();

$admin = Auth::getAdmin();
if (!$admin) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesi admin tidak valid.']);
    exit;
}

$adminId = (int)$admin['id'];
$pdo = Database::getInstance();

$stmt = $pdo->prepare("SELECT id, nama, email, username, role, password_hash FROM admin WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$adminRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$adminRow) {
    http_response_code(404);
    echo json_encode(['error' => 'Data akun admin tidak ditemukan.']);
    exit;
}

// GET: Cek status password akun superadmin saat ini
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok'           => true,
        'id'           => (int)$adminRow['id'],
        'nama'         => $adminRow['nama'],
        'email'        => $adminRow['email'] ?? '',
        'username'     => $adminRow['username'] ?? '',
        'has_password' => !empty($adminRow['password_hash']),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan. Gunakan GET atau POST.']);
    exit;
}

Auth::requireCsrfApi();

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword     = (string)($input['new_password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    $hasExistingPassword = !empty($adminRow['password_hash']);

    // Jika sudah memiliki password, verifikasi password saat ini
    if ($hasExistingPassword) {
        if ($currentPassword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Password saat ini wajib diisi.']);
            exit;
        }

        if (!password_verify($currentPassword, $adminRow['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Password saat ini tidak sesuai.']);
            exit;
        }
    }

    if ($newPassword === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Password baru wajib diisi.']);
        exit;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'Password baru minimal 6 karakter.']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Konfirmasi password baru tidak cocok.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmtUpd = $pdo->prepare("UPDATE admin SET password_hash = :hash, force_password_change = 0, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([
        ':hash' => $newHash,
        ':id'   => $adminId,
    ]);

    // Catat log aktivitas audit trail
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmtLog = $pdo->prepare("
        INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
        VALUES (?, 'ganti_password_superadmin', 'admin', ?, ?, ?, NOW())
    ");
    $stmtLog->execute([
        $adminId,
        $adminId,
        json_encode([
            'email'    => $adminRow['email'],
            'username' => $adminRow['username'],
            'action'   => $hasExistingPassword ? 'change_password' : 'set_initial_password',
        ], JSON_UNESCAPED_UNICODE),
        $ip,
    ]);

    echo json_encode([
        'ok'      => true,
        'message' => 'Password Super Admin berhasil diperbarui.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat memperbarui password.')]);
}

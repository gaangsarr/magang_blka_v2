<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireProdiApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan. Gunakan POST.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    Auth::requireCsrfApi();

    $currentPassword = (string)($input['current_password'] ?? '');
    $newPassword     = (string)($input['new_password'] ?? '');
    $confirmPassword = (string)($input['confirm_password'] ?? '');

    if ($currentPassword === '' || $newPassword === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Password saat ini dan password baru wajib diisi.']);
        exit;
    }

    if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['error' => 'Password baru minimal 8 karakter.']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['error' => 'Konfirmasi password baru tidak cocok.']);
        exit;
    }

    $adminId = Auth::getAdminId();
    if (!$adminId) {
        http_response_code(401);
        echo json_encode(['error' => 'Sesi admin tidak valid.']);
        exit;
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT password_hash FROM admin WHERE id = ? LIMIT 1");
    $stmt->execute([$adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Password saat ini salah.']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmtUpd = $pdo->prepare("UPDATE admin SET password_hash = :hash, force_password_change = 0, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([
        ':hash' => $newHash,
        ':id'   => $adminId
    ]);

    echo json_encode([
        'ok'      => true,
        'message' => 'Password akun Program Studi berhasil diperbarui.'
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui password.')]);
}

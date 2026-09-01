<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Hanya admin_perusahaan yang sedang login yang boleh memanggil endpoint ini
Auth::requirePerusahaanApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$newPassword = trim((string)($input['new_password'] ?? ''));
$confirmPassword = trim((string)($input['confirm_password'] ?? ''));
$picNama = trim((string)($input['pic_nama'] ?? ''));
$picJabatan = trim((string)($input['pic_jabatan'] ?? ''));
$picKontak = trim((string)($input['pic_kontak'] ?? ''));
$picEmail = trim((string)($input['pic_email'] ?? ''));

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['error' => 'Password baru minimal harus 8 karakter.']);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode(['error' => 'Konfirmasi password tidak cocok dengan password baru.']);
    exit;
}

if ($picNama === '' || $picKontak === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama PIC dan Nomor WhatsApp/HP PIC wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $adminId = Auth::getAdminId();
    $entitasId = Auth::getPerusahaanEntitasId();

    if ($adminId <= 0 || !$entitasId) {
        http_response_code(403);
        echo json_encode(['error' => 'Sesi tidak valid atau entitas tidak terhubung.']);
        exit;
    }

    $pdo->beginTransaction();

    // 1. Update password di tabel admin & matikan force_password_change
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmtAdmin = $pdo->prepare("
        UPDATE admin
        SET password_hash = :p_hash, force_password_change = 0, updated_at = NOW()
        WHERE id = :aid
    ");
    $stmtAdmin->execute([
        ':p_hash' => $passwordHash,
        ':aid'    => $adminId,
    ]);

    // 2. Update data PIC di tabel entitas_perusahaan
    $stmtEntitas = $pdo->prepare("
        UPDATE entitas_perusahaan
        SET 
            pic_nama = :pic_nama,
            pic_jabatan = :pic_jabatan,
            pic_kontak = :pic_kontak,
            pic_email = :pic_email,
            updated_at = NOW()
        WHERE id = :eid
    ");
    $stmtEntitas->execute([
        ':pic_nama'    => $picNama,
        ':pic_jabatan' => $picJabatan ?: null,
        ':pic_kontak'  => $picKontak,
        ':pic_email'   => $picEmail ?: null,
        ':eid'         => $entitasId,
    ]);

    $pdo->commit();

    // Perbarui session
    $_SESSION['force_password_change'] = false;

    echo json_encode([
        'ok'      => true,
        'message' => 'Password dan data PIC berhasil diperbarui. Selamat datang di Dashboard Mitra Perusahaan!',
    ]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data akun.')]);
}

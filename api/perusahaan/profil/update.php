<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requirePerusahaanApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mode = $input['mode'] ?? 'profile'; // 'profile' atau 'password'

$pdo = Database::getInstance();
$entitasId = Auth::getPerusahaanEntitasId();
$adminId = Auth::getAdminId();

try {
    if ($mode === 'password') {
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
            echo json_encode(['error' => 'Konfirmasi password tidak cocok.']);
            exit;
        }

        $stmtA = $pdo->prepare("SELECT password_hash FROM admin WHERE id = ? LIMIT 1");
        $stmtA->execute([$adminId]);
        $row = $stmtA->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Password saat ini salah.']);
            exit;
        }

        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmtUpd = $pdo->prepare("UPDATE admin SET password_hash = :hash, force_password_change = 0, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':hash' => $newHash, ':id' => $adminId]);

        echo json_encode([
            'ok' => true,
            'message' => 'Password akun berhasil diubah.'
        ]);
        exit;
    }

    // Default mode: Profile update
    $alamat     = trim((string)($input['alamat'] ?? ''));
    $latitude   = isset($input['latitude']) && $input['latitude'] !== '' ? (float)$input['latitude'] : null;
    $longitude  = isset($input['longitude']) && $input['longitude'] !== '' ? (float)$input['longitude'] : null;
    $picNama    = trim((string)($input['pic_nama'] ?? ''));
    $picJabatan = trim((string)($input['pic_jabatan'] ?? ''));
    $picKontak  = trim((string)($input['pic_kontak'] ?? ''));
    $picEmail   = trim((string)($input['pic_email'] ?? ''));
    $menerimaMagang = isset($input['menerima_magang']) ? ((bool)$input['menerima_magang'] ? 1 : 0) : 1;

    if ($picNama === '' || $picKontak === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nama PIC dan Nomor Kontak PIC wajib diisi.']);
        exit;
    }

    $stmtUpdE = $pdo->prepare("
        UPDATE entitas_perusahaan
        SET 
            alamat = :alamat,
            latitude = :lat,
            longitude = :lng,
            pic_nama = :pic_nama,
            pic_jabatan = :pic_jabatan,
            pic_kontak = :pic_kontak,
            pic_email = :pic_email,
            menerima_magang = :menerima,
            updated_at = NOW()
        WHERE id = :eid
    ");
    $stmtUpdE->execute([
        ':alamat'      => $alamat ?: null,
        ':lat'         => $latitude,
        ':lng'         => $longitude,
        ':pic_nama'    => $picNama,
        ':pic_jabatan' => $picJabatan ?: null,
        ':pic_kontak'  => $picKontak,
        ':pic_email'   => $picEmail ?: null,
        ':menerima'    => $menerimaMagang,
        ':eid'         => $entitasId,
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Profil perusahaan dan kontak PIC berhasil diperbarui.'
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui profil.')]);
}

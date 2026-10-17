<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $admin = Auth::getAdmin();
    if (!$admin) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $isSuperAdmin = ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin');
    $isPerusahaan = ($admin['role'] === 'admin_perusahaan');
    $isProdi      = ($admin['role'] === 'admin_prodi');
    
    $roleLabel = 'Admin BLKA';
    if ($isSuperAdmin) {
        $roleLabel = 'Super Admin BLKA';
    } elseif ($isPerusahaan) {
        $roleLabel = 'Admin Mitra Perusahaan';
    } elseif ($isProdi) {
        $roleLabel = 'Admin Program Studi (' . ($admin['jurusan_jenjang'] ?? '') . ' ' . ($admin['nama_jurusan'] ?? '') . ')';
    }

    $entitasData = null;
    if (!empty($admin['entitas_id'])) {
        $pdo = Database::getInstance();
        $stmtE = $pdo->prepare("SELECT id, nama, singkatan, tipe, alamat FROM entitas_perusahaan WHERE id = ?");
        $stmtE->execute([(int)$admin['entitas_id']]);
        $entitasData = $stmtE->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    $jurusanData = null;
    if (!empty($admin['jurusan_id'])) {
        $jurusanData = [
            'id'      => (int)$admin['jurusan_id'],
            'nama'    => $admin['nama_jurusan'] ?? '',
            'kode'    => $admin['jurusan_kode'] ?? '',
            'jenjang' => $admin['jurusan_jenjang'] ?? '',
        ];
    }

    echo json_encode([
        'ok'             => true,
        'admin'          => [
            'id'                    => (int)$admin['id'],
            'nama'                  => $admin['nama'],
            'email'                 => $admin['email'] ?? '',
            'username'              => $admin['username'] ?? '',
            'role'                  => $admin['role'],
            'role_label'            => $roleLabel,
            'is_super'              => $isSuperAdmin,
            'is_perusahaan'         => $isPerusahaan,
            'is_prodi'              => $isProdi,
            'entitas_id'            => !empty($admin['entitas_id']) ? (int)$admin['entitas_id'] : null,
            'entitas'               => $entitasData,
            'jurusan_id'            => !empty($admin['jurusan_id']) ? (int)$admin['jurusan_id'] : null,
            'jurusan'               => $jurusanData,
            'force_password_change' => (bool)($admin['force_password_change'] ?? false),
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil profil admin.')]);
}


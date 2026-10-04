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
    
    $roleLabel = 'Admin BLKA';
    if ($isSuperAdmin) {
        $roleLabel = 'Super Admin BLKA';
    } elseif ($isPerusahaan) {
        $roleLabel = 'Admin Mitra Perusahaan';
    }

    $entitasData = null;
    if (!empty($admin['entitas_id'])) {
        $pdo = Database::getInstance();
        $stmtE = $pdo->prepare("SELECT id, nama, singkatan, tipe, alamat FROM entitas_perusahaan WHERE id = ?");
        $stmtE->execute([(int)$admin['entitas_id']]);
        $entitasData = $stmtE->fetch(PDO::FETCH_ASSOC) ?: null;
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
            'entitas_id'            => !empty($admin['entitas_id']) ? (int)$admin['entitas_id'] : null,
            'entitas'               => $entitasData,
            'force_password_change' => (bool)($admin['force_password_change'] ?? false),
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil profil admin.')]);
}


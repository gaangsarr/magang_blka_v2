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
    $roleLabel = $isSuperAdmin ? 'Super Admin REMATE' : 'Admin REMATE';

    echo json_encode([
        'ok'             => true,
        'admin'          => [
            'id'         => (int)$admin['id'],
            'nama'       => $admin['nama'],
            'email'      => $admin['email'],
            'role'       => $admin['role'],
            'role_label' => $roleLabel,
            'is_super'   => $isSuperAdmin,
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil profil admin.')]);
}


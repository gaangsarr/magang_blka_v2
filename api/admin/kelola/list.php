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

try {
    $pdo = Database::getInstance();
    $currentAdmin = Auth::getAdmin();

    // Fetch all active admins
    $stmtAdmins = $pdo->query("
        SELECT 
            a.id AS admin_id,
            a.email AS admin_email,
            a.nama AS admin_nama,
            a.role,
            a.aktif,
            a.created_at
        FROM admin a
        ORDER BY (a.role = 'super_admin') DESC, a.id ASC
    ");
    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'            => true,
        'current_admin' => $currentAdmin,
        'admins'        => $admins,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data admin: ' . $e->getMessage()]);
}

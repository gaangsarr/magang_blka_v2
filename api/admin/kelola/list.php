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

    // Fetch all internal campus admins (exclude admin_perusahaan)
    $stmtAdmins = $pdo->query("
        SELECT 
            a.id AS admin_id,
            a.email AS admin_email,
            a.nama AS admin_nama,
            a.role,
            a.aktif,
            a.created_at
        FROM admin a
        WHERE a.role IN ('super_admin', 'superadmin', 'admin_blka', 'admin')
        ORDER BY (a.role = 'super_admin' OR a.role = 'superadmin') DESC, a.id ASC
    ");
    $admins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'            => true,
        'current_admin' => $currentAdmin,
        'admins'        => $admins,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data admin.')]);
}


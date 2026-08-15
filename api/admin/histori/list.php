<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $pdo = Database::getInstance();

    // Get all periods (draft, dibuka, ditutup, diarsipkan), with count of registrants
    $sql = "
        SELECT 
            pr.id,
            pr.nama,
            pr.status,
            pr.tanggal_mulai,
            pr.tanggal_selesai,
            pr.created_at,
            COUNT(p.id) AS total_pendaftar,
            SUM(CASE WHEN p.status IN ('diterima', 'diverifikasi', 'dipindahkan') THEN 1 ELSE 0 END) AS total_diterima,
            SUM(CASE WHEN p.status = 'ditolak' THEN 1 ELSE 0 END) AS total_ditolak
        FROM periode pr
        LEFT JOIN pendaftaran p ON p.periode_id = pr.id
        GROUP BY pr.id, pr.nama, pr.status, pr.tanggal_mulai, pr.tanggal_selesai, pr.created_at
        ORDER BY pr.id DESC
    ";

    $stmt = $pdo->query($sql);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'   => true,
        'data' => $list,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data histori.')]);
}


<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $pdo = Database::getInstance();
    
    // Ambil semua entitas tipe unit_pelaksana
    $query = "
        SELECT 
            e.id, e.nama, e.alamat, e.latitude, e.longitude, e.aktif,
            GROUP_CONCAT(up.peminatan_id) as peminatan_ids
        FROM entitas_perusahaan e
        LEFT JOIN unit_peminatan up ON e.id = up.entitas_id
        WHERE e.tipe = 'unit_pelaksana'
        GROUP BY e.id
        ORDER BY e.nama ASC
    ";
    
    $stmt = $pdo->query($query);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'data' => $data
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

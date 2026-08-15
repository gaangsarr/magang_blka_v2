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

    $stmt = $pdo->query("SELECT kunci, nilai, deskripsi, updated_at FROM pengaturan ORDER BY kunci ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert to key-value map for easier frontend consumption
    $map = [];
    foreach ($rows as $row) {
        $map[$row['kunci']] = [
            'nilai'      => $row['nilai'],
            'deskripsi'  => $row['deskripsi'],
            'updated_at' => $row['updated_at'],
        ];
    }

    echo json_encode([
        'ok'   => true,
        'data' => $map,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data pengaturan.')]);
}


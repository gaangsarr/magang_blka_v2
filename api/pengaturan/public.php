<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Requires mahasiswa login to read public settings
if (!Auth::isLoggedInMahasiswa()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Only expose settings relevant to mahasiswa
    $publicKeys = ['min_ipk_5bulan', 'min_sks_5bulan'];
    $placeholders = implode(',', array_fill(0, count($publicKeys), '?'));

    $stmt = $pdo->prepare("SELECT kunci, nilai FROM pengaturan WHERE kunci IN ($placeholders)");
    $stmt->execute($publicKeys);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $map[$row['kunci']] = $row['nilai'];
    }

    echo json_encode([
        'ok'   => true,
        'data' => $map,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil pengaturan.']);
}

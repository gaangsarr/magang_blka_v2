<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\Models\PicNarahubung;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $entitasId = isset($_GET['entitas_id']) ? (int)$_GET['entitas_id'] : 0;
    if ($entitasId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter entitas_id tidak valid.']);
        exit;
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id, nama, singkatan, tipe, alamat FROM entitas_perusahaan WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $entitasId]);
    $parentUnit = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$parentUnit) {
        http_response_code(404);
        echo json_encode(['error' => 'Unit entitas tidak ditemukan.']);
        exit;
    }

    $pic = PicNarahubung::getByEntitasId($entitasId);
    $connectedUnits = PicNarahubung::getConnectedUnits($entitasId);

    // Grouping by type for convenience
    $byType = [
        'unit_pelaksana' => [],
        'unit_layanan'   => [],
        'lainnya'        => []
    ];

    foreach ($connectedUnits as $u) {
        $t = $u['tipe'];
        if (isset($byType[$t])) {
            $byType[$t][] = $u;
        } else {
            $byType['lainnya'][] = $u;
        }
    }

    echo json_encode([
        'ok'               => true,
        'parent_unit'      => $parentUnit,
        'pic'              => $pic,
        'total'            => count($connectedUnits),
        'summary_by_type'  => [
            'total_up3'    => count($byType['unit_pelaksana']),
            'total_ulp'    => count($byType['unit_layanan']),
            'total_lainnya'=> count($byType['lainnya'])
        ],
        'data'             => $connectedUnits
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data unit terhubung.')]);
}

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
    $search = trim((string)($_GET['search'] ?? ''));
    $areaHcbp = trim((string)($_GET['area_hcbp'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));

    $filters = [];
    if ($search !== '') $filters['search'] = $search;
    if ($areaHcbp !== '') $filters['area_hcbp'] = $areaHcbp;
    if ($status !== '' && ($status === '1' || $status === '0')) $filters['aktif'] = $status;

    $list = PicNarahubung::getAll($filters);
    $areas = PicNarahubung::getDistinctAreas();

    // Stats
    $totalPic = count($list);
    $totalAktif = 0;
    $totalUnitTerhubung = 0;

    foreach ($list as $item) {
        if ($item['aktif']) $totalAktif++;
        $totalUnitTerhubung += (int)($item['total_unit_terhubung'] ?? 0);
    }

    echo json_encode([
        'ok'       => true,
        'total'    => $totalPic,
        'stats'    => [
            'total_pic'            => $totalPic,
            'total_aktif'          => $totalAktif,
            'total_unit_terhubung' => $totalUnitTerhubung,
            'total_area'           => count($areas)
        ],
        'areas'    => $areas,
        'data'     => $list
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data PIC Narahubung.')]);
}

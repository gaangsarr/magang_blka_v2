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

// Filter opsional via query string
// ?tipe=unit_pelaksana          → filter per tipe
// ?parent_id=5                  → filter per parent
// ?aktif=1                      → filter status aktif/nonaktif
// ?menerima_magang=1            → filter yang menerima magang
// ?with_peminatan=1             → sertakan array peminatan_ids

$filterTipe     = isset($_GET['tipe']) && in_array(
    $_GET['tipe'],
    ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana'],
    true
) ? $_GET['tipe'] : null;

$filterParentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
$filterAktif    = isset($_GET['aktif']) && $_GET['aktif'] !== '' ? (int)(bool)$_GET['aktif'] : null;
$filterMagang   = isset($_GET['menerima_magang']) && $_GET['menerima_magang'] !== '' ? (int)(bool)$_GET['menerima_magang'] : null;
$withPeminatan  = isset($_GET['with_peminatan']) && $_GET['with_peminatan'] === '1';

try {
    $pdo = Database::getInstance();

    $where  = ['1=1'];
    $params = [];

    if ($filterTipe !== null) {
        $where[]  = 'e.tipe = ?';
        $params[] = $filterTipe;
    }
    if ($filterParentId !== null) {
        $where[]  = 'e.parent_id = ?';
        $params[] = $filterParentId;
    }
    if ($filterAktif !== null) {
        $where[]  = 'e.aktif = ?';
        $params[] = $filterAktif;
    }
    if ($filterMagang !== null) {
        $where[]  = 'e.menerima_magang = ?';
        $params[] = $filterMagang;
    }

    $whereSQL = implode(' AND ', $where);

    $sql = "
        SELECT
            e.id,
            e.tipe,
            e.parent_id,
            p.nama  AS nama_parent,
            p.tipe  AS tipe_parent,
            e.nama,
            e.singkatan,
            e.alamat,
            e.latitude,
            e.longitude,
            e.aktif,
            e.menerima_magang
        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan p ON e.parent_id = p.id
        WHERE $whereSQL
        ORDER BY e.tipe ASC, e.nama ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Lampirkan peminatan_ids jika diminta
    if ($withPeminatan && !empty($data)) {
        $ids = array_column($data, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtPem = $pdo->prepare("
            SELECT up.entitas_id, up.peminatan_id, p.nama AS nama_peminatan
            FROM unit_peminatan up
            JOIN peminatan p ON up.peminatan_id = p.id
            WHERE up.entitas_id IN ($placeholders)
        ");
        $stmtPem->execute($ids);
        $pemRows = $stmtPem->fetchAll(PDO::FETCH_ASSOC);

        $pemMap = [];
        $pemNamesMap = [];
        foreach ($pemRows as $row) {
            $pemMap[$row['entitas_id']][] = (int)$row['peminatan_id'];
            $pemNamesMap[$row['entitas_id']][] = $row['nama_peminatan'];
        }
        foreach ($data as &$item) {
            $item['peminatan_ids']   = $pemMap[$item['id']] ?? [];
            $item['peminatan_names'] = $pemNamesMap[$item['id']] ?? [];
        }
        unset($item);
    }

    // Cast tipe data
    foreach ($data as &$item) {
        $item['id']              = (int)$item['id'];
        $item['parent_id']       = $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
        $item['aktif']           = (bool)$item['aktif'];
        $item['menerima_magang'] = (bool)$item['menerima_magang'];
    }
    unset($item);

    echo json_encode([
        'ok'   => true,
        'data' => $data,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

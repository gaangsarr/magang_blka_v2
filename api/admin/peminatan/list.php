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

    // Ambil master prodi aktif untuk selector
    $stmtJur = $pdo->query("SELECT id, kode, jenjang, nama_jurusan FROM jurusan WHERE aktif = 1 ORDER BY jenjang ASC, nama_jurusan ASC");
    $allJurusan = $stmtJur->fetchAll(PDO::FETCH_ASSOC);

    // Return all peminatan including inactive and its linked jurusan
    $stmt = $pdo->query("
        SELECT 
            p.id, 
            p.nama, 
            p.deskripsi, 
            p.aktif, 
            p.created_at,
            GROUP_CONCAT(pj.jurusan_id) AS jurusan_ids_str,
            GROUP_CONCAT(CONCAT(IFNULL(j.jenjang, 'S1'), ' ', j.nama_jurusan) ORDER BY j.jenjang ASC, j.nama_jurusan ASC SEPARATOR '||') AS jurusan_names_str
        FROM peminatan p
        LEFT JOIN peminatan_jurusan pj ON p.id = pj.peminatan_id
        LEFT JOIN jurusan j ON pj.jurusan_id = j.id
        GROUP BY p.id, p.nama, p.deskripsi, p.aktif, p.created_at
        ORDER BY p.id ASC
    ");
    $rawList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $list = [];
    foreach ($rawList as $row) {
        $jIds = !empty($row['jurusan_ids_str']) 
            ? array_map('intval', explode(',', $row['jurusan_ids_str'])) 
            : [];
        $jNames = !empty($row['jurusan_names_str']) 
            ? explode('||', $row['jurusan_names_str']) 
            : [];
        
        $list[] = [
            'id'            => (int)$row['id'],
            'nama'          => $row['nama'],
            'deskripsi'     => $row['deskripsi'],
            'aktif'         => (int)$row['aktif'],
            'created_at'    => $row['created_at'],
            'jurusan_ids'   => $jIds,
            'jurusan_names' => $jNames,
        ];
    }

    echo json_encode([
        'ok'          => true,
        'data'        => $list,
        'all_jurusan' => $allJurusan,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data peminatan.')]);
}


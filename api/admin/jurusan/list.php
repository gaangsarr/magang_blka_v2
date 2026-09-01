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

    $search = trim((string)($_GET['search'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $jenjang = strtoupper(trim((string)($_GET['jenjang'] ?? '')));

    $whereClauses = [];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(j.kode LIKE :s_kode OR j.nama_jurusan LIKE :s_nama)';
        $params[':s_kode'] = '%' . $search . '%';
        $params[':s_nama'] = '%' . $search . '%';
    }

    if ($status !== '' && ($status === '1' || $status === '0')) {
        $whereClauses[] = 'j.aktif = :aktif';
        $params[':aktif'] = (int)$status;
    }

    if ($jenjang !== '') {
        $whereClauses[] = 'j.jenjang = :jenjang';
        $params[':jenjang'] = $jenjang;
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $stmt = $pdo->prepare("
        SELECT 
            j.id, 
            j.kode, 
            j.jenjang,
            j.nama_jurusan, 
            j.aktif,
            (SELECT COUNT(*) FROM mahasiswa m WHERE m.jurusan_id = j.id) AS total_mahasiswa,
            (SELECT COUNT(DISTINCT upj.unit_pelaksana_periode_id) FROM unit_periode_jurusan upj WHERE upj.jurusan_id = j.id) AS total_unit_terhubung
        FROM jurusan j
        {$whereSql}
        ORDER BY j.jenjang ASC, j.kode ASC, j.nama_jurusan ASC
    ");
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format boolean / int
    $formattedList = array_map(function ($row) {
        return [
            'id'                     => (int)$row['id'],
            'kode'                   => (string)$row['kode'],
            'jenjang'                => (string)($row['jenjang'] ?: 'S1'),
            'nama_jurusan'           => (string)$row['nama_jurusan'],
            'aktif'                  => (bool)$row['aktif'],
            'total_mahasiswa'        => (int)$row['total_mahasiswa'],
            'total_unit_terhubung'   => (int)$row['total_unit_terhubung'],
        ];
    }, $list);

    echo json_encode([
        'ok'   => true,
        'total'=> count($formattedList),
        'data' => $formattedList,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data program studi.')]);
}

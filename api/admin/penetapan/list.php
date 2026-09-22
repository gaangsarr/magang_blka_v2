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

    // 1. Fetch all periods for dropdown selection
    $stmtAll = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtPeriode = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    // 2. Fetch master list jurusan untuk filter
    $stmtJ = $pdo->query("SELECT id, nama_jurusan AS nama, kode FROM jurusan ORDER BY nama_jurusan ASC");
    $jurusanList = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

    if (!$periode) {
        echo json_encode([
            'ok' => true,
            'periode_terpilih' => null,
            'all_periode' => [],
            'jurusan_list' => $jurusanList,
            'data' => [],
            'message' => 'Belum ada periode magang.'
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // Pagination parameters
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    // Filter parameters
    $search = trim((string)($_GET['search'] ?? ''));
    $filterJurusan = isset($_GET['filter_jurusan_id']) && $_GET['filter_jurusan_id'] !== '' ? (int)$_GET['filter_jurusan_id'] : null;
    $filterUnit = isset($_GET['filter_unit_id']) && $_GET['filter_unit_id'] !== '' ? (int)$_GET['filter_unit_id'] : null;
    $filterProgram = trim((string)($_GET['filter_program'] ?? ''));
    $filterStatus = trim((string)($_GET['filter_status'] ?? ''));

    $whereClauses = ['p.periode_id = :pid'];
    $params = [':pid' => $periodeId];

    if ($search !== '') {
        $whereClauses[] = '(m.nim LIKE :s_nim OR p.nama_snapshot LIKE :s_nama OR m.email LIKE :s_email OR e.nama LIKE :s_unit)';
        $searchWildcard = '%' . $search . '%';
        $params[':s_nim'] = $searchWildcard;
        $params[':s_nama'] = $searchWildcard;
        $params[':s_email'] = $searchWildcard;
        $params[':s_unit'] = $searchWildcard;
    }


    if ($filterJurusan !== null && $filterJurusan > 0) {
        $whereClauses[] = 'm.jurusan_id = :jurusan_id';
        $params[':jurusan_id'] = $filterJurusan;
    }

    if ($filterUnit !== null && $filterUnit > 0) {
        $whereClauses[] = 'p.unit_pelaksana_periode_id = :unit_id';
        $params[':unit_id'] = $filterUnit;
    }

    if ($filterProgram !== '') {
        $whereClauses[] = 'p.program = :program';
        $params[':program'] = $filterProgram;
    }

    if ($filterStatus !== '') {
        if ($filterStatus === 'dipindahkan') {
            $whereClauses[] = '(p.status = "dipindahkan" OR p.is_dipindahkan = 1)';
        } else {
            $whereClauses[] = 'p.status = :status';
            $params[':status'] = $filterStatus;
        }
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // 1. Total Count
    $countSql = "
        SELECT COUNT(*)
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE $whereSQL
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalRecords = (int)$stmtCount->fetchColumn();

    // 2. Fetch data pendaftaran beserta jurusan, unit asal & unit baru
    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            p.mahasiswa_id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            m.jurusan_id,
            j.nama_jurusan AS jurusan_nama,
            p.program,
            p.status,
            p.is_dipindahkan,
            p.catatan_admin,
            p.submitted_at,
            p.transkrip_path,
            p.cv_path,
            p.porto_path,
            p.unit_pelaksana_periode_id,
            e.nama AS unit_nama,
            p.unit_pelaksana_periode_asal_id,
            e_asal.nama AS unit_asal_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        WHERE $whereSQL
        ORDER BY p.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Fetch list unit untuk periode terpilih (untuk modal relocate & filter unit)
    $stmtUnits = $pdo->prepare("
        SELECT 
            upp.id AS upp_id,
            e.nama,
            upp.kuota_total,
            upp.kuota_tersisa,
            GROUP_CONCAT(upj.jurusan_id) AS prodi_ids_str
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_periode_jurusan upj ON upp.id = upj.unit_pelaksana_periode_id
        WHERE upp.periode_id = :pid AND upp.aktif = 1
        GROUP BY upp.id, e.nama, upp.kuota_total, upp.kuota_tersisa
        ORDER BY e.nama ASC
    ");
    $stmtUnits->execute([':pid' => $periodeId]);
    $unitList = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);
    foreach ($unitList as &$u) {
        $u['prodi_ids'] = !empty($u['prodi_ids_str']) ? array_map('intval', explode(',', $u['prodi_ids_str'])) : [];
        unset($u['prodi_ids_str']);
    }
    unset($u);

    echo json_encode([
        'ok' => true,
        'periode_id' => $periodeId,
        'periode_terpilih' => $periode,
        'periode_nama' => $periode['nama'],
        'all_periode' => $allPeriode,
        'jurusan_list' => $jurusanList,
        'unit_list' => $unitList,
        'data' => $list,
        'pagination' => [
            'total' => $totalRecords,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $perPage > 0 ? (int)ceil($totalRecords / $perPage) : 1
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data penetapan.')]);
}


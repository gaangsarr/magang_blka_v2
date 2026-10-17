<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireProdiApi();

try {
    $pdo = Database::getInstance();
    $jurusanId = Auth::getProdiJurusanId();

    if (!$jurusanId) {
        http_response_code(403);
        echo json_encode(['error' => 'Akun admin belum terikat dengan Program Studi manapun.']);
        exit;
    }

    // Ambil daftar semua periode untuk selector
    $stmtAll = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    if (!$periode) {
        echo json_encode([
            'ok'               => true,
            'periode_terpilih' => null,
            'all_periode'      => [],
            'data'             => [],
            'pagination'       => ['total' => 0, 'page' => 1, 'per_page' => 25, 'total_pages' => 1]
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // Pagination
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    // Filters
    $search        = trim((string)($_GET['search'] ?? ''));
    $filterStatus  = trim((string)($_GET['status'] ?? ''));
    $filterProgram = trim((string)($_GET['program'] ?? ''));

    $whereClauses = ['p.periode_id = :periode_id', 'm.jurusan_id = :jurusan_id'];
    $params = [
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ];

    if ($search !== '') {
        $whereClauses[] = '(m.nim LIKE :s_nim OR p.nama_snapshot LIKE :s_nama OR m.email LIKE :s_email OR ep.nama LIKE :s_unit)';
        $wildcard = '%' . $search . '%';
        $params[':s_nim']  = $wildcard;
        $params[':s_nama'] = $wildcard;
        $params[':s_email'] = $wildcard;
        $params[':s_unit'] = $wildcard;
    }

    if ($filterStatus !== '') {
        if ($filterStatus === 'diproses') {
            $whereClauses[] = "p.status IN ('menunggu', 'diproses')";
        } else {
            $whereClauses[] = 'p.status = :status';
            $params[':status'] = $filterStatus;
        }
    }

    if ($filterProgram !== '') {
        $rawProg = str_replace(' ', '_', strtolower($filterProgram));
        $whereClauses[] = '(p.program = :program OR p.program = :program_raw)';
        $params[':program'] = $filterProgram;
        $params[':program_raw'] = $rawProg;
    }

    $whereSQL = implode(' AND ', $whereClauses);

    // Count Total
    $countSql = "
        SELECT COUNT(*)
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        WHERE $whereSQL
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalRecords = (int)$stmtCount->fetchColumn();

    // Query Data
    $query = "
        SELECT 
            p.id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            p.jenis_kelamin,
            m.angkatan,
            CASE p.program
                WHEN '1_bulan' THEN '1 Bulan'
                WHEN '3_bulan' THEN '3 Bulan'
                WHEN '4_bulan' THEN '4 Bulan'
                WHEN '5_bulan' THEN '5 Bulan'
                WHEN '6_bulan' THEN '6 Bulan'
                ELSE IFNULL(NULLIF(p.program, ''), '-')
            END AS program,
            p.ipk,
            p.jumlah_sks,
            p.transkrip_path,
            p.cv_path,
            p.porto_path,
            p.linkedin_link,
            p.alamat,
            p.kota_kabupaten AS kota,
            p.provinsi,
            p.status,
            p.catatan_admin,
            p.submitted_at,
            ep.id AS unit_id,
            ep.nama AS unit_nama,
            ep.singkatan AS unit_singkatan,
            pem_agg.peminatan_list
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        LEFT JOIN (
            SELECT pp.pendaftaran_id, GROUP_CONCAT(mp.nama ORDER BY mp.nama SEPARATOR ', ') AS peminatan_list
            FROM pendaftaran_peminatan pp
            JOIN peminatan mp ON pp.peminatan_id = mp.id
            GROUP BY pp.pendaftaran_id
        ) pem_agg ON pem_agg.pendaftaran_id = p.id
        WHERE $whereSQL
        ORDER BY p.submitted_at DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($query);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'               => true,
        'periode_terpilih' => $periode,
        'all_periode'      => $allPeriode,
        'data'             => $rows,
        'pagination'       => [
            'total'       => $totalRecords,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $perPage > 0 ? (int)ceil($totalRecords / $perPage) : 1
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat daftar pendaftar prodi.')]);
}

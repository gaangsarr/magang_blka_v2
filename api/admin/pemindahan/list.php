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

    $periodeId = $periode ? (int)$periode['id'] : 0;

    // Filter parameters
    $statusApproval = trim((string)($_GET['status_approval'] ?? ''));
    $filterUnitAsal = isset($_GET['filter_unit_asal']) && $_GET['filter_unit_asal'] !== '' ? (int)$_GET['filter_unit_asal'] : null;
    $filterUnitTujuan = isset($_GET['filter_unit_tujuan']) && $_GET['filter_unit_tujuan'] !== '' ? (int)$_GET['filter_unit_tujuan'] : null;
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $whereClauses = [];
    $params = [];

    if ($periodeId > 0) {
        $whereClauses[] = 'pem.periode_id = :pid';
        $params[':pid'] = $periodeId;
    }

    if ($statusApproval !== '') {
        $whereClauses[] = 'pem.status_approval = :status_app';
        $params[':status_app'] = $statusApproval;
    }

    if ($filterUnitAsal !== null && $filterUnitAsal > 0) {
        $whereClauses[] = 'pem.unit_asal_id = :asal_id';
        $params[':asal_id'] = $filterUnitAsal;
    }

    if ($filterUnitTujuan !== null && $filterUnitTujuan > 0) {
        $whereClauses[] = 'pem.unit_tujuan_id = :tujuan_id';
        $params[':tujuan_id'] = $filterUnitTujuan;
    }

    if ($search !== '') {
        $whereClauses[] = '(m.nim LIKE :s_nim OR p.nama_snapshot LIKE :s_nama OR m.email LIKE :s_email OR e_asal.nama LIKE :s_asal OR e_tujuan.nama LIKE :s_tujuan)';
        $wildcard = '%' . $search . '%';
        $params[':s_nim'] = $wildcard;
        $params[':s_nama'] = $wildcard;
        $params[':s_email'] = $wildcard;
        $params[':s_asal'] = $wildcard;
        $params[':s_tujuan'] = $wildcard;
    }

    $whereSql = !empty($whereClauses) ? implode(' AND ', $whereClauses) : '1=1';

    // Summary Counts for Badges
    $stmtStats = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN pem.status_approval = 'menunggu_approval' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN pem.status_approval = 'disetujui' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN pem.status_approval = 'ditolak' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN pem.status_approval = 'force_blka' THEN 1 ELSE 0 END) AS force_blka
        FROM pemindahan_peserta pem
        WHERE pem.periode_id = :pid
    ");
    $stmtStats->execute([':pid' => $periodeId]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [
        'total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'force_blka' => 0
    ];
    $stats['disetujui'] = $stats['approved'] ?? 0;
    $stats['ditolak'] = $stats['rejected'] ?? 0;

    // Count records matching filter
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM pemindahan_peserta pem
        JOIN pendaftaran p ON pem.pendaftaran_id = p.id
        JOIN mahasiswa m ON pem.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp_asal ON pem.unit_asal_id = upp_asal.id
        JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        JOIN unit_pelaksana_periode upp_tujuan ON pem.unit_tujuan_id = upp_tujuan.id
        JOIN entitas_perusahaan e_tujuan ON upp_tujuan.entitas_id = e_tujuan.id
        WHERE {$whereSql}
    ");
    $stmtCount->execute($params);
    $totalRecords = (int)$stmtCount->fetchColumn();

    // Fetch Data
    $stmtData = $pdo->prepare("
        SELECT 
            pem.id,
            pem.id AS pemindahan_id,
            pem.pendaftaran_id,
            pem.periode_id,
            pem.mahasiswa_id,
            pem.unit_asal_id,
            pem.unit_tujuan_id,
            pem.diajukan_oleh_role,
            pem.alasan_pemindahan,
            pem.status_approval,
            pem.approval_catatan,
            pem.approved_at,
            pem.created_at,
            pem.created_at AS tanggal_diajukan,
            pem.created_at AS waktu_pengajuan,
            m.nim,
            p.nama_snapshot AS nama,
            p.nama_snapshot AS mahasiswa_nama,
            m.email,
            p.no_hp,
            p.program,
            p.ipk,
            p.transkrip_path,
            p.cv_path,
            p.porto_path,
            j.nama_jurusan,
            j.nama_jurusan AS jurusan_nama,
            j.jenjang,
            e_asal.nama AS unit_asal_nama,
            e_tujuan.nama AS unit_tujuan_nama,
            adm_aju.nama AS diajukan_oleh_nama,
            adm_app.nama AS disetujui_oleh_nama
        FROM pemindahan_peserta pem
        JOIN pendaftaran p ON pem.pendaftaran_id = p.id
        JOIN mahasiswa m ON pem.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp_asal ON pem.unit_asal_id = upp_asal.id
        JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        JOIN unit_pelaksana_periode upp_tujuan ON pem.unit_tujuan_id = upp_tujuan.id
        JOIN entitas_perusahaan e_tujuan ON upp_tujuan.entitas_id = e_tujuan.id
        LEFT JOIN admin adm_aju ON pem.diajukan_oleh_admin_id = adm_aju.id
        LEFT JOIN admin adm_app ON pem.approval_oleh_admin_id = adm_app.id
        WHERE {$whereSql}
        ORDER BY pem.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'               => true,
        'periode'          => $periode,
        'periode_terpilih' => $periode,
        'all_periode'      => $allPeriode,
        'stats'            => $stats,
        'total'            => $totalRecords,
        'data'             => $rows,
        'pagination'       => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $totalRecords,
            'total_pages' => $perPage > 0 ? (int)ceil($totalRecords / $perPage) : 1
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil monitoring pemindahan peserta.')]);
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PenetapanHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requirePerusahaanApi();

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $admin = Auth::getAdmin();

    if (!$entitasId) {
        if ($admin && ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin')) {
            $requestedEid = isset($_GET['entitas_id']) ? (int)$_GET['entitas_id'] : 0;
            if ($requestedEid > 0) {
                $entitasId = $requestedEid;
            } else {
                $stmtFirst = $pdo->query("SELECT id FROM entitas_perusahaan WHERE tipe IN ('unit_pelaksana', 'unit_layanan') ORDER BY id ASC LIMIT 1");
                $entitasId = (int)$stmtFirst->fetchColumn();
            }
        }
    }

    if (!$entitasId) {
        http_response_code(400);
        echo json_encode(['error' => 'Entitas unit tidak terhubung ke akun ini.']);
        exit;
    }

    // 1. Ambil Periode
    $requestedPid = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedPid > 0) {
        $stmtP = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ?");
        $stmtP->execute([$requestedPid]);
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtP = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtFall = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC LIMIT 1");
        $periode = $stmtFall->fetch(PDO::FETCH_ASSOC);
    }

    $periodeId = $periode ? (int)$periode['id'] : 0;

    // 2. Filter Parameters
    $type = trim((string)($_GET['type'] ?? 'masuk')); // 'masuk' atau 'keluar'
    $statusApproval = trim((string)($_GET['status_approval'] ?? ''));
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $whereClauses = [];
    $params = [];

    if ($periodeId > 0) {
        $whereClauses[] = 'pem.periode_id = :pid';
        $params[':pid'] = $periodeId;
    }

    if ($type === 'masuk') {
        $whereClauses[] = 'upp_tujuan.entitas_id = :eid';
        $params[':eid'] = $entitasId;
    } else {
        $whereClauses[] = 'upp_asal.entitas_id = :eid';
        $params[':eid'] = $entitasId;
    }

    if ($statusApproval !== '') {
        $whereClauses[] = 'pem.status_approval = :status_app';
        $params[':status_app'] = $statusApproval;
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

    $whereSql = implode(' AND ', $whereClauses);

    // Count
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

    // Data Query
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
            p.jumlah_sks,
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

    // Ambil pending transfer count untuk badge notifikasi merah
    $pendingCount = PenetapanHelper::getPendingTransferCount($pdo, $entitasId, $periodeId);

    echo json_encode([
        'ok'            => true,
        'type'          => $type,
        'periode'       => $periode,
        'pending_count' => $pendingCount,
        'total'         => $totalRecords,
        'data'          => $rows,
        'pagination'    => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $perPage > 0 ? (int)ceil($totalRecords / $perPage) : 1
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data pemindahan peserta.')]);
}

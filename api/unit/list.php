<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$lat = isset($body['lat']) ? (float)$body['lat'] : null;
$lng = isset($body['lng']) ? (float)$body['lng'] : null;
$periodeId = isset($body['periode_id']) ? (int)$body['periode_id'] : null;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids']) ? array_map('intval', $body['peminatan_ids']) : [];
$page = isset($body['page']) ? max(1, (int)$body['page']) : 1;
$search = isset($body['search']) ? trim((string)$body['search']) : '';
$limit = 10;
$offset = ($page - 1) * $limit;

if ($lat === null || $lng === null || !$periodeId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter lat, lng, dan periode_id wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Auto-cleanup reservasi kadaluarsa agar kuota yang ditampilkan selalu fresh & akurat
    \App\ReservasiHelper::cleanupExpired($pdo);
    
    // Validasi periode aktif & angkatan eligible
    $stmtP = $pdo->prepare("SELECT angkatan_eligible, nama, status FROM periode WHERE id = :pid");
    $stmtP->execute([':pid' => $periodeId]);
    $periodeData = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$periodeData || $periodeData['status'] !== 'dibuka') {
        http_response_code(400);
        echo json_encode(['error' => 'Periode magang ini tidak sedang dibuka.']);
        exit;
    }
    
    $mhsData = Auth::getMahasiswa();
    $mhsId = Auth::getMahasiswaId();
    $mhsJurusanId = (int)($mhsData['jurusan_id'] ?? 0);
    if (!$mhsJurusanId && $mhsId) {
        $stmtM = $pdo->prepare("SELECT jurusan_id FROM mahasiswa WHERE id = ?");
        $stmtM->execute([$mhsId]);
        $mhsJurusanId = (int)$stmtM->fetchColumn();
    }

    if (!empty($periodeData['angkatan_eligible'])) {
        $mhsAngkatan = (int)($mhsData['angkatan'] ?? 0);
        $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;
        $eligibleList = array_map('trim', explode(',', $periodeData['angkatan_eligible']));
        if (!in_array((string)$fullAngkatan, $eligibleList, true) && !in_array((string)$mhsAngkatan, $eligibleList, true)) {
            http_response_code(403);
            echo json_encode(['error' => "Pendaftaran periode {$periodeData['nama']} dikhususkan untuk Angkatan " . implode(', ', $eligibleList) . "."]);
            exit;
        }
    }

    // Siapkan klausa IN untuk peminatan
    $inClause = '0';
    if (!empty($peminatanIds)) {
        $inClause = implode(',', $peminatanIds);
    }

    // Search filter
    $searchWhere = '';
    $queryParams = [
        ':lat1' => $lat,
        ':lat2' => $lat,
        ':lng' => $lng,
        ':periode_id' => $periodeId,
        ':mhs_jurusan_id' => $mhsJurusanId
    ];
    $countParams = [];

    if ($search !== '') {
        $searchWhere = 'AND (e.nama LIKE :s1 OR e.singkatan LIKE :s2 OR e.alamat LIKE :s3 OR parent.nama LIKE :s4)';
        $searchVal = "%{$search}%";
        $queryParams[':s1'] = $searchVal;
        $queryParams[':s2'] = $searchVal;
        $queryParams[':s3'] = $searchVal;
        $queryParams[':s4'] = $searchVal;
        $countParams[':s1'] = $searchVal;
        $countParams[':s2'] = $searchVal;
        $countParams[':s3'] = $searchVal;
        $countParams[':s4'] = $searchVal;
    }

    // 1. Hitung total item untuk pagination
    $countSql = "
        SELECT COUNT(*) AS total
        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
        WHERE 1=1 {$searchWhere}
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($countParams);
    $totalItems = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalItems / $limit));

    // 2. Query data halaman saat ini
    $sql = "
        SELECT 
            e.id AS entitas_id,
            e.tipe,
            e.nama AS nama_unit,
            e.singkatan,
            e.alamat,
            e.latitude,
            e.longitude,
            parent.nama AS nama_parent,
            
            upp.id AS upp_id,
            upp.tipe_kuota,
            upp.kuota_tersisa,
            upp.kuota_total,
            
            upj.jurusan_id    AS upj_jurusan_id,
            upj.kuota_tersisa AS kuota_prodi_tersisa,
            upj.kuota_total   AS kuota_prodi_total,
            
            CASE WHEN e.latitude IS NOT NULL AND e.longitude IS NOT NULL THEN
                6371 * ACOS(LEAST(1.0, GREATEST(-1.0,
                    COS(RADIANS(:lat1)) * COS(RADIANS(e.latitude)) *
                    COS(RADIANS(e.longitude) - RADIANS(:lng)) +
                    SIN(RADIANS(:lat2)) * SIN(RADIANS(e.latitude))
                )))
            ELSE NULL END AS jarak,
            
            CASE WHEN upp.id IS NOT NULL THEN
                CASE 
                    WHEN EXISTS (SELECT 1 FROM unit_periode_peminatan uppem 
                                 WHERE uppem.unit_pelaksana_periode_id = upp.id) 
                    THEN (SELECT COUNT(*) FROM unit_periode_peminatan uppem2 
                          WHERE uppem2.unit_pelaksana_periode_id = upp.id 
                          AND uppem2.peminatan_id IN ({$inClause}))
                    ELSE (SELECT COUNT(*) FROM unit_peminatan up 
                          WHERE up.entitas_id = e.id 
                          AND up.peminatan_id IN ({$inClause}))
                END
            ELSE 0 END AS kecocokan,
            
            CASE 
                WHEN upp.id IS NOT NULL 
                     AND upp.aktif = 1 
                     AND e.aktif = 1
                     AND upj.jurusan_id IS NOT NULL
                     AND (
                         CASE WHEN upp.tipe_kuota = 'breakdown'
                              THEN LEAST(IFNULL(upj.kuota_tersisa, 0), upp.kuota_tersisa)
                              ELSE upp.kuota_tersisa
                         END
                     ) > 0
                THEN 1
                ELSE 0
            END AS can_select

        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
        LEFT JOIN unit_pelaksana_periode upp 
            ON upp.entitas_id = e.id AND upp.periode_id = :periode_id
        LEFT JOIN unit_periode_jurusan upj 
            ON upj.unit_pelaksana_periode_id = upp.id 
            AND upj.jurusan_id = :mhs_jurusan_id

        WHERE 1=1 {$searchWhere}

        ORDER BY 
            can_select DESC,
            (CASE WHEN e.latitude IS NOT NULL AND e.longitude IS NOT NULL THEN 0 ELSE 1 END) ASC,
            jarak ASC,
            kecocokan DESC,
            (CASE WHEN upp.tipe_kuota = 'breakdown' 
                  THEN LEAST(IFNULL(upj.kuota_tersisa,0), IFNULL(upp.kuota_tersisa,0))
                  ELSE IFNULL(upp.kuota_tersisa,0) END) DESC,
            e.nama ASC

        LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($units as &$u) {
        $canSelect = ((int)($u['can_select'] ?? 0)) === 1;
        $u['can_select'] = $canSelect ? 1 : 0;
        
        $u['jarak'] = ($canSelect && $u['jarak'] !== null) ? round((float)$u['jarak'], 2) : null;
        $u['kecocokan'] = $canSelect ? (int)($u['kecocokan'] ?? 0) : 0;
        
        $u['kuota_tersisa'] = (int)($u['kuota_tersisa'] ?? 0);
        $u['kuota_total']   = (int)($u['kuota_total'] ?? 0);
        $u['tipe_kuota']    = $u['tipe_kuota'] ?? 'keseluruhan';

        $prodiSisa  = $u['kuota_prodi_tersisa'] !== null ? (int)$u['kuota_prodi_tersisa'] : null;
        $prodiTotal = $u['kuota_prodi_total'] !== null ? (int)$u['kuota_prodi_total'] : null;
        $hasProdi   = !empty($u['upj_jurusan_id']);

        if ($hasProdi) {
            if ($u['tipe_kuota'] === 'breakdown') {
                $totalEfektif = $prodiTotal !== null ? $prodiTotal : $u['kuota_total'];
                $sisaEfektif  = max(0, $prodiSisa !== null ? min($prodiSisa, $u['kuota_tersisa']) : $u['kuota_tersisa']);
            } else {
                $totalEfektif = $u['kuota_total'];
                $sisaEfektif  = max(0, $u['kuota_tersisa']);
            }
            $u['sisa_kuota_efektif']  = $sisaEfektif;
            $u['total_kuota_efektif'] = $totalEfektif;
            $u['status_kuota']        = ($sisaEfektif > 0) ? 'tersedia' : 'penuh';
        } else {
            $u['sisa_kuota_efektif']  = 0;
            $u['total_kuota_efektif'] = $u['kuota_total'];
            $u['status_kuota']        = 'prodi_tidak_sesuai';
        }
    }
    unset($u);

    echo json_encode([
        'ok' => true,
        'data' => $units,
        'pagination' => [
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'total_items'  => $totalItems,
            'per_page'     => $limit
        ]
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat rekomendasi unit.')]);
}

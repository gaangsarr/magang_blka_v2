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

// Filter opsional via query string:
// ?tipe=unit_pelaksana          → filter per tipe
// ?parent_id=5                  → filter per parent
// ?aktif=1                      → filter status aktif/nonaktif
// ?menerima_magang=1            → filter yang menerima magang (1=ya, 0=tidak)
// ?search=bandung               → pencarian nama/singkatan/alamat/parent
// ?page=1                       → nomor halaman (default: 1)
// ?limit=25                     → limit data per halaman (default: 25)
// ?all=1                        → tanpa pagination (untuk select parent)
// ?with_peminatan=1             → sertakan array peminatan_ids & peminatan_names

$filterTipe     = isset($_GET['tipe']) && in_array(
    $_GET['tipe'],
    ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana', 'unit_layanan'],
    true
) ? $_GET['tipe'] : null;

$filterParentId = isset($_GET['parent_id']) && $_GET['parent_id'] !== '' ? (int)$_GET['parent_id'] : null;
$filterAktif    = isset($_GET['aktif']) && $_GET['aktif'] !== '' ? (int)(bool)$_GET['aktif'] : null;
$filterMagang   = isset($_GET['menerima_magang']) && $_GET['menerima_magang'] !== '' ? (int)$_GET['menerima_magang'] : null;
$filterKuota    = isset($_GET['filter_kuota']) && $_GET['filter_kuota'] !== '' ? trim((string)$_GET['filter_kuota']) : null;
$search         = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$noPaginate     = (isset($_GET['all']) && $_GET['all'] === '1') || (isset($_GET['no_paginate']) && $_GET['no_paginate'] === '1');
$withPeminatan  = isset($_GET['with_peminatan']) && $_GET['with_peminatan'] === '1';

$page  = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$limit = max(1, min(100, isset($_GET['limit']) ? (int)$_GET['limit'] : 25));
$offset = ($page - 1) * $limit;

try {
    $pdo = Database::getInstance();

    // Ambil Periode Aktif (dibuka/persiapan) atau periode terakhir
    $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
    $activePeriode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    if (!$activePeriode) {
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC LIMIT 1");
        $activePeriode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }
    $activePeriodeId = $activePeriode ? (int)$activePeriode['id'] : 0;

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
    if ($filterKuota !== null) {
        if ($filterKuota === 'dibuka') {
            $where[] = '(upp.id IS NOT NULL AND upp.aktif = 1 AND upp.kuota_total > 0)';
        } elseif ($filterKuota === 'belum_diset') {
            $where[] = '(upp.id IS NULL OR upp.kuota_total = 0 OR upp.kuota_total IS NULL)';
        } elseif ($filterKuota === 'disembunyikan') {
            $where[] = '(upp.id IS NOT NULL AND upp.aktif = 0)';
        }
    }
    if ($search !== '') {
        $searchParam = '%' . $search . '%';
        $where[] = '(e.nama LIKE ? OR e.singkatan LIKE ? OR e.alamat LIKE ? OR p.nama LIKE ?)';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    $whereSQL = implode(' AND ', $where);

    // 1. Hitung total baris yang cocok
    $countSql = "
        SELECT COUNT(*) 
        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan p ON e.parent_id = p.id
        LEFT JOIN unit_pelaksana_periode upp ON e.id = upp.entitas_id AND upp.periode_id = ?
        WHERE $whereSQL
    ";
    $countParams = array_merge([$activePeriodeId], $params);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalRows = (int)$countStmt->fetchColumn();

    // 2. Ambil data dengan Pagination
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
            e.menerima_magang,
            upp.id AS upp_id,
            upp.aktif AS upp_aktif,
            upp.tipe_kuota,
            upp.kuota_total,
            upp.kuota_tersisa
        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan p ON e.parent_id = p.id
        LEFT JOIN unit_pelaksana_periode upp ON e.id = upp.entitas_id AND upp.periode_id = ?
        WHERE $whereSQL
        ORDER BY p.nama ASC, e.tipe ASC, e.nama ASC
    ";

    if (!$noPaginate) {
        $sql .= " LIMIT $limit OFFSET $offset";
    }

    $queryParams = array_merge([$activePeriodeId], $params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($queryParams);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Lampirkan peminatan_ids & prodi_ids jika diminta
    if ($withPeminatan && !empty($data)) {
        $ids = array_column($data, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        // Peminatan
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

        // Prodi / Jurusan
        $stmtJur = $pdo->prepare("
            SELECT uj.entitas_id, uj.jurusan_id, j.nama_jurusan, j.kode
            FROM unit_jurusan uj
            JOIN jurusan j ON uj.jurusan_id = j.id
            WHERE uj.entitas_id IN ($placeholders)
        ");
        $stmtJur->execute($ids);
        $jurRows = $stmtJur->fetchAll(PDO::FETCH_ASSOC);

        $jurMap = [];
        $jurNamesMap = [];
        foreach ($jurRows as $row) {
            $jurMap[$row['entitas_id']][] = (int)$row['jurusan_id'];
            $jurNamesMap[$row['entitas_id']][] = $row['nama_jurusan'];
        }

        // Fallback prodi dari periode aktif jika master kosong
        $missingJurEntitasIds = array_diff($ids, array_keys($jurMap));
        if (!empty($missingJurEntitasIds)) {
            $mPlaceholders = implode(',', array_fill(0, count($missingJurEntitasIds), '?'));
            $stmtUpjFallback = $pdo->prepare("
                SELECT upp.entitas_id, upj.jurusan_id, j.nama_jurusan, j.kode
                FROM unit_pelaksana_periode upp
                JOIN unit_periode_jurusan upj ON upp.id = upj.unit_pelaksana_periode_id
                JOIN jurusan j ON upj.jurusan_id = j.id
                JOIN periode pr ON upp.periode_id = pr.id
                WHERE upp.entitas_id IN ($mPlaceholders)
                  AND pr.status IN ('dibuka', 'persiapan')
                ORDER BY upp.id DESC
            ");
            $stmtUpjFallback->execute(array_values($missingJurEntitasIds));
            $upjFallbackRows = $stmtUpjFallback->fetchAll(PDO::FETCH_ASSOC);
            foreach ($upjFallbackRows as $row) {
                if (!isset($jurMap[$row['entitas_id']]) || !in_array((int)$row['jurusan_id'], $jurMap[$row['entitas_id']])) {
                    $jurMap[$row['entitas_id']][] = (int)$row['jurusan_id'];
                    $jurNamesMap[$row['entitas_id']][] = $row['nama_jurusan'];
                }
            }
        }

        // Fallback peminatan dari periode aktif jika master kosong
        $missingPemEntitasIds = array_diff($ids, array_keys($pemMap));
        if (!empty($missingPemEntitasIds)) {
            $mPlaceholdersPem = implode(',', array_fill(0, count($missingPemEntitasIds), '?'));
            $stmtUppFallback = $pdo->prepare("
                SELECT upp.entitas_id, uppem.peminatan_id, p.nama AS nama_peminatan
                FROM unit_pelaksana_periode upp
                JOIN unit_periode_peminatan uppem ON upp.id = uppem.unit_pelaksana_periode_id
                JOIN peminatan p ON uppem.peminatan_id = p.id
                JOIN periode pr ON upp.periode_id = pr.id
                WHERE upp.entitas_id IN ($mPlaceholdersPem)
                  AND pr.status IN ('dibuka', 'persiapan')
                ORDER BY upp.id DESC
            ");
            $stmtUppFallback->execute(array_values($missingPemEntitasIds));
            $uppFallbackRows = $stmtUppFallback->fetchAll(PDO::FETCH_ASSOC);
            foreach ($uppFallbackRows as $row) {
                if (!isset($pemMap[$row['entitas_id']]) || !in_array((int)$row['peminatan_id'], $pemMap[$row['entitas_id']])) {
                    $pemMap[$row['entitas_id']][] = (int)$row['peminatan_id'];
                    $pemNamesMap[$row['entitas_id']][] = $row['nama_peminatan'];
                }
            }
        }

        foreach ($data as &$item) {
            $item['peminatan_ids']   = $pemMap[$item['id']] ?? [];
            $item['peminatan_names'] = $pemNamesMap[$item['id']] ?? [];
            $item['prodi_ids']       = $jurMap[$item['id']] ?? [];
            $item['prodi_names']     = $jurNamesMap[$item['id']] ?? [];
        }
        unset($item);
    }

    // Cast tipe data
    foreach ($data as &$item) {
        $item['id']              = (int)$item['id'];
        $item['parent_id']       = $item['parent_id'] !== null ? (int)$item['parent_id'] : null;
        $item['aktif']           = (bool)$item['aktif'];
        $item['menerima_magang'] = (bool)$item['menerima_magang'];
        $item['upp_id']          = $item['upp_id'] !== null ? (int)$item['upp_id'] : null;
        $item['upp_aktif']       = $item['upp_aktif'] !== null ? (int)$item['upp_aktif'] : null;
        $item['kuota_total']     = $item['kuota_total'] !== null ? (int)$item['kuota_total'] : null;
        $item['kuota_tersisa']   = $item['kuota_tersisa'] !== null ? (int)$item['kuota_tersisa'] : null;
        $item['tipe_kuota']      = $item['tipe_kuota'] ?? 'keseluruhan';
    }
    unset($item);

    $totalPages = $noPaginate ? 1 : (int)ceil($totalRows / $limit);
    $from = $totalRows === 0 ? 0 : ($offset + 1);
    $to = $noPaginate ? $totalRows : min($totalRows, $offset + count($data));

    echo json_encode([
        'ok'            => true,
        'periode_aktif' => $activePeriode,
        'data'          => $data,
        'pagination'    => [
            'total'       => $totalRows,
            'page'        => $noPaginate ? 1 : $page,
            'limit'       => $noPaginate ? $totalRows : $limit,
            'total_pages' => $totalPages,
            'from'        => $from,
            'to'          => $to
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat daftar entitas.')]);
}

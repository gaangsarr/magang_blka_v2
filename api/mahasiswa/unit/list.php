<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PengajuanUnitHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard auth mahasiswa
Auth::requireMahasiswaApi();
Auth::rateLimitByIp('mhs_unit_list', 100, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $mahasiswa = Auth::getMahasiswa();
    $mhsId = Auth::getMahasiswaId();
    $mhsJurusanId = (int)($mahasiswa['jurusan_id'] ?? 0);

    // Ambil periode acuan aktif (dibuka / persiapan)
    $periodeAcuan = PengajuanUnitHelper::getPeriodeAcuan($pdo);
    $periodeId = $periodeAcuan ? (int)$periodeAcuan['id'] : null;

    // Filter query parameters
    $search = trim((string)($_GET['search'] ?? ''));
    $tipe = trim((string)($_GET['tipe'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? 'semua')); // semua, buka_kuota, belum_buka
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $whereClauses = ['e.aktif = 1'];
    $params = [];

    if ($search !== '') {
        $whereClauses[] = '(e.nama LIKE :s1 OR e.singkatan LIKE :s2 OR e.alamat LIKE :s3 OR parent.nama LIKE :s4)';
        $searchVal = "%{$search}%";
        $params[':s1'] = $searchVal;
        $params[':s2'] = $searchVal;
        $params[':s3'] = $searchVal;
        $params[':s4'] = $searchVal;
    }

    if ($tipe !== '' && in_array($tipe, ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana', 'unit_layanan'], true)) {
        $whereClauses[] = 'e.tipe = :tipe';
        $params[':tipe'] = $tipe;
    }

    if ($statusFilter === 'buka_kuota' && $periodeId) {
        $whereClauses[] = 'upp.id IS NOT NULL AND upp.aktif = 1 AND upp.kuota_tersisa > 0';
    } elseif ($statusFilter === 'belum_buka' && $periodeId) {
        $whereClauses[] = '(upp.id IS NULL OR upp.aktif = 0 OR upp.kuota_tersisa = 0)';
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Hitung total item untuk pagination
    $countSql = "
        SELECT COUNT(*)
        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
        " . ($periodeId ? "LEFT JOIN unit_pelaksana_periode upp ON upp.entitas_id = e.id AND upp.periode_id = {$periodeId}" : "") . "
        WHERE {$whereSql}
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalItems = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalItems / $limit));

    // Kolom Whitelist ketat: HAPUS lat, lng, pic_nama, pic_kontak, pic_email
    $selectSql = "
        SELECT 
            e.id,
            e.tipe,
            e.nama,
            e.singkatan,
            e.alamat,
            e.menerima_magang,
            parent.nama AS parent_nama,
            parent.singkatan AS parent_singkatan,
            
            -- Info Kuota Periode Acuan (jika ada)
            " . ($periodeId ? "
            upp.id AS upp_id,
            upp.tipe_kuota,
            upp.kuota_total,
            upp.kuota_tersisa,
            upp.aktif AS upp_aktif,
            upj.kuota_total AS prodi_kuota_total,
            upj.kuota_tersisa AS prodi_kuota_tersisa,
            " : "
            NULL AS upp_id,
            NULL AS tipe_kuota,
            NULL AS kuota_total,
            NULL AS kuota_tersisa,
            NULL AS upp_aktif,
            NULL AS prodi_kuota_total,
            NULL AS prodi_kuota_tersisa,
            ") . "

            -- Cek status pengajuan milik mahasiswa yang sedang login
            pum.id AS pengajuan_id,
            pum.jenis AS pengajuan_jenis,
            pum.status AS pengajuan_status,
            pum.alasan_admin AS pengajuan_alasan,
            pum.created_at AS pengajuan_waktu

        FROM entitas_perusahaan e
        LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
        " . ($periodeId ? "
        LEFT JOIN unit_pelaksana_periode upp ON upp.entitas_id = e.id AND upp.periode_id = {$periodeId}
        LEFT JOIN unit_periode_jurusan upj ON upj.unit_pelaksana_periode_id = upp.id AND upj.jurusan_id = {$mhsJurusanId}
        " : "") . "
        LEFT JOIN pengajuan_unit_mahasiswa pum ON pum.entitas_id = e.id 
            AND pum.mahasiswa_id = :mid 
            AND pum.status IN ('menunggu', 'diproses', 'disetujui')

        WHERE {$whereSql}

        ORDER BY 
            (pum.id IS NOT NULL) DESC,
            " . ($periodeId ? "(upp.id IS NOT NULL AND upp.aktif = 1 AND upp.kuota_tersisa > 0) DESC," : "") . "
            e.parent_id ASC,
            e.tipe ASC,
            e.nama ASC

        LIMIT {$limit} OFFSET {$offset}
    ";

    $params[':mid'] = $mhsId;
    $stmtSelect = $pdo->prepare($selectSql);
    $stmtSelect->execute($params);
    $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

    // Ambil daftar rincian jurusan per upp_id untuk unit yang membuka kuota
    $uppIds = array_filter(array_column($rows, 'upp_id'));
    $jurusanMap = [];
    if (!empty($uppIds)) {
        $inUpp = implode(',', array_map('intval', $uppIds));
        $stmtJur = $pdo->query("
            SELECT upj.unit_pelaksana_periode_id, j.nama_jurusan AS jurusan_nama, j.jenjang, upj.kuota_total, upj.kuota_tersisa
            FROM unit_periode_jurusan upj
            JOIN jurusan j ON j.id = upj.jurusan_id
            WHERE upj.unit_pelaksana_periode_id IN ({$inUpp})
            ORDER BY j.nama_jurusan ASC
        ");
        while ($jRow = $stmtJur->fetch(PDO::FETCH_ASSOC)) {
            $uId = (int)$jRow['unit_pelaksana_periode_id'];
            $jurusanMap[$uId][] = [
                'nama'          => $jRow['jurusan_nama'],
                'jenjang'       => $jRow['jenjang'],
                'kuota_total'   => (int)$jRow['kuota_total'],
                'kuota_tersisa' => (int)$jRow['kuota_tersisa'],
            ];
        }
    }

    $data = [];
    foreach ($rows as $r) {
        $uppId = !empty($r['upp_id']) ? (int)$r['upp_id'] : null;
        $uppAktif = !empty($r['upp_aktif']);
        $hasQuota = ($uppId !== null && $uppAktif);

        $kuotaInfo = null;
        if ($hasQuota) {
            $tipeKuota = $r['tipe_kuota'] ?? 'keseluruhan';
            $kuotaTotal = (int)($r['kuota_total'] ?? 0);
            $kuotaSisa = (int)($r['kuota_tersisa'] ?? 0);

            $prodiTotal = $r['prodi_kuota_total'] !== null ? (int)$r['prodi_kuota_total'] : null;
            $prodiSisa = $r['prodi_kuota_tersisa'] !== null ? (int)$r['prodi_kuota_tersisa'] : null;

            $kuotaInfo = [
                'tipe_kuota'       => $tipeKuota,
                'kuota_total'      => $kuotaTotal,
                'kuota_tersisa'    => $kuotaSisa,
                'prodi_sesuai'     => ($prodiTotal !== null),
                'prodi_tersisa'    => $prodiSisa,
                'prodi_total'      => $prodiTotal,
                'rincian_jurusan'  => $jurusanMap[$uppId] ?? []
            ];
        }

        $pengajuanSaya = null;
        if (!empty($r['pengajuan_id'])) {
            $pengajuanSaya = [
                'id'           => (int)$r['pengajuan_id'],
                'jenis'        => $r['pengajuan_jenis'],
                'status'       => $r['pengajuan_status'],
                'alasan_admin' => $r['pengajuan_alasan'],
                'waktu'        => $r['pengajuan_waktu']
            ];
        }

        $data[] = [
            'id'               => (int)$r['id'],
            'nama'             => $r['nama'],
            'singkatan'        => $r['singkatan'],
            'tipe'             => $r['tipe'],
            'alamat'           => $r['alamat'],
            'menerima_magang'  => (bool)$r['menerima_magang'],
            'parent_nama'      => $r['parent_nama'],
            'parent_singkatan' => $r['parent_singkatan'],
            'has_kuota'        => $hasQuota,
            'kuota'            => $kuotaInfo,
            'pengajuan_saya'   => $pengajuanSaya
        ];
    }

    // Hitung total pengajuan aktif mahasiswa untuk informasi limit
    $activeRequestCount = PengajuanUnitHelper::countActive($pdo, $mhsId);

    echo json_encode([
        'ok' => true,
        'periode_acuan' => $periodeAcuan ? [
            'id'     => (int)$periodeAcuan['id'],
            'nama'   => $periodeAcuan['nama'],
            'status' => $periodeAcuan['status']
        ] : null,
        'active_request_count' => $activeRequestCount,
        'max_request_limit'    => 3,
        'pagination' => [
            'current_page' => $page,
            'total_pages'  => $totalPages,
            'total_items'  => $totalItems,
            'per_page'     => $limit
        ],
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat katalog unit PLN.')]);
}

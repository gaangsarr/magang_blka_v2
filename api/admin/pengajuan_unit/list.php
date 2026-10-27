<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard ketat: Khusus Super Admin dan Admin BLKA (tolak Admin Perusahaan & Admin Prodi)
Auth::requireBlkaApi();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $tab = trim((string)($_GET['tab'] ?? 'kuota')); // 'kuota' | 'usulan'
    $status = trim((string)($_GET['status'] ?? 'semua'));
    $search = trim((string)($_GET['search'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    // Hitung KPI ringkasan untuk header dashboard admin
    $stmtKpi = $pdo->query("
        SELECT 
            COUNT(CASE WHEN jenis = 'request_kuota' AND status = 'menunggu' THEN 1 END) AS kuota_menunggu,
            COUNT(CASE WHEN jenis = 'request_kuota' AND status = 'diproses' THEN 1 END) AS kuota_diproses,
            COUNT(CASE WHEN jenis = 'usulan_unit_baru' AND status = 'menunggu' THEN 1 END) AS usulan_menunggu,
            COUNT(CASE WHEN jenis = 'usulan_unit_baru' AND status = 'diproses' THEN 1 END) AS usulan_diproses,
            COUNT(DISTINCT CASE WHEN status IN ('menunggu', 'diproses') THEN mahasiswa_id END) AS mhs_aktif_count,
            COUNT(CASE WHEN status = 'disetujui' THEN 1 END) AS total_disetujui
        FROM pengajuan_unit_mahasiswa
    ");
    $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    // =========================================================================
    // TAB 1: Permintaan Kuota Unit (Dikelompokkan per Entitas PLN)
    // =========================================================================
    if ($tab === 'kuota') {
        $whereClauses = ["p.jenis = 'request_kuota'"];
        $params = [];

        if ($status !== 'semua' && in_array($status, ['menunggu', 'diproses', 'disetujui', 'ditolak', 'dibatalkan'], true)) {
            $whereClauses[] = "p.status = :st";
            $params[':st'] = $status;
        } else {
            // Default tampilkan yang bukan dibatalkan
            $whereClauses[] = "p.status != 'dibatalkan'";
        }

        if ($search !== '') {
            $whereClauses[] = "(e.nama LIKE :s1 OR e.singkatan LIKE :s2 OR e.alamat LIKE :s3 OR m.nama LIKE :s4 OR m.nim LIKE :s5)";
            $searchVal = "%{$search}%";
            $params[':s1'] = $searchVal;
            $params[':s2'] = $searchVal;
            $params[':s3'] = $searchVal;
            $params[':s4'] = $searchVal;
            $params[':s5'] = $searchVal;
        }

        $whereSql = implode(' AND ', $whereClauses);

        // Hitung total unit yang memiliki pengajuan sesuai filter
        $countSql = "
            SELECT COUNT(DISTINCT p.entitas_id)
            FROM pengajuan_unit_mahasiswa p
            JOIN entitas_perusahaan e ON e.id = p.entitas_id
            JOIN mahasiswa m ON m.id = p.mahasiswa_id
            WHERE {$whereSql}
        ";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalItems = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, (int)ceil($totalItems / $limit));

        // Ambil daftar entitas yang diminta mahasiswa
        $groupSql = "
            SELECT 
                p.entitas_id,
                e.nama AS entitas_nama,
                e.singkatan AS entitas_singkatan,
                e.tipe AS entitas_tipe,
                e.alamat AS entitas_alamat,
                e.pic_nama,
                e.pic_kontak,
                e.pic_email,
                
                COUNT(p.id) AS total_pemohon,
                COUNT(CASE WHEN p.status = 'menunggu' THEN 1 END) AS count_menunggu,
                COUNT(CASE WHEN p.status = 'diproses' THEN 1 END) AS count_diproses,
                COUNT(CASE WHEN p.status = 'disetujui' THEN 1 END) AS count_disetujui,
                COUNT(CASE WHEN p.status = 'ditolak' THEN 1 END) AS count_ditolak,
                MAX(p.created_at) AS latest_request_at

            FROM pengajuan_unit_mahasiswa p
            JOIN entitas_perusahaan e ON e.id = p.entitas_id
            JOIN mahasiswa m ON m.id = p.mahasiswa_id
            WHERE {$whereSql}
            GROUP BY p.entitas_id, e.nama, e.singkatan, e.tipe, e.alamat, e.pic_nama, e.pic_kontak, e.pic_email
            ORDER BY 
                (COUNT(CASE WHEN p.status = 'menunggu' THEN 1 END)) DESC,
                (COUNT(CASE WHEN p.status = 'diproses' THEN 1 END)) DESC,
                MAX(p.created_at) DESC
            LIMIT {$limit} OFFSET {$offset}
        ";
        $stmtGroup = $pdo->prepare($groupSql);
        $stmtGroup->execute($params);
        $groups = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);

        // Ambil rincian mahasiswa untuk masing-masing entitas pada halaman ini
        $entitasIds = array_column($groups, 'entitas_id');
        $mhsDetailMap = [];
        $prodiSummaryMap = [];

        if (!empty($entitasIds)) {
            $inEnt = implode(',', array_map('intval', $entitasIds));
            $detailSql = "
                SELECT 
                    p.id,
                    p.entitas_id,
                    p.status,
                    p.alasan_admin,
                    p.created_at,
                    m.id AS mahasiswa_id,
                    m.nama AS mahasiswa_nama,
                    m.nim,
                    m.email AS mahasiswa_email,
                    j.nama_jurusan AS jurusan_nama
                FROM pengajuan_unit_mahasiswa p
                JOIN mahasiswa m ON m.id = p.mahasiswa_id
                LEFT JOIN jurusan j ON j.id = m.jurusan_id
                WHERE p.jenis = 'request_kuota' 
                  AND p.status != 'dibatalkan'
                  AND p.entitas_id IN ({$inEnt})
                ORDER BY p.id DESC
            ";
            $stmtDetail = $pdo->query($detailSql);
            while ($d = $stmtDetail->fetch(PDO::FETCH_ASSOC)) {
                $eid = (int)$d['entitas_id'];
                $mhsDetailMap[$eid][] = [
                    'id'             => (int)$d['id'],
                    'mahasiswa_id'   => (int)$d['mahasiswa_id'],
                    'nama'           => $d['mahasiswa_nama'],
                    'nim'            => $d['nim'],
                    'email'          => $d['mahasiswa_email'],
                    'jurusan'        => $d['jurusan_nama'] ?? 'Belum ditentukan',
                    'status'         => $d['status'],
                    'alasan_admin'   => $d['alasan_admin'],
                    'created_at'     => $d['created_at'],
                ];

                $jur = $d['jurusan_nama'] ?? 'Lainnya';
                $prodiSummaryMap[$eid][$jur] = ($prodiSummaryMap[$eid][$jur] ?? 0) + 1;
            }
        }

        $data = [];
        foreach ($groups as $g) {
            $eid = (int)$g['entitas_id'];
            $data[] = [
                'entitas_id'        => $eid,
                'nama'              => $g['entitas_nama'],
                'singkatan'         => $g['entitas_singkatan'],
                'tipe'              => $g['entitas_tipe'],
                'alamat'            => $g['entitas_alamat'],
                'pic_nama'          => $g['pic_nama'],
                'pic_kontak'        => $g['pic_kontak'],
                'pic_email'         => $g['pic_email'],
                'total_pemohon'     => (int)$g['total_pemohon'],
                'count_menunggu'    => (int)$g['count_menunggu'],
                'count_diproses'    => (int)$g['count_diproses'],
                'count_disetujui'   => (int)$g['count_disetujui'],
                'count_ditolak'     => (int)$g['count_ditolak'],
                'latest_request_at' => $g['latest_request_at'],
                'prodi_summary'     => $prodiSummaryMap[$eid] ?? [],
                'pemohon_list'      => $mhsDetailMap[$eid] ?? []
            ];
        }

        echo json_encode([
            'ok'         => true,
            'tab'        => 'kuota',
            'kpi'        => $kpi,
            'pagination' => [
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'total_items'  => $totalItems,
                'per_page'     => $limit
            ],
            'data'       => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // =========================================================================
    // TAB 2: Usulan Unit Baru (Belum Terdaftar)
    // =========================================================================
    if ($tab === 'usulan') {
        $whereClauses = ["p.jenis = 'usulan_unit_baru'"];
        $params = [];

        if ($status !== 'semua' && in_array($status, ['menunggu', 'diproses', 'disetujui', 'ditolak', 'dibatalkan'], true)) {
            $whereClauses[] = "p.status = :st";
            $params[':st'] = $status;
        } else {
            $whereClauses[] = "p.status != 'dibatalkan'";
        }

        if ($search !== '') {
            $whereClauses[] = "(p.nama_unit_baru LIKE :s1 OR p.alamat_unit_baru LIKE :s2 OR p.pic_nama LIKE :s3 OR p.pic_kontak LIKE :s4 OR m.nama LIKE :s5 OR m.nim LIKE :s6)";
            $searchVal = "%{$search}%";
            $params[':s1'] = $searchVal;
            $params[':s2'] = $searchVal;
            $params[':s3'] = $searchVal;
            $params[':s4'] = $searchVal;
            $params[':s5'] = $searchVal;
            $params[':s6'] = $searchVal;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $countSql = "
            SELECT COUNT(*)
            FROM pengajuan_unit_mahasiswa p
            JOIN mahasiswa m ON m.id = p.mahasiswa_id
            WHERE {$whereSql}
        ";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalItems = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, (int)ceil($totalItems / $limit));

        $selectSql = "
            SELECT 
                p.id,
                p.nama_unit_baru,
                p.alamat_unit_baru,
                p.pic_nama,
                p.pic_kontak,
                p.pic_email,
                p.catatan,
                p.status,
                p.alasan_admin,
                p.entitas_id_hasil,
                p.created_at,
                p.diproses_at,
                
                m.id AS pengusul_id,
                m.nama AS pengusul_nama,
                m.nim AS pengusul_nim,
                m.email AS pengusul_email,
                j.nama_jurusan AS pengusul_jurusan,
                
                res_ent.nama AS hasil_entitas_nama,
                res_ent.tipe AS hasil_entitas_tipe,
                
                -- Cek apakah entitas hasil sudah memiliki akun admin_perusahaan
                (SELECT a.id FROM admin a WHERE a.entitas_id = p.entitas_id_hasil AND a.role = 'admin_perusahaan' LIMIT 1) AS admin_perusahaan_id,
                (SELECT a.username FROM admin a WHERE a.entitas_id = p.entitas_id_hasil AND a.role = 'admin_perusahaan' LIMIT 1) AS admin_perusahaan_username

            FROM pengajuan_unit_mahasiswa p
            JOIN mahasiswa m ON m.id = p.mahasiswa_id
            LEFT JOIN jurusan j ON j.id = m.jurusan_id
            LEFT JOIN entitas_perusahaan res_ent ON res_ent.id = p.entitas_id_hasil
            WHERE {$whereSql}
            ORDER BY 
                CASE p.status 
                    WHEN 'menunggu' THEN 1 
                    WHEN 'diproses' THEN 2 
                    WHEN 'disetujui' THEN 3 
                    WHEN 'ditolak' THEN 4 
                    ELSE 5 
                END ASC,
                p.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmtSelect = $pdo->prepare($selectSql);
        $stmtSelect->execute($params);
        $rows = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

        // Ambil daftar pendukung untuk masing-masing usulan
        $usulanIds = array_column($rows, 'id');
        $supportersMap = [];
        if (!empty($usulanIds)) {
            $inIds = implode(',', array_map('intval', $usulanIds));
            $stmtSup = $pdo->query("
                SELECT 
                    p2.id,
                    p2.usulan_induk_id,
                    p2.status,
                    p2.created_at,
                    m.id AS mahasiswa_id,
                    m.nama,
                    m.nim,
                    j.nama_jurusan AS jurusan
                FROM pengajuan_unit_mahasiswa p2
                JOIN mahasiswa m ON m.id = p2.mahasiswa_id
                LEFT JOIN jurusan j ON j.id = m.jurusan_id
                WHERE p2.jenis = 'dukungan_usulan' 
                  AND p2.status != 'dibatalkan'
                  AND p2.usulan_induk_id IN ({$inIds})
                ORDER BY p2.id ASC
            ");
            while ($s = $stmtSup->fetch(PDO::FETCH_ASSOC)) {
                $uid = (int)$s['usulan_induk_id'];
                $supportersMap[$uid][] = [
                    'id'           => (int)$s['id'],
                    'mahasiswa_id' => (int)$s['mahasiswa_id'],
                    'nama'         => $s['nama'],
                    'nim'          => $s['nim'],
                    'jurusan'      => $s['jurusan'] ?? 'Belum ditentukan',
                    'status'       => $s['status'],
                    'created_at'   => $s['created_at'],
                ];
            }
        }

        $data = [];
        foreach ($rows as $r) {
            $uid = (int)$r['id'];
            $supporters = $supportersMap[$uid] ?? [];
            $hasAccount = !empty($r['admin_perusahaan_id']);

            $data[] = [
                'id'               => $uid,
                'nama_unit'        => $r['nama_unit_baru'],
                'alamat'           => $r['alamat_unit_baru'],
                'pic_nama'         => $r['pic_nama'],
                'pic_kontak'       => $r['pic_kontak'],
                'pic_email'        => $r['pic_email'],
                'catatan'          => $r['catatan'],
                'status'           => $r['status'],
                'alasan_admin'     => $r['alasan_admin'],
                'entitas_id_hasil' => !empty($r['entitas_id_hasil']) ? (int)$r['entitas_id_hasil'] : null,
                'entitas_hasil'    => !empty($r['hasil_entitas_nama']) ? [
                    'nama' => $r['hasil_entitas_nama'],
                    'tipe' => $r['hasil_entitas_tipe'],
                ] : null,
                'has_admin_account'       => $hasAccount,
                'admin_perusahaan_username'=> $r['admin_perusahaan_username'] ?? null,
                'created_at'       => $r['created_at'],
                'diproses_at'      => $r['diproses_at'],
                'pengusul'         => [
                    'id'      => (int)$r['pengusul_id'],
                    'nama'    => $r['pengusul_nama'],
                    'nim'     => $r['pengusul_nim'],
                    'email'   => $r['pengusul_email'],
                    'jurusan' => $r['pengusul_jurusan'] ?? 'Belum ditentukan',
                ],
                'jumlah_pendukung' => count($supporters) + 1, // +1 pengusul awal
                'pendukung_list'   => $supporters
            ];
        }

        echo json_encode([
            'ok'         => true,
            'tab'        => 'usulan',
            'kpi'        => $kpi,
            'pagination' => [
                'current_page' => $page,
                'total_pages'  => $totalPages,
                'total_items'  => $totalItems,
                'per_page'     => $limit
            ],
            'data'       => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Tab tidak valid.']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat daftar pengajuan.')]);
}

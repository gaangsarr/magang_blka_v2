<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

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

    // 1. Ambil Periode Selector
    $stmtAllP = $pdo->query("SELECT id, nama, tanggal_mulai, tanggal_selesai, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAllP->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        foreach ($allPeriode as $p) {
            if ((int)$p['id'] === $requestedId) {
                $periode = $p;
                break;
            }
        }
    }

    if (!$periode) {
        // Cari periode dibuka atau persiapan
        foreach ($allPeriode as $p) {
            if (in_array($p['status'], ['dibuka', 'persiapan'], true)) {
                $periode = $p;
                break;
            }
        }
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    if (!$periode) {
        echo json_encode([
            'ok' => true,
            'all_periode' => [],
            'selected_periode' => null,
            'total' => 0,
            'data' => [],
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 2. Query filter & search
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $search = trim((string)($_GET['search'] ?? ''));
    $filterStatus = trim((string)($_GET['filter_status'] ?? ''));
    $filterProgram = trim((string)($_GET['filter_program'] ?? ''));
    $filterJurusan = (int)($_GET['filter_jurusan'] ?? 0);

    $whereClauses = [
        'p.periode_id = :pid',
        'upp.entitas_id = :eid'
    ];
    $params = [
        ':pid' => $periodeId,
        ':eid' => $entitasId,
    ];

    if ($search !== '') {
        $whereClauses[] = '(m.nim LIKE :s_nim OR p.nama_snapshot LIKE :s_nama OR m.email LIKE :s_email OR p.no_hp LIKE :s_hp)';
        $wildcard = '%' . $search . '%';
        $params[':s_nim'] = $wildcard;
        $params[':s_nama'] = $wildcard;
        $params[':s_email'] = $wildcard;
        $params[':s_hp'] = $wildcard;
    }

    if ($filterStatus !== '') {
        $whereClauses[] = 'p.status = :status';
        $params[':status'] = $filterStatus;
    }

    if ($filterProgram !== '') {
        $whereClauses[] = 'p.program = :program';
        $params[':program'] = $filterProgram;
    }

    if ($filterJurusan > 0) {
        $whereClauses[] = 'm.jurusan_id = :jurusan_id';
        $params[':jurusan_id'] = $filterJurusan;
    }

    $whereSql = implode(' AND ', $whereClauses);

    // Count Total
    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        WHERE {$whereSql}
    ");
    $stmtCount->execute($params);
    $totalRecords = (int)$stmtCount->fetchColumn();

    // Data Pendaftar
    $stmtData = $pdo->prepare("
        SELECT 
            p.id AS pendaftaran_id,
            p.nama_snapshot,
            p.jenis_kelamin,
            p.ipk,
            p.jumlah_sks,
            p.transkrip_path,
            p.no_hp,
            p.program,
            p.status,
            p.is_dipindahkan,
            p.catatan_admin,
            p.submitted_at,
            p.alamat, p.rt, p.rw, p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi,
            m.id AS mahasiswa_id,
            m.nim,
            m.email,
            m.angkatan,
            j.id AS jurusan_id,
            j.nama_jurusan,
            j.kode AS kode_jurusan,
            j.jenjang,
            e.nama AS unit_tujuan_nama,
            p.unit_pelaksana_periode_asal_id,
            e_asal.nama AS unit_asal_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        WHERE {$whereSql}
        ORDER BY p.submitted_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmtData->execute($params);
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Ambil peminatan untuk pendaftar
    $pendaftaranIds = array_column($rows, 'pendaftaran_id');
    $peminatanMap = [];
    if (!empty($pendaftaranIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($pendaftaranIds), '?'));
        $stmtPem = $pdo->prepare("
            SELECT pp.pendaftaran_id, pem.nama AS nama_peminatan
            FROM pendaftaran_peminatan pp
            JOIN peminatan pem ON pp.peminatan_id = pem.id
            WHERE pp.pendaftaran_id IN ({$inPlaceholders})
            ORDER BY pem.nama ASC
        ");
        $stmtPem->execute($pendaftaranIds);
        while ($pemRow = $stmtPem->fetch(PDO::FETCH_ASSOC)) {
            $pId = (int)$pemRow['pendaftaran_id'];
            if (!isset($peminatanMap[$pId])) {
                $peminatanMap[$pId] = [];
            }
            $peminatanMap[$pId][] = $pemRow['nama_peminatan'];
        }
    }

    $formattedData = [];
    foreach ($rows as $r) {
        $pId = (int)$r['pendaftaran_id'];
        
        $domisiliParts = array_filter([
            $r['alamat'],
            ($r['rt'] || $r['rw']) ? ("RT {$r['rt']} / RW {$r['rw']}") : '',
            $r['kelurahan'] ? "Kel. {$r['kelurahan']}" : '',
            $r['kecamatan'] ? "Kec. {$r['kecamatan']}" : '',
            $r['kota_kabupaten'],
            $r['provinsi']
        ]);
        $alamatLengkap = implode(', ', $domisiliParts);

        $isMoved = ((int)$r['is_dipindahkan'] === 1) || ($r['status'] === 'dipindahkan');
        $namaJurusanLengkap = $r['nama_jurusan'] ? (($r['jenjang'] ? "{$r['jenjang']} - " : '') . $r['nama_jurusan']) : '-';

        $formattedData[] = [
            'id'               => $pId,
            'nama'             => $r['nama_snapshot'],
            'nim'              => $r['nim'],
            'email'            => $r['email'],
            'no_hp'            => $r['no_hp'] ?? '-',
            'jenis_kelamin'    => $r['jenis_kelamin'],
            'ipk'              => $r['ipk'] ? number_format((float)$r['ipk'], 2) : '-',
            'jumlah_sks'       => $r['jumlah_sks'] ? (int)$r['jumlah_sks'] : '-',
            'jurusan_nama'     => $namaJurusanLengkap,
            'kode_jurusan'     => $r['kode_jurusan'] ?? '',
            'jenjang'          => $r['jenjang'] ?? 'S1',
            'angkatan'         => $r['angkatan'] ?? '-',
            'program'          => $r['program'] === '5_bulan' ? '5 Bulan' : '1 Bulan',
            'status'           => $r['status'],
            'is_dipindahkan'   => $isMoved,
            'unit_asal_nama'   => $r['unit_asal_nama'] ?? null,
            'unit_tujuan_nama' => $r['unit_tujuan_nama'] ?? null,
            'catatan_admin'    => $r['catatan_admin'] ?? null,
            'alamat_lengkap'   => $alamatLengkap ?: '-',
            'peminatan'        => $peminatanMap[$pId] ?? [],
            'submitted_at'     => $r['submitted_at'],
        ];
    }

    echo json_encode([
        'ok' => true,
        'all_periode' => $allPeriode,
        'selected_periode' => [
            'id'     => $periodeId,
            'nama'   => $periode['nama'],
            'status' => $periode['status'],
        ],
        'pagination' => [
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $totalRecords,
            'total_pages' => (int)ceil($totalRecords / $perPage),
        ],
        'data' => $formattedData,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data pendaftar.')]);
}

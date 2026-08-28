<?php
/**
 * api/public/penempatan.php
 * API Publik untuk Rekapitulasi Penempatan Mahasiswa berdasarkan Token Unik Periode.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\SuratGenerator;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getInstance();

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Akses ditolak. Tautan memerlukan token otentikasi resmi.'
        ]);
        exit;
    }

    $periode = SuratGenerator::getPeriodeByToken($pdo, $token);
    if (!$periode) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'Tautan pengumuman penempatan tidak valid atau telah diperbarui.'
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 1. Ambil daftar Unit yang ADA mahasiswa diterima pada periode ini
    $stmtUnits = $pdo->prepare("
        SELECT DISTINCT e.id, e.nama
        FROM pendaftaran p
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE p.periode_id = :pid AND p.status = 'diterima'
        ORDER BY e.nama ASC
    ");
    $stmtUnits->execute([':pid' => $periodeId]);
    $unitList = $stmtUnits->fetchAll(PDO::FETCH_ASSOC);

    // 2. Ambil daftar Jurusan yang ADA mahasiswa diterima pada periode ini
    $stmtJurusan = $pdo->prepare("
        SELECT DISTINCT j.id, j.nama_jurusan AS nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN jurusan j ON m.jurusan_id = j.id
        WHERE p.periode_id = :pid AND p.status = 'diterima'
        ORDER BY j.nama_jurusan ASC
    ");
    $stmtJurusan->execute([':pid' => $periodeId]);
    $jurusanList = $stmtJurusan->fetchAll(PDO::FETCH_ASSOC);

    // 3. Stats Ringkasan
    $stmtStats = $pdo->prepare("
        SELECT 
            COUNT(p.id) AS total_mahasiswa,
            COUNT(DISTINCT upp.entitas_id) AS total_unit,
            COUNT(DISTINCT m.jurusan_id) AS total_prodi
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        WHERE p.periode_id = :pid AND p.status = 'diterima'
    ");
    $stmtStats->execute([':pid' => $periodeId]);
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [
        'total_mahasiswa' => 0,
        'total_unit' => 0,
        'total_prodi' => 0
    ];

    // 4. Parameter Filter & Pagination
    $search = trim((string)($_GET['search'] ?? ''));
    $unitFilter = (int)($_GET['unit_id'] ?? 0);
    $jurusanFilter = (int)($_GET['jurusan_id'] ?? 0);
    $programFilter = trim((string)($_GET['program'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(10, min(100, (int)($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $perPage;

    $where = ["p.periode_id = :pid", "p.status = 'diterima'"];
    $params = [':pid' => $periodeId];

    if ($unitFilter > 0) {
        $where[] = "upp.entitas_id = :uid";
        $params[':uid'] = $unitFilter;
    }

    if ($jurusanFilter > 0) {
        $where[] = "m.jurusan_id = :jid";
        $params[':jid'] = $jurusanFilter;
    }

    if ($programFilter !== '') {
        $where[] = "p.program = :program";
        $params[':program'] = $programFilter;
    }

    if ($search !== '') {
        $where[] = "(m.nim LIKE :s OR p.nama_snapshot LIKE :s OR e.nama LIKE :s OR j.nama_jurusan LIKE :s)";
        $params[':s'] = "%{$search}%";
    }

    $whereSql = implode(' AND ', $where);

    // Hitung total data hasil filter
    $countSql = "
        SELECT COUNT(DISTINCT p.id)
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE {$whereSql}
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalFiltered = (int)$stmtCount->fetchColumn();

    // Data rows
    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            j.nama_jurusan AS prodi,
            p.program,
            e.nama AS unit_penempatan,
            COALESCE(GROUP_CONCAT(DISTINCT pem.nama SEPARATOR ', '), '-') AS peminatan
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN pendaftaran_peminatan pp ON p.id = pp.pendaftaran_id
        LEFT JOIN peminatan pem ON pp.peminatan_id = pem.id
        WHERE {$whereSql}
        GROUP BY p.id, m.nim, p.nama_snapshot, j.nama_jurusan, p.program, e.nama
        ORDER BY e.nama ASC, j.nama_jurusan ASC, p.nama_snapshot ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmtData = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmtData->bindValue($k, $v);
    }
    $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtData->execute();

    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'periode' => [
            'id' => $periode['id'],
            'nama' => $periode['nama'],
            'tahun_akademik' => $periode['tahun_akademik'] ?? '',
            'perihal' => $periode['perihal'] ?? '',
            'tanggal_surat' => $periode['tanggal_surat'] ?? '',
            'status' => $periode['status'],
            'program_1_bulan' => (int)$periode['program_1_bulan'],
            'program_5_bulan' => (int)$periode['program_5_bulan']
        ],
        'stats' => [
            'total_mahasiswa' => (int)$stats['total_mahasiswa'],
            'total_unit' => (int)$stats['total_unit'],
            'total_prodi' => (int)$stats['total_prodi']
        ],
        'unit_list' => $unitList,
        'jurusan_list' => $jurusanList,
        'data' => $rows,
        'pagination' => [
            'total' => $totalFiltered,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int)ceil($totalFiltered / $perPage)
        ]
    ]);

} catch (\Throwable $e) {
    error_log('[api/public/penempatan.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Terjadi kesalahan sistem saat memproses data penempatan.'
    ]);
}

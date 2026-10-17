<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
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

    // Ambil data detail prodi
    $stmtProdi = $pdo->prepare("SELECT id, kode, jenjang, nama_jurusan FROM jurusan WHERE id = ? LIMIT 1");
    $stmtProdi->execute([$jurusanId]);
    $prodi = $stmtProdi->fetch(PDO::FETCH_ASSOC);

    if (!$prodi) {
        http_response_code(404);
        echo json_encode(['error' => 'Data Program Studi tidak ditemukan.']);
        exit;
    }

    // Ambil daftar semua periode untuk selector
    $stmtAll = $pdo->query("SELECT id, nama, status, tanggal_mulai, tanggal_selesai FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status, tanggal_mulai, tanggal_selesai FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        // Cari periode aktif (dibuka atau persiapan) terlebih dahulu
        $stmtPeriode = $pdo->query("SELECT id, nama, status, tanggal_mulai, tanggal_selesai FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    if (!$periode) {
        echo json_encode([
            'ok'               => true,
            'prodi'            => $prodi,
            'periode_terpilih' => null,
            'all_periode'      => [],
            'stats'            => [
                'total_pendaftar' => 0,
                'diterima'        => 0,
                'diproses'        => 0,
                'cadangan'        => 0,
                'ditolak'         => 0,
                'dipindahkan'     => 0,
            ],
            'breakdown_program' => [],
            'top_units'         => [],
            'peminatan_stat'    => [],
            'recent_pendaftar'  => []
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 1. KPI Counts
    $stmtKpi = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_pendaftar,
            SUM(CASE WHEN p.status = 'diterima' THEN 1 ELSE 0 END) AS diterima,
            SUM(CASE WHEN p.status IN ('menunggu', 'diproses') THEN 1 ELSE 0 END) AS diproses,
            SUM(CASE WHEN p.status = 'cadangan' THEN 1 ELSE 0 END) AS cadangan,
            SUM(CASE WHEN p.status = 'ditolak' THEN 1 ELSE 0 END) AS ditolak,
            SUM(CASE WHEN p.status = 'dipindahkan' THEN 1 ELSE 0 END) AS dipindahkan
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        WHERE p.periode_id = :periode_id AND m.jurusan_id = :jurusan_id
    ");
    $stmtKpi->execute([
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ]);
    $kpiRaw = $stmtKpi->fetch(PDO::FETCH_ASSOC);

    $stats = [
        'total_pendaftar' => (int)($kpiRaw['total_pendaftar'] ?? 0),
        'diterima'        => (int)($kpiRaw['diterima'] ?? 0),
        'diproses'        => (int)($kpiRaw['diproses'] ?? 0),
        'cadangan'        => (int)($kpiRaw['cadangan'] ?? 0),
        'ditolak'         => (int)($kpiRaw['ditolak'] ?? 0),
        'dipindahkan'     => (int)($kpiRaw['dipindahkan'] ?? 0),
    ];

    // 2. Breakdown Program Magang (1 bulan, 3 bulan, dst)
    $stmtProgram = $pdo->prepare("
        SELECT 
            CASE p.program
                WHEN '1_bulan' THEN '1 Bulan'
                WHEN '3_bulan' THEN '3 Bulan'
                WHEN '4_bulan' THEN '4 Bulan'
                WHEN '5_bulan' THEN '5 Bulan'
                WHEN '6_bulan' THEN '6 Bulan'
                ELSE IFNULL(NULLIF(p.program, ''), 'Tidak Spesifik')
            END AS nama_program,
            COUNT(*) AS total
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        WHERE p.periode_id = :periode_id AND m.jurusan_id = :jurusan_id
        GROUP BY p.program
        ORDER BY total DESC
    ");
    $stmtProgram->execute([
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ]);
    $breakdownProgram = $stmtProgram->fetchAll(PDO::FETCH_ASSOC);

    // 3. Top PLN Units tujuan mahasiswa prodi
    $stmtTopUnits = $pdo->prepare("
        SELECT 
            ep.id,
            ep.nama AS unit_nama,
            ep.singkatan,
            COUNT(*) AS total_pelamar,
            SUM(CASE WHEN p.status = 'diterima' THEN 1 ELSE 0 END) AS total_diterima
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        WHERE p.periode_id = :periode_id AND m.jurusan_id = :jurusan_id
        GROUP BY ep.id, ep.nama, ep.singkatan
        ORDER BY total_pelamar DESC
        LIMIT 6
    ");
    $stmtTopUnits->execute([
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ]);
    $topUnits = $stmtTopUnits->fetchAll(PDO::FETCH_ASSOC);

    // 4. Distribusi Peminatan yang dipilih oleh mahasiswa prodi
    $stmtPem = $pdo->prepare("
        SELECT 
            pem.id,
            pem.nama AS nama_peminatan,
            COUNT(DISTINCT p.id) AS total_peminat
        FROM pendaftaran_peminatan pp
        JOIN peminatan pem ON pp.peminatan_id = pem.id
        JOIN pendaftaran p ON pp.pendaftaran_id = p.id
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        WHERE p.periode_id = :periode_id AND m.jurusan_id = :jurusan_id
        GROUP BY pem.id, pem.nama
        ORDER BY total_peminat DESC
    ");
    $stmtPem->execute([
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ]);
    $peminatanStat = $stmtPem->fetchAll(PDO::FETCH_ASSOC);

    // 5. Recent 5 Pendaftar dari prodi ini
    $stmtRecent = $pdo->prepare("
        SELECT 
            p.id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            CASE p.program
                WHEN '1_bulan' THEN '1 Bulan'
                WHEN '3_bulan' THEN '3 Bulan'
                WHEN '4_bulan' THEN '4 Bulan'
                WHEN '5_bulan' THEN '5 Bulan'
                WHEN '6_bulan' THEN '6 Bulan'
                ELSE IFNULL(NULLIF(p.program, ''), '-')
            END AS program,
            p.ipk,
            p.status,
            p.submitted_at,
            ep.nama AS unit_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        WHERE p.periode_id = :periode_id AND m.jurusan_id = :jurusan_id
        ORDER BY p.submitted_at DESC
        LIMIT 5
    ");
    $stmtRecent->execute([
        ':periode_id' => $periodeId,
        ':jurusan_id' => $jurusanId
    ]);
    $recentPendaftar = $stmtRecent->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'                => true,
        'prodi'             => $prodi,
        'periode_terpilih'  => $periode,
        'all_periode'       => $allPeriode,
        'stats'             => $stats,
        'breakdown_program' => $breakdownProgram,
        'top_units'         => $topUnits,
        'peminatan_stat'    => $peminatanStat,
        'recent_pendaftar'  => $recentPendaftar
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan saat memuat dashboard program studi.')]);
}

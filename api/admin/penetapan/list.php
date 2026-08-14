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
    $stmtAll = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtPeriode = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    // 2. Fetch master list jurusan untuk filter
    $stmtJ = $pdo->query("SELECT id, nama_jurusan AS nama, kode FROM jurusan ORDER BY nama_jurusan ASC");
    $jurusanList = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

    if (!$periode) {
        echo json_encode([
            'ok' => true,
            'periode_terpilih' => null,
            'all_periode' => [],
            'jurusan_list' => $jurusanList,
            'data' => [],
            'message' => 'Belum ada periode magang.'
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 3. Fetch data pendaftaran beserta jurusan, unit asal & unit baru
    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            p.mahasiswa_id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            m.jurusan_id,
            j.nama_jurusan AS jurusan_nama,
            p.program,
            p.status,
            p.is_dipindahkan,
            p.catatan_admin,
            p.submitted_at,
            p.unit_pelaksana_periode_id,
            e.nama AS unit_nama,
            p.unit_pelaksana_periode_asal_id,
            e_asal.nama AS unit_asal_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        WHERE p.periode_id = :pid
        ORDER BY p.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid' => $periodeId]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'periode_id' => $periodeId,
        'periode_terpilih' => $periode,
        'periode_nama' => $periode['nama'],
        'all_periode' => $allPeriode,
        'jurusan_list' => $jurusanList,
        'data' => $list
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data penetapan: ' . $e->getMessage()]);
}

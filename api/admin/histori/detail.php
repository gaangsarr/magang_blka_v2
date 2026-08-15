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

$periodeId = $_GET['periode_id'] ?? null;
if (!$periodeId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter periode_id wajib.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $periodeId = (int) $periodeId;

    // 1. Fetch periode info
    $stmtP = $pdo->prepare("SELECT id, nama, status, tanggal_mulai, tanggal_selesai FROM periode WHERE id = :id");
    $stmtP->execute([':id' => $periodeId]);
    $periode = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        http_response_code(404);
        echo json_encode(['error' => 'Periode tidak ditemukan.']);
        exit;
    }

    // 2. Fetch all registrants for this period
    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            j.nama_jurusan AS jurusan,
            p.program,
            p.status,
            p.is_dipindahkan,
            p.catatan_admin,
            p.submitted_at,
            e.nama AS unit_nama,
            e_asal.nama AS unit_asal_nama,
            CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili
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
    $pendaftar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Statistics
    $stats = [
        'total'       => count($pendaftar),
        'diajukan'    => 0,
        'diverifikasi' => 0,
        'diterima'    => 0,
        'ditolak'     => 0,
        'dipindahkan' => 0,
    ];

    foreach ($pendaftar as $p) {
        $st = strtolower((string)$p['status']);
        $isMoved = ((int)$p['is_dipindahkan'] === 1) || ($st === 'dipindahkan');

        if ($st === 'diterima' || $st === 'diverifikasi') {
            $stats['diterima']++;
            $stats['diverifikasi']++;
        } elseif ($st === 'ditolak') {
            $stats['ditolak']++;
        } elseif ($st === 'diajukan') {
            $stats['diajukan']++;
        } elseif ($st === 'dipindahkan') {
            $stats['diterima']++;
            $stats['diverifikasi']++;
        }

        // Count dipindahkan ONCE per record
        if ($isMoved) {
            $stats['dipindahkan']++;
        }
    }

    // 4. Distribution per jurusan
    $perJurusan = [];
    foreach ($pendaftar as $p) {
        $jur = $p['jurusan'] ?: 'Tidak Diketahui';
        $perJurusan[$jur] = ($perJurusan[$jur] ?? 0) + 1;
    }
    arsort($perJurusan);

    // 5. Top unit pelaksana
    $perUnit = [];
    foreach ($pendaftar as $p) {
        $unit = $p['unit_nama'] ?: 'Unknown';
        $perUnit[$unit] = ($perUnit[$unit] ?? 0) + 1;
    }
    arsort($perUnit);
    $topUnits = array_slice($perUnit, 0, 10, true);

    // 6. Distribution per status
    $perStatus = [
        'Diajukan'    => $stats['diajukan'],
        'Diterima'    => $stats['diterima'],
        'Ditolak'     => $stats['ditolak'],
        'Dipindahkan' => $stats['dipindahkan'],
    ];

    echo json_encode([
        'ok'          => true,
        'periode'     => $periode,
        'stats'       => $stats,
        'per_jurusan' => $perJurusan,
        'top_units'   => $topUnits,
        'per_status'  => $perStatus,
        'data'        => $pendaftar,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil detail histori.')]);
}


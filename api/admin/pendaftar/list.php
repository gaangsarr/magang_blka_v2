<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil daftar semua periode untuk dropdown
    $stmtAll = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        // Cari periode aktif (dibuka atau persiapan) terlebih dahulu
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        // Fallback ke periode terbaru
        $periode = $allPeriode[0];
    }

    if (!$periode) {
        echo json_encode([
            'ok' => true,
            'has_periode_aktif' => false,
            'periode_terpilih' => null,
            'all_periode' => [],
            'data' => []
        ]);
        exit;
    }
    
    $periodeId = (int)$periode['id'];

    // Cek apakah ada periode yang aktif di sistem secara umum (dibuka atau persiapan)
    $stmtCheckAktif = $pdo->query("SELECT COUNT(*) FROM periode WHERE status IN ('dibuka', 'persiapan')");
    $hasPeriodeAktif = ((int)$stmtCheckAktif->fetchColumn()) > 0;
    
    $query = "
        SELECT 
            p.id,
            m.nim,
            p.nama_snapshot AS nama,
            p.no_hp,
            m.email,
            j.nama_jurusan,
            m.angkatan,
            p.program,
            p.ipk,
            p.jumlah_sks,
            p.jenis_kelamin,
            p.alamat AS alamat_domisili,
            p.rt,
            p.rw,
            p.kelurahan,
            p.kecamatan,
            p.kota_kabupaten AS kota,
            p.provinsi,
            p.latitude,
            p.longitude,
            ROUND(
                6371 * ACOS(
                    LEAST(1.0, GREATEST(-1.0,
                        COS(RADIANS(IFNULL(p.latitude, 0))) * COS(RADIANS(IFNULL(ep.latitude, 0))) *
                        COS(RADIANS(IFNULL(ep.longitude, 0)) - RADIANS(IFNULL(p.longitude, 0))) +
                        SIN(RADIANS(IFNULL(p.latitude, 0))) * SIN(RADIANS(IFNULL(ep.latitude, 0)))
                    ))
                ), 2
            ) AS jarak_km,
            p.status AS status_penetapan,
            p.submitted_at AS created_at,
            ep.nama AS unit_nama,
            (
                SELECT GROUP_CONCAT(mp.nama SEPARATOR ', ')
                FROM pendaftaran_peminatan pp
                JOIN peminatan mp ON pp.peminatan_id = mp.id
                WHERE pp.pendaftaran_id = p.id
            ) AS peminatan_list
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        WHERE p.periode_id = :periode_id
        ORDER BY p.submitted_at DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':periode_id', $periodeId, PDO::PARAM_INT);
    $stmt->execute();
    
    $pendaftar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'has_periode_aktif' => $hasPeriodeAktif,
        'periode_terpilih' => $periode,
        'all_periode' => $allPeriode,
        'data' => $pendaftar
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

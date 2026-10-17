<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
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

    // Detail Prodi
    $stmtProdi = $pdo->prepare("SELECT id, kode, jenjang, nama_jurusan FROM jurusan WHERE id = ?");
    $stmtProdi->execute([$jurusanId]);
    $prodi = $stmtProdi->fetch(PDO::FETCH_ASSOC);

    // Ambil daftar semua periode untuk selector
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
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    $periodeId = $periode ? (int)$periode['id'] : 0;

    // 1. Peminatan yang terdaftar / terkait dengan prodi ini (via pivot peminatan_jurusan)
    $stmtPem = $pdo->prepare("
        SELECT 
            p.id,
            p.nama,
            p.deskripsi,
            p.aktif,
            COUNT(DISTINCT pd.id) AS total_pelamar_periode
        FROM peminatan p
        JOIN peminatan_jurusan pj ON p.id = pj.peminatan_id AND pj.jurusan_id = :jid
        LEFT JOIN pendaftaran_peminatan pp ON p.id = pp.peminatan_id
        LEFT JOIN pendaftaran pd ON pp.pendaftaran_id = pd.id AND pd.periode_id = :pid
        LEFT JOIN mahasiswa m ON pd.mahasiswa_id = m.id AND m.jurusan_id = :jid2
        GROUP BY p.id, p.nama, p.deskripsi, p.aktif
        ORDER BY p.nama ASC
    ");
    $stmtPem->execute([
        ':jid'  => $jurusanId,
        ':pid'  => $periodeId,
        ':jid2' => $jurusanId
    ]);
    $peminatanList = $stmtPem->fetchAll(PDO::FETCH_ASSOC);

    // 2. Formasi Unit PLN yang membuka kuota untuk prodi ini pada periode aktif
    $stmtUnit = $pdo->prepare("
        SELECT 
            ep.id AS unit_id,
            ep.nama AS unit_nama,
            ep.singkatan,
            ep.alamat,
            upp.id AS unit_pelaksana_periode_id,
            upp.kuota_total AS kuota_unit_total,
            upp.tipe_kuota,
            upj.kuota_total AS kuota_prodi,
            upj.kuota_tersisa AS kuota_prodi_tersisa,
            COUNT(DISTINCT p.id) AS pelamar_dari_prodi,
            SUM(CASE WHEN p.status = 'diterima' THEN 1 ELSE 0 END) AS diterima_dari_prodi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        LEFT JOIN unit_periode_jurusan upj ON upp.id = upj.unit_pelaksana_periode_id AND upj.jurusan_id = :jid
        LEFT JOIN pendaftaran p ON upp.id = p.unit_pelaksana_periode_id 
                                AND p.periode_id = :pid 
                                AND p.mahasiswa_id IN (SELECT id FROM mahasiswa WHERE jurusan_id = :jid2)
        WHERE upp.periode_id = :pid2
          AND (
            upj.jurusan_id IS NOT NULL 
            OR upp.tipe_kuota = 'keseluruhan'
          )
        GROUP BY ep.id, ep.nama, ep.singkatan, ep.alamat, upp.id, upp.kuota_total, upp.tipe_kuota, upj.kuota_total, upj.kuota_tersisa
        ORDER BY pelamar_dari_prodi DESC, ep.nama ASC
    ");
    $stmtUnit->execute([
        ':jid'  => $jurusanId,
        ':pid'  => $periodeId,
        ':jid2' => $jurusanId,
        ':pid2' => $periodeId
    ]);
    $formasiUnit = $stmtUnit->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok'               => true,
        'prodi'            => $prodi,
        'periode_terpilih' => $periode,
        'all_periode'      => $allPeriode,
        'peminatan_list'   => $peminatanList,
        'formasi_unit'     => $formasiUnit
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat data peminatan dan formasi unit.')]);
}

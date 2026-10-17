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

    $pendaftaranId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($pendaftaranId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter id pendaftaran wajib disertakan.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.periode_id,
            p.mahasiswa_id,
            p.unit_pelaksana_periode_id,
            p.nama_snapshot AS nama,
            p.no_hp,
            p.jenis_kelamin,
            p.alamat,
            p.rt,
            p.rw,
            p.kelurahan,
            p.kecamatan,
            p.kota_kabupaten AS kota,
            p.provinsi,
            p.latitude,
            p.longitude,
            p.ipk,
            p.jumlah_sks,
            CASE p.program
                WHEN '1_bulan' THEN '1 Bulan'
                WHEN '3_bulan' THEN '3 Bulan'
                WHEN '4_bulan' THEN '4 Bulan'
                WHEN '5_bulan' THEN '5 Bulan'
                WHEN '6_bulan' THEN '6 Bulan'
                ELSE IFNULL(NULLIF(p.program, ''), '-')
            END AS program,
            p.cv_path,
            p.transkrip_path,
            p.porto_path,
            p.status,
            p.catatan_admin,
            p.submitted_at,
            p.updated_at,
            m.nim,
            m.email,
            m.angkatan,
            m.jurusan_id,
            j.nama_jurusan,
            j.kode AS kode_jurusan,
            j.jenjang AS jenjang_jurusan,
            ep.id AS unit_id,
            ep.nama AS unit_nama,
            ep.singkatan AS unit_singkatan,
            ep.alamat AS unit_alamat,
            pr.nama AS periode_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        JOIN periode pr ON p.periode_id = pr.id
        WHERE p.id = :id AND m.jurusan_id = :jurusan_id
        LIMIT 1
    ");

    $stmt->execute([
        ':id'         => $pendaftaranId,
        ':jurusan_id' => $jurusanId
    ]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        http_response_code(404);
        echo json_encode(['error' => 'Data pendaftar tidak ditemukan atau bukan merupakan mahasiswa dari Program Studi Anda.']);
        exit;
    }

    // Ambil daftar peminatan yang dipilih
    $stmtPem = $pdo->prepare("
        SELECT pem.id, pem.nama, pem.deskripsi
        FROM pendaftaran_peminatan pp
        JOIN peminatan pem ON pp.peminatan_id = pem.id
        WHERE pp.pendaftaran_id = ?
        ORDER BY pem.nama ASC
    ");
    $stmtPem->execute([$pendaftaranId]);
    $peminatan = $stmtPem->fetchAll(PDO::FETCH_ASSOC);
    $data['peminatan_list'] = $peminatan;

    echo json_encode([
        'ok'   => true,
        'data' => $data
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat detail pendaftar.')]);
}

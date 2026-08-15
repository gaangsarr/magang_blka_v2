<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();

try {
    $mahasiswaId = Auth::getMahasiswaId();
    $pdo = Database::getInstance();
    
    // 1. Cek apakah ada periode yang sedang DIBUKA saat ini
    $stmtActive = $pdo->query("SELECT id, nama, status, pengumuman_dibuka, angkatan_eligible FROM periode WHERE status = 'dibuka' LIMIT 1");
    $activePeriode = $stmtActive->fetch(PDO::FETCH_ASSOC) ?: null;

    // 2. Ambil seluruh riwayat pendaftaran mahasiswa
    $stmtAllReg = $pdo->prepare("
        SELECT 
            p.id AS pendaftaran_id,
            p.status, 
            p.submitted_at, 
            p.program,
            p.nama_snapshot AS nama,
            m.nim,
            m.jurusan_id,
            j.nama_jurusan AS jurusan_nama,
            p.is_dipindahkan,

            p.catatan_admin,
            upp.kuota_tersisa,
            e.nama AS nama_unit,
            e_asal.nama AS nama_unit_asal,
            pr.id AS periode_id,
            pr.nama AS nama_periode,
            pr.status AS status_periode,
            pr.pengumuman_dibuka
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN periode pr ON p.periode_id = pr.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        WHERE p.mahasiswa_id = :mid
        ORDER BY p.id DESC
    ");
    $stmtAllReg->execute([':mid' => $mahasiswaId]);
    $rawList = $stmtAllReg->fetchAll(PDO::FETCH_ASSOC);

    $isRegisteredInActive = false;
    $processedList = [];

    foreach ($rawList as $row) {
        $isPengumumanBuka = (bool)($row['pengumuman_dibuka'] ?? false);
        $isThisPeriodActive = ($activePeriode && (int)$row['periode_id'] === (int)$activePeriode['id']);
        
        if ($isThisPeriodActive) {
            $isRegisteredInActive = true;
        }

        $displayStatus = $row['status'];
        if (!$isPengumumanBuka) {
            $displayStatus = 'menunggu_pengumuman';
        }

        $processedList[] = [
            'pendaftaran_id'    => (int)$row['pendaftaran_id'],
            'periode_id'        => (int)$row['periode_id'],
            'periode_nama'      => $row['nama_periode'],
            'periode_status'    => $row['status_periode'],
            'is_periode_aktif'  => $isThisPeriodActive,
            'pengumuman_dibuka' => $isPengumumanBuka,
            'status'            => $displayStatus,
            'raw_status'        => $row['status'],
            'program'           => $row['program'],
            'nama'              => $row['nama'],
            'nim'               => $row['nim'],
            'jurusan'           => $row['jurusan_nama'] ?? '-',
            'nama_unit'         => $isPengumumanBuka ? $row['nama_unit'] : ($row['nama_unit_asal'] ?: $row['nama_unit']),
            'nama_unit_asal'    => $isPengumumanBuka ? $row['nama_unit_asal'] : null,
            'is_dipindahkan'    => $isPengumumanBuka ? (bool)$row['is_dipindahkan'] : false,
            'catatan_admin'     => $isPengumumanBuka ? $row['catatan_admin'] : null,
            'submitted_at'      => $row['submitted_at']
        ];
    }

    echo json_encode([
        'ok'                           => true,
        'has_registrations'            => count($processedList) > 0,
        'terdaftar'                    => count($processedList) > 0,
        'total'                        => count($processedList),
        'ada_periode_dibuka'           => (bool)$activePeriode,
        'sudah_mendaftar_periode_aktif'=> $isRegisteredInActive,
        'periode_aktif_saat_ini'       => $activePeriode ? [
            'id'   => (int)$activePeriode['id'],
            'nama' => $activePeriode['nama'],
        ] : null,
        'data'                         => $processedList
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat riwayat pendaftaran.')]);
}

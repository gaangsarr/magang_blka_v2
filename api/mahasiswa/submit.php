<?php
declare(strict_types=1);
ob_start();
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\UserException;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();
Auth::requireCsrfApi();
Auth::rateLimit('submit', 5, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Validasi input minimal
$required = ['periode_id', 'reservasi_id', 'upp_id', 'program', 'nama', 'jenis_kelamin', 'ipk', 'jumlah_sks', 'no_hp', 'lat', 'lng', 'rt', 'rw', 'kelurahan', 'kecamatan', 'kota_kabupaten', 'provinsi', 'alamat_lengkap'];
foreach ($required as $req) {
    if (!isset($body[$req]) || $body[$req] === '') {
        http_response_code(400);
        echo json_encode(['error' => "Parameter $req wajib diisi."]);
        exit;
    }
}

$mahasiswaId = Auth::getMahasiswaId();
$periodeId = (int)$body['periode_id'];
$reservasiId = (int)$body['reservasi_id'];
$uppId = (int)$body['upp_id'];
$peminatanIds = isset($body['peminatan']) && is_array($body['peminatan']) ? array_map('intval', $body['peminatan']) : [];

$pdoEarly = Database::getInstance();
$stmtPCheck = $pdoEarly->prepare("SELECT angkatan_eligible, nama, status, tanggal_selesai, jam_selesai, syarat_transkrip, syarat_cv, syarat_porto FROM periode WHERE id = :pid");
$stmtPCheck->execute([':pid' => $periodeId]);
$periodeDataEarly = $stmtPCheck->fetch(PDO::FETCH_ASSOC);

if (!$periodeDataEarly || $periodeDataEarly['status'] !== 'dibuka') {
    http_response_code(400);
    echo json_encode(['error' => 'Periode magang ini tidak sedang dibuka.']);
    exit;
}

if (\App\PeriodeHelper::isPeriodeExpired($periodeDataEarly)) {
    \App\PeriodeHelper::closeExpiredPeriodes($pdoEarly);
    http_response_code(400);
    echo json_encode(['error' => 'Periode pendaftaran magang telah ditutup.']);
    exit;
}

Auth::startSession(startPHP: true);
$mhsAuth = Auth::getMahasiswa();
$mhsNim = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($mhsAuth['nim'] ?? ''));

// Helper validasi tautan web / path berkas
$validateDokumen = function (?string $rawVal, string $docType, string $docLabel, string $mhsNim, bool $isMandatory) use ($root): ?string {
    $val = trim((string)$rawVal);
    if (empty($val)) {
        if ($isMandatory) {
            http_response_code(400);
            echo json_encode(['error' => "Tautan berkas {$docLabel} wajib diisi sebelum mengirim pendaftaran."]);
            exit;
        }
        return null;
    }

    // A. Format URL Web (Google Drive, OneDrive, Dropbox, dll)
    if (str_starts_with($val, 'http://') || str_starts_with($val, 'https://')) {
        if (!filter_var($val, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => "Format tautan {$docLabel} tidak valid. Pastikan diawali dengan https://"]);
            exit;
        }
        return $val;
    }

    // B. Format Berkas Lokal Lama (Backward Compatibility)
    if (!str_starts_with($val, $docType . '/') || str_contains($val, '..')) {
        http_response_code(400);
        echo json_encode(['error' => "Format path {$docLabel} tidak valid."]);
        exit;
    }
    if (!str_ends_with($val, '/' . $mhsNim . '.pdf') && !str_ends_with($val, $mhsNim . '.pdf')) {
        http_response_code(400);
        echo json_encode(['error' => "Dokumen {$docLabel} tidak cocok dengan NIM Anda. Silakan periksa kembali."]);
        exit;
    }
    $fullPath = $root . '/storage/' . $val;
    if (!file_exists($fullPath) || !is_readable($fullPath)) {
        http_response_code(400);
        echo json_encode(['error' => "Berkas {$docLabel} tidak ditemukan di server. Silakan masukkan tautan berkas Anda."]);
        exit;
    }

    return $val;
};

// 1. Validasi Transkrip Nilai
$rawTranskrip = $body['transkrip_link'] ?? $body['transkrip_path'] ?? $_SESSION['transkrip_temp_path'] ?? '';
$transkripPath = $validateDokumen($rawTranskrip, 'transkrip', 'Transkrip Nilai', $mhsNim, !empty($periodeDataEarly['syarat_transkrip']));

// 2. Validasi CV
$rawCv = $body['cv_link'] ?? $body['cv_path'] ?? $_SESSION['cv_temp_path'] ?? '';
$cvPath = $validateDokumen($rawCv, 'cv', 'Curriculum Vitae (CV)', $mhsNim, !empty($periodeDataEarly['syarat_cv']));

// 3. Validasi Portofolio
$rawPorto = $body['porto_link'] ?? $body['porto_path'] ?? $_SESSION['porto_temp_path'] ?? '';
$portoPath = $validateDokumen($rawPorto, 'porto', 'Portofolio', $mhsNim, !empty($periodeDataEarly['syarat_porto']));


// Validasi & Sanitasi Ketat Nomor Handphone / WhatsApp (hanya angka, diawali 08/628, 10-14 digit)
$rawNoHp = trim((string)($body['no_hp'] ?? ''));
$cleanHp = preg_replace('/[^\d]/', '', $rawNoHp);

if (str_starts_with($cleanHp, '628')) {
    $cleanHp = '08' . substr($cleanHp, 3);
}

if (!preg_match('/^08[0-9]{8,12}$/', $cleanHp)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nomor Handphone / WhatsApp tidak valid. Harus berupa angka 10-14 digit dan diawali dengan 08 (contoh: 081234567890).']);
    exit;
}
$body['no_hp'] = $cleanHp;

// Validasi & Sanitasi Ketat RT & RW (hanya angka 1-5 digit)
$cleanRt = preg_replace('/[^\d]/', '', trim((string)($body['rt'] ?? '')));
$cleanRw = preg_replace('/[^\d]/', '', trim((string)($body['rw'] ?? '')));

if ($cleanRt === '' || !preg_match('/^[0-9]{1,5}$/', $cleanRt)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nomor RT tidak valid. Harus berupa angka (contoh: 01 atau 005).']);
    exit;
}

if ($cleanRw === '' || !preg_match('/^[0-9]{1,5}$/', $cleanRw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nomor RW tidak valid. Harus berupa angka (contoh: 01 atau 005).']);
    exit;
}

$body['rt'] = $cleanRt;
$body['rw'] = $cleanRw;

// Validasi Batasan Umum IPK (0.00 - 4.00) dan SKS (0 - 150)
$userIpk = (float)$body['ipk'];
if ($userIpk < 0.0 || $userIpk > 4.00) {
    http_response_code(400);
    echo json_encode(['error' => 'Nilai IPK tidak valid. Harus berada di antara 0.00 dan 4.00.']);
    exit;
}

$userSks = (int)$body['jumlah_sks'];
if ($userSks < 0 || $userSks > 150) {
    http_response_code(400);
    echo json_encode(['error' => 'Jumlah SKS yang sudah ditempuh tidak boleh bernilai negatif dan tidak boleh lebih dari 150 SKS.']);
    exit;
}

// Validasi Syarat Program 5 Bulan jika dipilih
if ($body['program'] === '5_bulan') {
    $pdo = Database::getInstance();
    $stmtSet = $pdo->query("SELECT kunci, nilai FROM pengaturan WHERE kunci IN ('min_ipk_5bulan', 'min_sks_5bulan')");
    $settings = $stmtSet->fetchAll(PDO::FETCH_KEY_PAIR);

    $minIpk = isset($settings['min_ipk_5bulan']) ? (float)$settings['min_ipk_5bulan'] : 3.00;
    $minSks = isset($settings['min_sks_5bulan']) ? (int)$settings['min_sks_5bulan'] : 110;

    $userIpk = (float)$body['ipk'];
    $userSks = (int)$body['jumlah_sks'];

    if ($userIpk < $minIpk) {
        http_response_code(400);
        echo json_encode(['error' => "Program Magang 5 Bulan mensyaratkan IPK minimal " . number_format($minIpk, 2) . "."]);
        exit;
    }
    if ($userSks < $minSks) {
        http_response_code(400);
        echo json_encode(['error' => "Program Magang 5 Bulan mensyaratkan minimal {$minSks} SKS yang sudah ditempuh."]);
        exit;
    }
}

$pendaftaranId = 0;
try {
    Database::transaction(function (PDO $pdo) use ($mahasiswaId, $periodeId, $reservasiId, $uppId, $body, $peminatanIds, $transkripPath, $cvPath, $portoPath, &$pendaftaranId) {
        // 1. Validasi periode dibuka dan angkatan eligible
        $stmtP = $pdo->prepare("SELECT angkatan_eligible, nama, status, tanggal_selesai, jam_selesai FROM periode WHERE id = :pid");
        $stmtP->execute([':pid' => $periodeId]);
        $periodeData = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$periodeData || $periodeData['status'] !== 'dibuka') {
            throw new UserException("Periode magang ini tidak sedang dibuka.");
        }
        if (\App\PeriodeHelper::isPeriodeExpired($periodeData)) {
            \App\PeriodeHelper::closeExpiredPeriodes($pdo);
            throw new UserException("Periode pendaftaran magang telah ditutup.");
        }
        if (!empty($periodeData['angkatan_eligible'])) {
            $mhsData = Auth::getMahasiswa();
            $mhsAngkatan = (int)($mhsData['angkatan'] ?? 0);
            $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;
            $eligibleList = array_map('trim', explode(',', $periodeData['angkatan_eligible']));
            if (!in_array((string)$fullAngkatan, $eligibleList, true) && !in_array((string)$mhsAngkatan, $eligibleList, true)) {
                throw new UserException("Mohon maaf, pendaftaran periode {$periodeData['nama']} dikhususkan untuk Angkatan " . implode(', ', $eligibleList) . ".");
            }
        }

        // 1b. Validasi status pendaftaran sebelumnya pada periode ini
        $stmtCek = $pdo->prepare("SELECT id, status, unit_pelaksana_periode_id, catatan_admin, submitted_at FROM pendaftaran WHERE mahasiswa_id = :mid AND periode_id = :pid FOR UPDATE");
        $stmtCek->execute([':mid' => $mahasiswaId, ':pid' => $periodeId]);
        $existingPendaftaran = $stmtCek->fetch(PDO::FETCH_ASSOC);

        $isReRegistration = false;
        if ($existingPendaftaran) {
            if ($existingPendaftaran['status'] !== 'ditolak') {
                throw new UserException("Anda sudah terdaftar pada periode ini.");
            }
            // Mahasiswa berstatus ditolak tidak boleh mendaftar kembali ke unit yang sama yang menolaknya
            if ((int)$existingPendaftaran['unit_pelaksana_periode_id'] === $uppId) {
                throw new UserException("Anda telah ditolak dari unit ini. Silakan pilih unit pelaksana lain yang tersedia.");
            }
            $isReRegistration = true;
        }

        // Cek juga dari histori penolakan pada periode ini
        $stmtHistori = $pdo->prepare("SELECT 1 FROM pendaftaran_histori_penolakan WHERE mahasiswa_id = :mid AND periode_id = :pid AND unit_pelaksana_periode_id = :upp_id LIMIT 1");
        $stmtHistori->execute([':mid' => $mahasiswaId, ':pid' => $periodeId, ':upp_id' => $uppId]);
        if ($stmtHistori->fetch()) {
            throw new UserException("Anda telah ditolak dari unit ini. Silakan pilih unit pelaksana lain yang tersedia.");
        }

        // 2. Validasi reservasi
        $stmtRes = $pdo->prepare("SELECT status, expired_at FROM reservasi WHERE id = :id AND mahasiswa_id = :mid FOR UPDATE");
        $stmtRes->execute([':id' => $reservasiId, ':mid' => $mahasiswaId]);
        $res = $stmtRes->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            throw new UserException("Data reservasi tidak ditemukan.");
        }
        if ($res['status'] !== 'ditahan') {
            throw new UserException("Status reservasi tidak valid (sudah {$res['status']}).");
        }
        if (strtotime($res['expired_at']) < time()) {
            throw new UserException("Waktu reservasi Anda telah habis. Silakan pilih unit pelaksana kembali.");
        }

        if ($isReRegistration) {
            $pendaftaranId = (int)$existingPendaftaran['id'];

            // 3a. Arsipkan data penolakan lama ke pendaftaran_histori_penolakan
            $stmtLogHistori = $pdo->prepare("
                INSERT INTO pendaftaran_histori_penolakan (
                    pendaftaran_id, mahasiswa_id, periode_id, unit_pelaksana_periode_id, catatan_admin, ditolak_pada, created_at
                ) VALUES (
                    :p_id, :mid, :pid, :old_upp_id, :catatan, :ditolak_pada, NOW()
                )
            ");
            $stmtLogHistori->execute([
                ':p_id'         => $pendaftaranId,
                ':mid'          => $mahasiswaId,
                ':pid'          => $periodeId,
                ':old_upp_id'   => (int)$existingPendaftaran['unit_pelaksana_periode_id'],
                ':catatan'      => $existingPendaftaran['catatan_admin'],
                ':ditolak_pada' => $existingPendaftaran['submitted_at'] ?? date('Y-m-d H:i:s'),
            ]);

            // 3b. Update baris pendaftaran eksisting dengan unit baru, reset status ke 'diajukan'
            $stmtUpdate = $pdo->prepare("
                UPDATE pendaftaran SET
                    reservasi_id = :rid,
                    unit_pelaksana_periode_id = :upp_id,
                    program = :prog,
                    nama_snapshot = :nama,
                    jenis_kelamin = :jk,
                    ipk = :ipk,
                    jumlah_sks = :sks,
                    no_hp = :hp,
                    alamat = :almt,
                    rt = :rt,
                    rw = :rw,
                    kelurahan = :kel,
                    kecamatan = :kec,
                    kota_kabupaten = :kota,
                    provinsi = :prov,
                    latitude = :lat,
                    longitude = :lng,
                    transkrip_path = :transkrip_path,
                    transkrip_uploaded_at = :transkrip_uploaded_at,
                    cv_path = :cv_path,
                    cv_uploaded_at = :cv_uploaded_at,
                    porto_path = :porto_path,
                    porto_uploaded_at = :porto_uploaded_at,
                    status = 'diajukan',
                    catatan_admin = NULL,
                    is_dipindahkan = 0,
                    unit_pelaksana_periode_asal_id = NULL,
                    submitted_at = NOW()
                WHERE id = :id
            ");

            $stmtUpdate->execute([
                ':id'                    => $pendaftaranId,
                ':rid'                   => $reservasiId,
                ':upp_id'                => $uppId,
                ':prog'                  => $body['program'],
                ':nama'                  => $body['nama'],
                ':jk'                    => $body['jenis_kelamin'],
                ':ipk'                   => (float)$body['ipk'],
                ':sks'                   => (int)$body['jumlah_sks'],
                ':hp'                    => $body['no_hp'],
                ':almt'                  => $body['alamat_lengkap'],
                ':rt'                    => $body['rt'],
                ':rw'                    => $body['rw'],
                ':kel'                   => $body['kelurahan'],
                ':kec'                   => $body['kecamatan'],
                ':kota'                  => $body['kota_kabupaten'],
                ':prov'                  => $body['provinsi'],
                ':lat'                   => (float)$body['lat'],
                ':lng'                   => (float)$body['lng'],
                ':transkrip_path'        => $transkripPath,
                ':transkrip_uploaded_at' => $transkripPath ? date('Y-m-d H:i:s') : null,
                ':cv_path'               => $cvPath,
                ':cv_uploaded_at'        => $cvPath ? date('Y-m-d H:i:s') : null,
                ':porto_path'            => $portoPath,
                ':porto_uploaded_at'     => $portoPath ? date('Y-m-d H:i:s') : null,
            ]);

            // Bersihkan peminatan lama
            $pdo->prepare("DELETE FROM pendaftaran_peminatan WHERE pendaftaran_id = ?")->execute([$pendaftaranId]);
        } else {
            // 3. Insert Pendaftaran Baru
            $stmtInsert = $pdo->prepare("
                INSERT INTO pendaftaran (
                    mahasiswa_id, periode_id, reservasi_id, unit_pelaksana_periode_id, 
                    program, nama_snapshot, jenis_kelamin, ipk, jumlah_sks, no_hp, 
                    alamat, rt, rw, kelurahan, kecamatan, kota_kabupaten, provinsi, latitude, longitude,
                    transkrip_path, transkrip_uploaded_at,
                    cv_path, cv_uploaded_at,
                    porto_path, porto_uploaded_at
                ) VALUES (
                    :mid, :pid, :rid, :upp_id,
                    :prog, :nama, :jk, :ipk, :sks, :hp,
                    :almt, :rt, :rw, :kel, :kec, :kota, :prov, :lat, :lng,
                    :transkrip_path, :transkrip_uploaded_at,
                    :cv_path, :cv_uploaded_at,
                    :porto_path, :porto_uploaded_at
                )
            ");
            
            $stmtInsert->execute([
                ':mid'                   => $mahasiswaId,
                ':pid'                   => $periodeId,
                ':rid'                   => $reservasiId,
                ':upp_id'                => $uppId,
                ':prog'                  => $body['program'],
                ':nama'                  => $body['nama'],
                ':jk'                    => $body['jenis_kelamin'],
                ':ipk'                   => (float)$body['ipk'],
                ':sks'                   => (int)$body['jumlah_sks'],
                ':hp'                    => $body['no_hp'],
                ':almt'                  => $body['alamat_lengkap'],
                ':rt'                    => $body['rt'],
                ':rw'                    => $body['rw'],
                ':kel'                   => $body['kelurahan'],
                ':kec'                   => $body['kecamatan'],
                ':kota'                  => $body['kota_kabupaten'],
                ':prov'                  => $body['provinsi'],
                ':lat'                   => (float)$body['lat'],
                ':lng'                   => (float)$body['lng'],
                ':transkrip_path'        => $transkripPath,
                ':transkrip_uploaded_at' => $transkripPath ? date('Y-m-d H:i:s') : null,
                ':cv_path'               => $cvPath,
                ':cv_uploaded_at'        => $cvPath ? date('Y-m-d H:i:s') : null,
                ':porto_path'            => $portoPath,
                ':porto_uploaded_at'     => $portoPath ? date('Y-m-d H:i:s') : null,
            ]);
            
            $pendaftaranId = (int)$pdo->lastInsertId();
        }

        // 4. Update status reservasi
        $pdo->prepare("UPDATE reservasi SET status = 'dikonfirmasi' WHERE id = :id")
            ->execute([':id' => $reservasiId]);

        // 5. Insert Peminatan
        if (!empty($peminatanIds)) {
            // Batasi maksimal 3 peminatan
            $peminatanIds = array_slice($peminatanIds, 0, 3);
            $stmtPem = $pdo->prepare("INSERT IGNORE INTO pendaftaran_peminatan (pendaftaran_id, peminatan_id) VALUES (?, ?)");
            foreach ($peminatanIds as $pid) {
                $stmtPem->execute([$pendaftaranId, $pid]);
            }
        }
    });

    // Bersihkan session temporary transkrip, cv, dan porto setelah berhasil submit
    unset(
        $_SESSION['transkrip_temp_path'], $_SESSION['transkrip_periode_id'],
        $_SESSION['cv_temp_path'], $_SESSION['cv_periode_id'],
        $_SESSION['porto_temp_path'], $_SESSION['porto_periode_id']
    );

    // Masukkan bukti pendaftaran ke antrean email (asynchronous queue)
    if ($pendaftaranId > 0) {
        try {
            $pdo = Database::getInstance();
            \App\EmailQueue::pushBuktiPendaftaran($pdo, $pendaftaranId);
        } catch (\Throwable $e) {
            error_log('[Submit Mahasiswa] Gagal antrekan email bukti: ' . $e->getMessage());
        }
    }

    if (ob_get_length()) ob_clean();
    echo json_encode(['ok' => true, 'message' => 'Pendaftaran berhasil dikirim.']);
} catch (UserException $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat memproses pendaftaran.')]);
}

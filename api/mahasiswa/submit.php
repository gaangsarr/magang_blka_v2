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

try {
    Database::transaction(function (PDO $pdo) use ($mahasiswaId, $periodeId, $reservasiId, $uppId, $body, $peminatanIds) {
        // 1. Validasi periode dibuka dan angkatan eligible
        $stmtP = $pdo->prepare("SELECT angkatan_eligible, nama, status FROM periode WHERE id = :pid");
        $stmtP->execute([':pid' => $periodeId]);
        $periodeData = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$periodeData || $periodeData['status'] !== 'dibuka') {
            throw new \Exception("Periode magang ini tidak sedang dibuka.");
        }
        if (!empty($periodeData['angkatan_eligible'])) {
            $mhsData = Auth::getMahasiswa();
            $mhsAngkatan = (int)($mhsData['angkatan'] ?? 0);
            $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;
            $eligibleList = array_map('trim', explode(',', $periodeData['angkatan_eligible']));
            if (!in_array((string)$fullAngkatan, $eligibleList, true) && !in_array((string)$mhsAngkatan, $eligibleList, true)) {
                throw new \Exception("Mohon maaf, pendaftaran periode {$periodeData['nama']} dikhususkan untuk Angkatan " . implode(', ', $eligibleList) . ".");
            }
        }

        // 1b. Validasi belum pernah submit di periode ini
        $stmtCek = $pdo->prepare("SELECT id FROM pendaftaran WHERE mahasiswa_id = :mid AND periode_id = :pid");
        $stmtCek->execute([':mid' => $mahasiswaId, ':pid' => $periodeId]);
        if ($stmtCek->fetch()) {
            throw new \Exception("Anda sudah terdaftar pada periode ini.");
        }


        // 2. Validasi reservasi
        $stmtRes = $pdo->prepare("SELECT status, expired_at FROM reservasi WHERE id = :id AND mahasiswa_id = :mid FOR UPDATE");
        $stmtRes->execute([':id' => $reservasiId, ':mid' => $mahasiswaId]);
        $res = $stmtRes->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            throw new \Exception("Data reservasi tidak ditemukan.");
        }
        if ($res['status'] !== 'ditahan') {
            throw new \Exception("Status reservasi tidak valid (sudah {$res['status']}).");
        }
        if (strtotime($res['expired_at']) < time()) {
            throw new \Exception("Waktu reservasi Anda telah habis. Silakan pilih unit pelaksana kembali.");
        }

        // 3. Insert Pendaftaran
        $stmtInsert = $pdo->prepare("
            INSERT INTO pendaftaran (
                mahasiswa_id, periode_id, reservasi_id, unit_pelaksana_periode_id, 
                program, nama_snapshot, jenis_kelamin, ipk, jumlah_sks, no_hp, 
                alamat, rt, rw, kelurahan, kecamatan, kota_kabupaten, provinsi, latitude, longitude
            ) VALUES (
                :mid, :pid, :rid, :upp_id,
                :prog, :nama, :jk, :ipk, :sks, :hp,
                :almt, :rt, :rw, :kel, :kec, :kota, :prov, :lat, :lng
            )
        ");
        
        $stmtInsert->execute([
            ':mid' => $mahasiswaId,
            ':pid' => $periodeId,
            ':rid' => $reservasiId,
            ':upp_id' => $uppId,
            ':prog' => $body['program'],
            ':nama' => $body['nama'],
            ':jk' => $body['jenis_kelamin'],
            ':ipk' => (float)$body['ipk'],
            ':sks' => (int)$body['jumlah_sks'],
            ':hp' => $body['no_hp'],
            ':almt' => $body['alamat_lengkap'],
            ':rt' => $body['rt'],
            ':rw' => $body['rw'],
            ':kel' => $body['kelurahan'],
            ':kec' => $body['kecamatan'],
            ':kota' => $body['kota_kabupaten'],
            ':prov' => $body['provinsi'],
            ':lat' => (float)$body['lat'],
            ':lng' => (float)$body['lng']
        ]);
        
        $pendaftaranId = $pdo->lastInsertId();

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

    echo json_encode(['ok' => true, 'message' => 'Pendaftaran berhasil dikirim.']);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

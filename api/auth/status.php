<?php
/**
 * api/auth/status.php
 *
 * Endpoint: GET /api/auth/status.php
 *
 * Digunakan oleh auth.js di login.html untuk mengecek apakah
 * sudah ada session aktif sebelum menampilkan tombol login.
 *
 * Response:
 *   { "authenticated": true,  "role": "mahasiswa", "sudah_mendaftar": true/false, "is_eligible_angkatan": true/false }
 *   { "authenticated": false }
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;
use App\Database;

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::startSession(startPHP: true);
$csrfToken = Auth::generateCsrfToken();

if (Auth::isLoggedInMahasiswa()) {
    $mahasiswa = Auth::getMahasiswa();
    $mid = Auth::getMahasiswaId();
    $pdo = Database::getInstance();

    // 1. Cek apakah sudah mendaftar di periode dibuka
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pendaftaran p
         JOIN periode pr ON p.periode_id = pr.id
         WHERE p.mahasiswa_id = :mid AND pr.status = 'dibuka'"
    );
    $stmt->execute([':mid' => $mid]);
    $sudahMendaftar = (int) $stmt->fetchColumn() > 0;

    // 1b. Cek apakah pernah mendaftar di periode manapun (riwayat)
    $stmtRiwayat = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE mahasiswa_id = :mid");
    $stmtRiwayat->execute([':mid' => $mid]);
    $hasRiwayat = (int) $stmtRiwayat->fetchColumn() > 0;

    // 2. Cek kelayakan angkatan (eligible cohorts) & status periode dibuka
    $stmtP = $pdo->query("SELECT id, nama, angkatan_eligible FROM periode WHERE status = 'dibuka' LIMIT 1");
    $activePeriode = $stmtP->fetch(PDO::FETCH_ASSOC) ?: null;

    $isEligibleAngkatan = true;
    $angkatanEligibleText = null;

    $mhsAngkatan = (int)($mahasiswa['angkatan'] ?? 0);
    $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;

    if ($activePeriode && !empty($activePeriode['angkatan_eligible'])) {
        $eligibleList = array_map('trim', explode(',', $activePeriode['angkatan_eligible']));
        $angkatanEligibleText = implode(', ', $eligibleList);

        $isEligibleAngkatan = in_array((string)$fullAngkatan, $eligibleList, true) || in_array((string)$mhsAngkatan, $eligibleList, true);
    }

    // 3. Cek reservasi aktif yang masih ditahan
    $stmtActiveRes = $pdo->prepare(
        "SELECT r.id AS reservasi_id, r.unit_pelaksana_periode_id AS upp_id, r.expired_at, e.nama AS nama_unit
         FROM reservasi r
         JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id
         JOIN entitas_perusahaan e ON upp.entitas_id = e.id
         WHERE r.mahasiswa_id = :mid AND r.status = 'ditahan' AND r.expired_at > NOW()
         ORDER BY r.id DESC LIMIT 1"
    );
    $stmtActiveRes->execute([':mid' => $mid]);
    $activeReservasi = $stmtActiveRes->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode([
        'authenticated'          => true,
        'role'                   => 'mahasiswa',
        'ada_periode_dibuka'     => (bool)$activePeriode,
        'periode_aktif'          => $activePeriode ? [
            'id'   => (int)$activePeriode['id'],
            'nama' => $activePeriode['nama'],
        ] : null,
        'sudah_mendaftar'        => $sudahMendaftar,
        'has_riwayat_pendaftaran'=> $hasRiwayat,
        'is_eligible_pendaftaran_aktif' => $isEligibleAngkatan,
        'is_eligible_angkatan'   => $isEligibleAngkatan,
        'angkatan_eligible'      => $angkatanEligibleText,
        'mhs_angkatan'           => $fullAngkatan,

        'active_reservasi'       => $activeReservasi,
        'csrf_token'             => $csrfToken,
        'user'                   => [
            'nama'     => $mahasiswa['nama'] ?? '-',
            'nim'      => $mahasiswa['nim'] ?? '-',
            'email'    => $mahasiswa['email'] ?? '-',
            'jurusan'  => $mahasiswa['jurusan_nama'] ?? '-',
            'angkatan' => $fullAngkatan,
        ]
    ]);
    exit;

}

if (Auth::isLoggedInAdmin()) {
    echo json_encode([
        'authenticated' => true,
        'role'          => 'admin',
        'csrf_token'    => $csrfToken,
    ]);
    exit;
}

http_response_code(401);
echo json_encode([
    'authenticated' => false,
    'csrf_token'    => $csrfToken
]);

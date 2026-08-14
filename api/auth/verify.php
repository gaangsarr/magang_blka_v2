<?php
declare(strict_types=1);

// Tangkap semua output (PHP warnings/notices) agar tidak merusak JSON response
ob_start();

// Sembunyikan error dari output — di-log oleh PHP, tidak dikirim ke browser
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Shutdown handler: tangkap fatal error yang tidak tertangkap → return JSON
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        if (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        echo json_encode([
            'error' => $debug
                ? '[Fatal] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']
                : 'Terjadi kesalahan sistem. Coba lagi.',
        ], JSON_UNESCAPED_UNICODE);
    }
});

/**
 * api/auth/verify.php
 *
 * Endpoint: POST /api/auth/verify.php
 * Content-Type: application/json
 * Body: { "id_token": "<Firebase ID Token>" }
 *
 * Alur:
 *  1. Validasi method & Content-Type
 *  2. Decode & verifikasi Firebase ID token (kreait/firebase-tokens)
 *  3. Cek domain @itpln.ac.id
 *  4. Ambil displayName dari token claims
 *  5. Parse NIM dari email prefix
 *  6. Lookup jurusan_id dari kode jurusan
 *  7. Upsert tabel mahasiswa
 *  8. Set PHP session
 *  9. Return JSON response
 *
 * Response sukses:
 *  { "ok": true, "needs_nama": false|true, "sudah_mendaftar": false|true }
 *
 * Response error:
 *  HTTP 4xx + { "error": "pesan error" }
 */



// ── Autoload & Bootstrap ──────────────────────────────────────────────────────
$root = dirname(__DIR__, 2); // /v1

require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use Kreait\Firebase\JWT\IdTokenVerifier;
use Kreait\Firebase\JWT\Error\IdTokenVerificationFailed;

// Load .env
$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

// ── Headers ───────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// ── Helper: JSON response dan exit ───────────────────────────────────────────
function respond(array $data, int $status = 200): never
{
    // Buang semua output yang ter-buffer (PHP warnings, notices, dll.)
    // agar tidak merusak JSON response
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Validasi HTTP Method ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method tidak diizinkan.'], 405);
}

// ── Baca Body JSON ────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body) || empty($body['id_token'])) {
    respond(['error' => 'Parameter id_token wajib diisi.'], 400);
}

$idTokenString = trim($body['id_token']);

if (!is_string($idTokenString) || strlen($idTokenString) < 100) {
    respond(['error' => 'Format token tidak valid.'], 400);
}

// ── Verifikasi Firebase ID Token ─────────────────────────────────────────────
$projectId = $_ENV['FIREBASE_PROJECT_ID'] ?? '';

if (empty($projectId)) {
    // Konfigurasi belum diisi — tolak dengan pesan yang jelas untuk developer
    respond(['error' => 'FIREBASE_PROJECT_ID belum dikonfigurasi di .env.'], 500);
}

try {
    $verifier = IdTokenVerifier::createWithProjectId($projectId);
    $token    = $verifier->verifyIdToken($idTokenString);
} catch (IdTokenVerificationFailed $e) {
    // Token signature tidak valid, expired, atau project ID salah
    respond(['error' => 'Token tidak valid atau sudah kadaluarsa. Coba login ulang.'], 401);
} catch (\Throwable $e) {
    // Error tak terduga (mis. network issue saat fetch public keys)
    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    $msg   = $debug ? 'Verifikasi token gagal: ' . $e->getMessage() : 'Verifikasi token gagal.';
    respond(['error' => $msg], 500);
}

// ── Ambil Claims dari Token ───────────────────────────────────────────────────
$claims = $token->payload();

$email       = $claims['email'] ?? '';
$displayName = $claims['name']  ?? '';
$firebaseUid = $claims['sub']   ?? '';

// Normalisasi
$email       = strtolower(trim($email));
$displayName = trim($displayName);

// ── Validasi Domain ───────────────────────────────────────────────────────────
// ── Validasi Domain ───────────────────────────────────────────────────────────
if (!str_ends_with($email, '@itpln.ac.id')) {
    respond([
        'error' => 'Hanya akun dengan email @itpln.ac.id yang diizinkan mendaftar.'
    ], 403);
}

$pdo = Database::getInstance();

// ── Cek Pintu Utama: Apakah Email Terdaftar di Tabel Admin? ─────────────────
$stmtAdmin = $pdo->prepare(
    "SELECT id, nama, role, aktif FROM admin WHERE email = :email AND aktif = 1 LIMIT 1"
);
$stmtAdmin->execute([':email' => $email]);
$adminAccount = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

if ($adminAccount) {
    // User adalah Admin (Dosen, Staf, atau Mahasiswa Admin)
    Auth::setAdminSession((int)$adminAccount['id'], $adminAccount['nama'] ?: ($displayName ?: 'Admin'), $adminAccount['role']);

    respond([
        'ok'           => true,
        'is_admin'     => true,
        'redirect_url' => '/admin/index.html',
        'nama'         => $adminAccount['nama'] ?: $displayName,
        'email'        => $email,
    ]);
}

// ── Pengguna Biasa: Parse NIM dari Email ─────────────────────────────────────
$nimData = Auth::parseNimFromEmail($email);

if ($nimData === null) {
    respond([
        'error' => sprintf(
            'Email "%s" terdeteksi sebagai akun Dosen/Staf ITPLN dan belum diberikan hak akses Admin. '
            . 'Jika kamu Mahasiswa, pastikan login dengan email mahasiswa (mis. nama231234@itpln.ac.id). '
            . 'Jika kamu Staf/Dosen, silakan hubungi Super Admin BLKA.',
            htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
        )
    ], 403);
}

$nim          = $nimData['nim'];
$angkatan     = $nimData['angkatan'];
$kodeJurusan  = $nimData['kode_jurusan'];
$noUrutAbsen  = $nimData['no_urut_absen'];

// ── Lookup Jurusan ────────────────────────────────────────────────────────────
$stmtJurusan = $pdo->prepare(
    'SELECT id, nama_jurusan FROM jurusan WHERE kode = :kode LIMIT 1'
);
$stmtJurusan->execute([':kode' => $kodeJurusan]);
$jurusan = $stmtJurusan->fetch(PDO::FETCH_ASSOC);

$jurusanId   = $jurusan ? (int) $jurusan['id'] : null;
$jurusanNama = $jurusan ? $jurusan['nama_jurusan'] : null;

// ── Upsert Tabel Mahasiswa ────────────────────────────────────────────────────
// Gunakan firebase_uid sebagai unique key untuk identifikasi akun.
// Jika mahasiswa sudah ada (login ulang), update last_login saja.
// Jika belum ada, insert dengan data yang tersedia.

$needsNama = empty($displayName);

try {
    $mahasiswaId = Database::transaction(function (PDO $pdo) use (
        $firebaseUid, $email, $nim, $displayName, $angkatan, $jurusanId, $needsNama, $noUrutAbsen
    ): int {
        // Cek apakah sudah ada
        $stmtCheck = $pdo->prepare(
            'SELECT id, nama FROM mahasiswa WHERE uid_firebase = :uid LIMIT 1'
        );
        $stmtCheck->execute([':uid' => $firebaseUid]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Sudah ada — update email, jurusan, last_login
            $pdo->prepare(
                'UPDATE mahasiswa
                 SET email      = :email,
                     nim        = :nim,
                     angkatan   = :angkatan,
                     jurusan_id = :jurusan_id,
                     no_urut_absen = :no_urut_absen,
                     updated_at = NOW()
                 WHERE uid_firebase = :uid'
            )->execute([
                ':email'      => $email,
                ':nim'        => $nim,
                ':angkatan'   => $angkatan,
                ':jurusan_id' => $jurusanId,
                ':no_urut_absen' => $noUrutAbsen,
                ':uid'        => $firebaseUid,
            ]);

            return (int) $existing['id'];
        }

        // Belum ada — insert baru
        $namaInsert = $needsNama ? null : $displayName;

        $stmtInsert = $pdo->prepare(
            'INSERT INTO mahasiswa
               (uid_firebase, email, nim, nama, needs_nama, angkatan, jurusan_id, no_urut_absen, created_at, updated_at)
             VALUES
               (:uid, :email, :nim, :nama, :needs_nama, :angkatan, :jurusan_id, :no_urut_absen, NOW(), NOW())'
        );
        $stmtInsert->execute([
            ':uid'        => $firebaseUid,
            ':email'      => $email,
            ':nim'        => $nim,
            ':nama'       => $namaInsert,
            ':needs_nama' => $needsNama ? 1 : 0,
            ':angkatan'   => $angkatan,
            ':jurusan_id' => $jurusanId,
            ':no_urut_absen' => $noUrutAbsen,
        ]);

        return (int) $pdo->lastInsertId();
    });
} catch (\Throwable $e) {
    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    $msg   = $debug ? 'Database error: ' . $e->getMessage() : 'Terjadi kesalahan sistem. Coba lagi.';
    respond(['error' => $msg], 500);
}

// ── Cek Apakah Mahasiswa Sudah Punya Pendaftaran Aktif ───────────────────────
$stmtCekDaftar = $pdo->prepare(
    "SELECT COUNT(*) AS total
     FROM pendaftaran p
     JOIN periode pr ON p.periode_id = pr.id
     WHERE p.mahasiswa_id = :mid
       AND pr.status = 'dibuka'"
);
$stmtCekDaftar->execute([':mid' => $mahasiswaId]);
$sudahMendaftar = (int) $stmtCekDaftar->fetchColumn() > 0;

// ── Set Session Mahasiswa ──────────────────────────────────────────────────────
Auth::setMahasiswaSession($mahasiswaId);

// ── Response ──────────────────────────────────────────────────────────────────
respond([
    'ok'              => true,
    'mahasiswa_id'    => $mahasiswaId,
    'nim'             => $nim,
    'email'           => $email,
    'nama'            => $displayName ?: null,
    'jurusan'         => $jurusanNama,
    'angkatan'        => $angkatan,
    'no_urut_absen'   => $noUrutAbsen,
    'needs_nama'      => $needsNama,
    'sudah_mendaftar' => $sudahMendaftar,
    'is_admin'        => false,
    'redirect_url'    => null,
]);

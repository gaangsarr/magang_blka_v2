<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\AzureAuth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::startSession(startPHP: true);

// 1. Tangkap error pembatalan atau error dari Microsoft
if (isset($_GET['error'])) {
    $errCode = $_GET['error'];
    $errDesc = $_GET['error_description'] ?? '';

    if ($errCode === 'access_denied') {
        header('Location: /login.html?error=' . urlencode('Proses login Microsoft dibatalkan oleh pengguna.'));
        exit;
    }

    header('Location: /login.html?error=' . urlencode('Gagal login Microsoft: ' . ($errDesc ?: $errCode)));
    exit;
}

// 2. Validasi CSRF state (cek session dan cookie fallback)
$sessionState  = $_SESSION['oauth_state'] ?? null;
$cookieState   = $_COOKIE['azure_oauth_state'] ?? null;
$receivedState = (string)($_GET['state'] ?? '');

unset($_SESSION['oauth_state']);
if (isset($_COOKIE['azure_oauth_state'])) {
    setcookie('azure_oauth_state', '', ['expires' => time() - 3600, 'path' => '/']);
}

$stateMatches = (!empty($sessionState) && hash_equals($sessionState, $receivedState))
             || (!empty($cookieState) && hash_equals($cookieState, $receivedState));

if (empty($receivedState) || !$stateMatches) {
    header('Location: /login.html?error=' . urlencode('Sesi login tidak valid atau sudah kadaluarsa (CSRF Mismatch). Silakan coba lagi.'));
    exit;
}

// 3. Validasi authorization code
$code = trim($_GET['code'] ?? '');
if (empty($code)) {
    header('Location: /login.html?error=' . urlencode('Authorization code tidak diterima dari server Microsoft.'));
    exit;
}

// 4. Tukar authorization code dengan Access Token & ID Token
try {
    $tokens = AzureAuth::exchangeCodeForToken($code);
    $accessToken = $tokens['access_token'] ?? '';
    if (empty($accessToken)) {
        throw new Exception('Access token kosong dalam respon Microsoft.');
    }

    // 5. Ambil profil pengguna dari Microsoft Graph API
    $profile = AzureAuth::getUserProfile($accessToken);
} catch (\Throwable $e) {
    header('Location: /login.html?error=' . urlencode($e->getMessage()));
    exit;
}

$azureUid    = $profile['id'];
$displayName = trim($profile['displayName']);
$email       = strtolower(trim($profile['mail']));

// 6. Validasi domain email @itpln.ac.id
if (!str_ends_with($email, '@itpln.ac.id')) {
    header('Location: /login.html?error=' . urlencode('Hanya akun dengan email resmi @itpln.ac.id yang diizinkan masuk ke sistem REMATE.'));
    exit;
}

try {
    $pdo = Database::getInstance();

    // 7. Cek apakah pengguna adalah Admin (Dosen/BLKA/Superadmin)
    $stmtAdmin = $pdo->prepare(
        "SELECT id, nama, role, aktif FROM admin WHERE email = :email AND aktif = 1 LIMIT 1"
    );
    $stmtAdmin->execute([':email' => $email]);
    $adminAccount = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

    if ($adminAccount) {
        // Berhasil login sebagai Admin
        Auth::setAdminSession(
            (int)$adminAccount['id'],
            $adminAccount['nama'] ?: ($displayName ?: 'Admin'),
            $adminAccount['role']
        );
        header('Location: /admin/index.html');
        exit;
    }

    // 8. Pengguna Mahasiswa: Parse NIM dari email
    $nimData = Auth::parseNimFromEmail($email);

    if ($nimData === null) {
        header('Location: /login.html?error=' . urlencode(
            "Email {$email} terdeteksi sebagai akun Dosen/Staf ITPLN dan belum diberikan hak akses Admin. "
            . "Jika kamu Mahasiswa, pastikan login dengan akun email mahasiswa ITPLN."
        ));
        exit;
    }

    $nim         = $nimData['nim'];
    $angkatan    = $nimData['angkatan'];
    $kodeJurusan = $nimData['kode_jurusan'];
    $noUrutAbsen = $nimData['no_urut_absen'];

    // 9. Lookup jurusan dari kode jurusan NIM
    $stmtJurusan = $pdo->prepare(
        'SELECT id, nama_jurusan FROM jurusan WHERE kode = :kode LIMIT 1'
    );
    $stmtJurusan->execute([':kode' => $kodeJurusan]);
    $jurusan = $stmtJurusan->fetch(PDO::FETCH_ASSOC);

    $jurusanId = $jurusan ? (int)$jurusan['id'] : null;
    $needsNama = empty($displayName);

    // 10. Upsert Data Mahasiswa di Database
    $mahasiswaId = Database::transaction(function (PDO $pdo) use (
        $azureUid, $email, $nim, $displayName, $angkatan, $jurusanId, $needsNama, $noUrutAbsen
    ): int {
        // Cek apakah mahasiswa sudah terdaftar berdasarkan Azure UID atau Email
        $stmtCheck = $pdo->prepare(
            'SELECT id, nama, uid_firebase FROM mahasiswa WHERE uid_firebase = :uid OR email = :email LIMIT 1'
        );
        $stmtCheck->execute([':uid' => $azureUid, ':email' => $email]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        $namaValue = !empty($displayName) ? $displayName : null;
        $needsNama = empty($displayName);

        if ($existing) {
            // Update data terbaru
            $stmtUpdate = $pdo->prepare(
                'UPDATE mahasiswa
                 SET uid_firebase  = :uid,
                     email         = :email,
                     nim           = :nim,
                     nama          = COALESCE(:nama, nama),
                     needs_nama    = :needs_nama,
                     angkatan      = :angkatan,
                     jurusan_id    = :jurusan_id,
                     no_urut_absen = :no_urut_absen,
                     updated_at    = NOW()
                 WHERE id = :id'
            );
            $stmtUpdate->execute([
                ':uid'           => $azureUid,
                ':email'         => $email,
                ':nim'           => $nim,
                ':nama'          => $namaValue,
                ':needs_nama'    => $needsNama ? 1 : 0,
                ':angkatan'      => $angkatan,
                ':jurusan_id'    => $jurusanId,
                ':no_urut_absen' => $noUrutAbsen,
                ':id'            => $existing['id'],
            ]);

            return (int)$existing['id'];
        }

        // Insert mahasiswa baru
        $stmtInsert = $pdo->prepare(
            'INSERT INTO mahasiswa
               (uid_firebase, email, nim, nama, needs_nama, angkatan, jurusan_id, no_urut_absen, created_at, updated_at)
             VALUES
               (:uid, :email, :nim, :nama, :needs_nama, :angkatan, :jurusan_id, :no_urut_absen, NOW(), NOW())'
        );
        $stmtInsert->execute([
            ':uid'           => $azureUid,
            ':email'         => $email,
            ':nim'           => $nim,
            ':nama'          => $namaValue,
            ':needs_nama'    => $needsNama ? 1 : 0,
            ':angkatan'      => $angkatan,
            ':jurusan_id'    => $jurusanId,
            ':no_urut_absen' => $noUrutAbsen,
        ]);

        return (int)$pdo->lastInsertId();
    });

    // 11. Set Session Mahasiswa
    Auth::setMahasiswaSession($mahasiswaId);

    // 12. Cek riwayat pendaftaran untuk pengalihan tujuan (redirect)
    $stmtCekDaftar = $pdo->prepare(
        "SELECT COUNT(*) AS total
         FROM pendaftaran p
         JOIN periode pr ON p.periode_id = pr.id
         WHERE p.mahasiswa_id = :mid
           AND pr.status = 'dibuka'"
    );
    $stmtCekDaftar->execute([':mid' => $mahasiswaId]);
    $sudahMendaftar = (int)$stmtCekDaftar->fetchColumn() > 0;

    $returnTo = $_SESSION['oauth_return_to'] ?? null;
    unset($_SESSION['oauth_return_to']);

    if (!empty($returnTo)) {
        header('Location: ' . $returnTo);
        exit;
    }

    if ($sudahMendaftar) {
        header('Location: /status.html');
        exit;
    }

    if ($needsNama) {
        header('Location: /daftar.html?needs_nama=1');
        exit;
    }

    header('Location: /daftar.html');
    exit;

} catch (\Throwable $e) {
    $debug = ($_ENV['APP_ENV'] ?? 'local') === 'local' || ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    $msg = $debug ? 'Gagal memproses login: ' . $e->getMessage() : 'Terjadi kesalahan sistem saat memproses login.';
    header('Location: /login.html?error=' . urlencode($msg));
    exit;
}

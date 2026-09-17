<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;
use App\AzureAuth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::startSession(startPHP: true);

// 1. Jika sudah login sebagai Admin, langsung arahkan ke dashboard
$admin = Auth::getAdmin();
if ($admin) {
    if ($admin['role'] === 'admin_perusahaan') {
        header('Location: /perusahaan/index.html');
    } else {
        header('Location: /admin/index.html');
    }
    exit;
}

// Jika sudah login sebagai Mahasiswa, cek apakah akunnya kini telah didaftarkan sebagai Admin
$mahasiswa = Auth::getMahasiswa();
if ($mahasiswa) {
    $pdo = App\Database::getInstance();
    $stmtCekAdmin = $pdo->prepare(
        "SELECT id, nama, role, entitas_id, force_password_change, aktif 
         FROM admin 
         WHERE (LOWER(TRIM(email)) = :email OR (mahasiswa_id IS NOT NULL AND mahasiswa_id = :mid)) 
           AND aktif = 1 
         LIMIT 1"
    );
    $stmtCekAdmin->execute([
        ':email' => strtolower(trim($mahasiswa['email'] ?? '')),
        ':mid'   => (int)$mahasiswa['id'],
    ]);
    $adm = $stmtCekAdmin->fetch(PDO::FETCH_ASSOC);

    if ($adm) {
        Auth::setAdminSession(
            (int)$adm['id'],
            $adm['nama'] ?: ($mahasiswa['nama'] ?? 'Admin'),
            $adm['role'] ?? 'admin_blka',
            !empty($adm['entitas_id']) ? (int)$adm['entitas_id'] : null,
            (bool)($adm['force_password_change'] ?? false)
        );
        if ($adm['role'] === 'admin_perusahaan') {
            header('Location: /perusahaan/index.html');
        } else {
            header('Location: /admin/index.html');
        }
        exit;
    }

    header('Location: /daftar.html');
    exit;
}

// 2. Cek apakah kredensial Azure sudah dikonfigurasi
if (!AzureAuth::isConfigured()) {
    if (AzureAuth::isDevMockAllowed()) {
        // Mode development lokal: arahkan ke halaman Mock Login
        header('Location: /api/auth/azure/mock.php');
        exit;
    }

    header('Location: /login.html?error=' . urlencode('Konfigurasi Microsoft Azure belum lengkap. Silakan hubungi Administrator.'));
    exit;
}

// 3. Buat CSRF state token
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

// Set fallback cookie ber-SameSite Lax
setcookie('azure_oauth_state', $state, [
    'expires'  => time() + 600, // 10 menit
    'path'     => '/',
    'secure'   => ($_ENV['APP_ENV'] ?? 'local') !== 'local',
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (!empty($_GET['return_to'])) {
    $_SESSION['oauth_return_to'] = filter_var($_GET['return_to'], FILTER_SANITIZE_URL);
}

// Pastikan session tersimpan ke disk sebelum redirect
session_write_close();

// 4. Redirect ke halaman otorisasi Microsoft
$authUrl = AzureAuth::getAuthorizationUrl($state);
header('Location: ' . $authUrl);
exit;

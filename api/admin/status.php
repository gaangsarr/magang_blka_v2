<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$csrfToken = Auth::generateCsrfToken();

// Jika sesi saat ini masih berstatus mahasiswa, periksa apakah akunnya telah didaftarkan sebagai Admin
if (!Auth::isLoggedInAdmin() && Auth::isLoggedInMahasiswa()) {
    $mahasiswa = Auth::getMahasiswa();
    $mid = Auth::getMahasiswaId();
    $pdo = App\Database::getInstance();
    $stmtCheckAdmin = $pdo->prepare(
        "SELECT id, nama, email, username, role, entitas_id, force_password_change, aktif 
         FROM admin 
         WHERE (LOWER(TRIM(email)) = :email OR (mahasiswa_id IS NOT NULL AND mahasiswa_id = :mid)) 
           AND aktif = 1 
         LIMIT 1"
    );
    $stmtCheckAdmin->execute([
        ':email' => strtolower(trim($mahasiswa['email'] ?? '')),
        ':mid'   => $mid
    ]);
    $adminFound = $stmtCheckAdmin->fetch(PDO::FETCH_ASSOC);
    if ($adminFound) {
        Auth::setAdminSession(
            (int)$adminFound['id'],
            $adminFound['nama'] ?: ($mahasiswa['nama'] ?? 'Admin'),
            $adminFound['role'] ?? 'admin_blka',
            !empty($adminFound['entitas_id']) ? (int)$adminFound['entitas_id'] : null,
            (bool)($adminFound['force_password_change'] ?? false)
        );
    }
}

if (Auth::isLoggedInAdmin()) {
    echo json_encode([
        'authenticated' => true,
        'admin' => Auth::getAdmin(),
        'csrf_token' => $csrfToken
    ]);
} else {
    echo json_encode([
        'authenticated' => false,
        'csrf_token' => $csrfToken
    ]);
}

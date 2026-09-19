<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$identifier = trim((string)($body['identifier'] ?? $body['email'] ?? $body['username'] ?? ''));
$password   = trim((string)($body['password'] ?? ''));

if ($identifier === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Username/Email dan password wajib diisi.']);
    exit;
}

// Rate limiting lapis 1: max 60 login per menit per IP
// Longgar karena admin PLN di 1 kantor bisa share IP yang sama
Auth::rateLimitByIp('login_admin', 60, 60);

// Rate limiting lapis 2: max 5 login per menit per username/email
// Ketat per-akun untuk cegah brute-force password 1 admin
Auth::rateLimitByIdentity($identifier, 'login_admin', 5, 60);

try {
    $pdo = Database::getInstance();
    
    // Wajib cek aktif = 1 agar admin yang dicabut aksesnya tidak bisa login
    $stmt = $pdo->prepare("
        SELECT id, nama, email, username, role, entitas_id, password_hash, force_password_change, aktif 
        FROM admin 
        WHERE (email = :email OR username = :uname) AND aktif = 1 
        LIMIT 1
    ");
    $stmt->execute([':email' => $identifier, ':uname' => $identifier]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Username/Email atau password salah.']);
        exit;
    }
    
    $role = $admin['role'] ?? 'admin_blka';
    $entitasId = !empty($admin['entitas_id']) ? (int)$admin['entitas_id'] : null;
    $forceChange = (bool)($admin['force_password_change'] ?? false);

    // Set admin session dengan role yang benar dari database
    Auth::setAdminSession(
        (int)$admin['id'], 
        $admin['nama'], 
        $role, 
        $entitasId, 
        $forceChange
    );
    
    $redirectUrl = ($role === 'admin_perusahaan') ? '/perusahaan/index.html' : '/admin/index.html';

    echo json_encode([
        'ok'                    => true,
        'message'               => 'Login berhasil.',
        'role'                  => $role,
        'force_password_change' => $forceChange,
        'redirect'              => $redirectUrl
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat login.')]);
}


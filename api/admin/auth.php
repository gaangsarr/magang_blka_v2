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

if (!isset($body['email'], $body['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Email dan password wajib diisi.']);
    exit;
}

$email = trim($body['email']);
$password = trim($body['password']);

try {
    $pdo = Database::getInstance();
    
    $stmt = $pdo->prepare("SELECT id, nama, password_hash FROM admin WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Email atau password salah.']);
        exit;
    }
    
    // Set admin session
    Auth::setAdminSession((int)$admin['id'], $admin['nama']);
    
    echo json_encode([
        'ok' => true,
        'message' => 'Login berhasil.',
        'redirect' => '/admin/index.html'
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

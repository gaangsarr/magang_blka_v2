<?php
/**
 * api/auth/logout.php
 *
 * Endpoint: POST /api/auth/logout.php
 *
 * Hapus session server. Firebase sign-out dilakukan di sisi client (auth.js).
 * Redirect ke /login.html setelah logout.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

Auth::logout();

echo json_encode(['ok' => true, 'message' => 'Logout berhasil.']);

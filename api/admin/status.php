<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

$csrfToken = Auth::generateCsrfToken();

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

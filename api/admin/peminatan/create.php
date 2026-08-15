<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON.']);
    exit;
}

$nama = trim($body['nama'] ?? '');
$deskripsi = trim($body['deskripsi'] ?? '');

if ($nama === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama peminatan wajib diisi.']);
    exit;
}

if (mb_strlen($nama) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama peminatan maksimal 100 karakter.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Check uniqueness
    $stmtCheck = $pdo->prepare("SELECT id FROM peminatan WHERE nama = :nama");
    $stmtCheck->execute([':nama' => $nama]);
    if ($stmtCheck->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => "Peminatan '$nama' sudah ada."]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO peminatan (nama, deskripsi, aktif) VALUES (:nama, :desk, 1)");
    $stmt->execute([
        ':nama' => $nama,
        ':desk' => $deskripsi ?: null,
    ]);

    echo json_encode([
        'ok'      => true,
        'message' => "Peminatan '$nama' berhasil ditambahkan.",
        'id'      => (int) $pdo->lastInsertId(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menambah peminatan.')]);
}


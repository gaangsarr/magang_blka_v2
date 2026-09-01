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
    echo json_encode(['error' => 'Format JSON tidak valid.']);
    exit;
}

$kode = strtoupper(trim((string)($body['kode'] ?? '')));
$jenjang = strtoupper(trim((string)($body['jenjang'] ?? 'S1')));
$namaJurusan = trim((string)($body['nama_jurusan'] ?? ''));
$aktif = isset($body['aktif']) ? ((int)$body['aktif'] === 1 ? 1 : 0) : 1;

if ($kode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Kode program studi wajib diisi (contoh: 11, 31, 71).']);
    exit;
}

if (strlen($kode) !== 2 || !ctype_alnum($kode)) {
    http_response_code(400);
    echo json_encode(['error' => 'Kode program studi harus terdiri dari 2 digit alfanumerik (contoh: 11, 31).']);
    exit;
}

$validJenjang = ['D3', 'D4', 'S1', 'S2', 'S3', 'Profesi'];
if (!in_array($jenjang, $validJenjang, true)) {
    $jenjang = 'S1';
}

if ($namaJurusan === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama program studi wajib diisi.']);
    exit;
}

if (mb_strlen($namaJurusan) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama program studi maksimal 100 karakter.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Check unique kode
    $stmtCheckKode = $pdo->prepare("SELECT id, nama_jurusan FROM jurusan WHERE kode = :kode");
    $stmtCheckKode->execute([':kode' => $kode]);
    $existingKode = $stmtCheckKode->fetch(PDO::FETCH_ASSOC);
    if ($existingKode) {
        http_response_code(409);
        echo json_encode(['error' => "Kode '$kode' sudah digunakan oleh program studi '{$existingKode['nama_jurusan']}'."]);
        exit;
    }

    // Check unique nama_jurusan + jenjang
    $stmtCheckNama = $pdo->prepare("SELECT id FROM jurusan WHERE LOWER(nama_jurusan) = LOWER(:nama) AND jenjang = :jenjang");
    $stmtCheckNama->execute([':nama' => $namaJurusan, ':jenjang' => $jenjang]);
    if ($stmtCheckNama->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => "Program studi $jenjang '$namaJurusan' sudah terdaftar."]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO jurusan (kode, jenjang, nama_jurusan, aktif) VALUES (:kode, :jenjang, :nama, :aktif)");
    $stmt->execute([
        ':kode'    => $kode,
        ':jenjang' => $jenjang,
        ':nama'    => $namaJurusan,
        ':aktif'   => $aktif,
    ]);

    $newId = (int)$pdo->lastInsertId();

    echo json_encode([
        'ok'      => true,
        'message' => "Program studi $jenjang $namaJurusan (Kode: $kode) berhasil ditambahkan.",
        'id'      => $newId,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menambahkan program studi.')]);
}

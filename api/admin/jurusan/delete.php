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

$id = isset($body['id']) ? (int)$body['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID program studi tidak valid.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Check existence
    $stmtExist = $pdo->prepare("SELECT id, kode, nama_jurusan FROM jurusan WHERE id = :id");
    $stmtExist->execute([':id' => $id]);
    $jurusan = $stmtExist->fetch(PDO::FETCH_ASSOC);
    if (!$jurusan) {
        http_response_code(404);
        echo json_encode(['error' => 'Program studi tidak ditemukan.']);
        exit;
    }

    // Check relations in mahasiswa
    $stmtMhs = $pdo->prepare("SELECT COUNT(*) FROM mahasiswa WHERE jurusan_id = :id");
    $stmtMhs->execute([':id' => $id]);
    $countMhs = (int)$stmtMhs->fetchColumn();

    // Check relations in unit_periode_jurusan
    $stmtUpj = $pdo->prepare("SELECT COUNT(*) FROM unit_periode_jurusan WHERE jurusan_id = :id");
    $stmtUpj->execute([':id' => $id]);
    $countUpj = (int)$stmtUpj->fetchColumn();

    // Check relations in unit_jurusan
    $stmtUj = $pdo->prepare("SELECT COUNT(*) FROM unit_jurusan WHERE jurusan_id = :id");
    $stmtUj->execute([':id' => $id]);
    $countUj = (int)$stmtUj->fetchColumn();

    if ($countMhs > 0 || $countUpj > 0 || $countUj > 0) {
        $reasons = [];
        if ($countMhs > 0) $reasons[] = "{$countMhs} data mahasiswa";
        if ($countUpj > 0 || $countUj > 0) $reasons[] = "relasi kuota unit kantor mitra";

        $reasonStr = implode(' dan ', $reasons);

        http_response_code(400);
        echo json_encode([
            'error' => "Program studi '{$jurusan['nama_jurusan']}' tidak dapat dihapus karena masih terhubung dengan {$reasonStr}. Silakan nonaktifkan status program studi ini sebagai alternatif.",
            'has_relations' => true,
        ]);
        exit;
    }

    $stmtDel = $pdo->prepare("DELETE FROM jurusan WHERE id = :id");
    $stmtDel->execute([':id' => $id]);

    echo json_encode([
        'ok'      => true,
        'message' => "Program studi '{$jurusan['nama_jurusan']}' (Kode: {$jurusan['kode']}) berhasil dihapus.",
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menghapus program studi.')]);
}

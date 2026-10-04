<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

Auth::requireMahasiswaApi();

$mid = Auth::getMahasiswaId();
$pdo = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // 1. Cek draft dari reservasi aktif terlebih dahulu
    $stmtRes = $pdo->prepare("
        SELECT draft_data 
        FROM reservasi 
        WHERE mahasiswa_id = :mid AND status = 'ditahan' AND expired_at > NOW() 
        ORDER BY id DESC LIMIT 1
    ");
    $stmtRes->execute([':mid' => $mid]);
    $draftRaw = $stmtRes->fetchColumn();

    // 2. Jika tidak ada reservasi aktif, ambil dari tabel mahasiswa
    if (!$draftRaw) {
        $stmtMhs = $pdo->prepare("SELECT draft_data FROM mahasiswa WHERE id = :mid LIMIT 1");
        $stmtMhs->execute([':mid' => $mid]);
        $draftRaw = $stmtMhs->fetchColumn();
    }

    $draft = null;
    if ($draftRaw) {
        $draft = json_decode($draftRaw, true);
    }

    echo json_encode([
        'ok'    => true,
        'draft' => $draft
    ]);
    exit;
}

if ($method === 'POST') {
    Auth::requireCsrfApi();
    Auth::rateLimit('save_draft', 60, 60); // Maksimal 60 simpan per menit

    $body = json_decode(file_get_contents('php://input'), true);
    $draft = $body['draft'] ?? null;

    $draftJson = null;
    if ($draft !== null && (is_array($draft) || is_object($draft))) {
        $draftJson = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // Update di tabel mahasiswa
    $stmtMhs = $pdo->prepare("UPDATE mahasiswa SET draft_data = :draft, updated_at = NOW() WHERE id = :mid");
    $stmtMhs->execute([
        ':draft' => $draftJson,
        ':mid'   => $mid
    ]);

    // Jika sedang ada reservasi yang ditahan, sinkronkan juga snapshot draft reservasi
    $stmtRes = $pdo->prepare("
        UPDATE reservasi 
        SET draft_data = :draft 
        WHERE mahasiswa_id = :mid AND status = 'ditahan' AND expired_at > NOW()
    ");
    $stmtRes->execute([
        ':draft' => $draftJson,
        ':mid'   => $mid
    ]);

    echo json_encode([
        'ok'      => true,
        'message' => 'Draft formulir berhasil disinkronisasi ke server.'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak diizinkan.']);

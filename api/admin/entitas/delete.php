<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

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
$id   = isset($body['id']) ? (int)$body['id'] : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['error' => 'id wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil entitas yang akan dihapus
    $stmtGet = $pdo->prepare("SELECT id, tipe, nama FROM entitas_perusahaan WHERE id = ?");
    $stmtGet->execute([$id]);
    $entitas = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$entitas) {
        http_response_code(404);
        echo json_encode(['error' => 'Entitas tidak ditemukan.']);
        exit;
    }

    // Cek apakah masih punya child node aktif di bawahnya
    $stmtChild = $pdo->prepare(
        "SELECT COUNT(*) FROM entitas_perusahaan WHERE parent_id = ? AND aktif = 1"
    );
    $stmtChild->execute([$id]);
    $childCount = (int)$stmtChild->fetchColumn();

    if ($childCount > 0) {
        http_response_code(400);
        echo json_encode([
            'error' => "Tidak bisa menonaktifkan '{$entitas['nama']}' karena masih memiliki "
                . "$childCount sub-unit aktif di bawahnya. Nonaktifkan sub-unit terlebih dahulu."
        ]);
        exit;
    }

    // Cek apakah ada reservasi aktif pada alokasi kuota entitas ini
    $stmtReservasi = $pdo->prepare("
        SELECT COUNT(*) 
        FROM unit_pelaksana_periode upp
        JOIN reservasi r ON r.upp_id = upp.id
        WHERE upp.entitas_id = ?
          AND r.status = 'aktif'
    ");
    $stmtReservasi->execute([$id]);
    $reservasiAktif = (int)$stmtReservasi->fetchColumn();

    if ($reservasiAktif > 0) {
        http_response_code(400);
        echo json_encode([
            'error' => "Tidak bisa menonaktifkan '{$entitas['nama']}' karena masih ada "
                . "$reservasiAktif reservasi aktif."
        ]);
        exit;
    }

    // Soft-delete: set aktif = 0
    $stmtDel = $pdo->prepare(
        "UPDATE entitas_perusahaan SET aktif = 0 WHERE id = ?"
    );
    $stmtDel->execute([$id]);

    echo json_encode([
        'ok'      => true,
        'message' => "'{$entitas['nama']}' berhasil dinonaktifkan.",
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menonaktifkan entitas.')]);
}


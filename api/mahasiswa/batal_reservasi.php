<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['reservasi_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'reservasi_id wajib diisi.']);
    exit;
}

$reservasiId = (int)$body['reservasi_id'];
$mahasiswaId = Auth::getMahasiswaId();

try {
    Database::transaction(function (PDO $pdo) use ($mahasiswaId, $reservasiId) {
        $stmtCek = $pdo->prepare("SELECT unit_pelaksana_periode_id, status FROM reservasi WHERE id = :id AND mahasiswa_id = :mid FOR UPDATE");
        $stmtCek->execute([':id' => $reservasiId, ':mid' => $mahasiswaId]);
        $res = $stmtCek->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            throw new \Exception('Reservasi tidak ditemukan.');
        }

        if ($res['status'] !== 'ditahan') {
            throw new \Exception('Hanya reservasi yang sedang ditahan yang bisa dibatalkan.');
        }

        // Batalkan
        $pdo->prepare("UPDATE reservasi SET status = 'dibatalkan' WHERE id = :id")->execute([':id' => $reservasiId]);
        
        // Kembalikan kuota
        $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :upp_id")->execute([':upp_id' => $res['unit_pelaksana_periode_id']]);
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Reservasi dibatalkan dan kuota dikembalikan.'
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

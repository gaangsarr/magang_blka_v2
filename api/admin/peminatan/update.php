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

$id = isset($body['id']) ? (int) $body['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID peminatan tidak valid.']);
    exit;
}

$nama = isset($body['nama']) ? trim($body['nama']) : null;
$deskripsi = isset($body['deskripsi']) ? trim($body['deskripsi']) : null;
$aktif = isset($body['aktif']) ? (int) $body['aktif'] : null;

if ($nama !== null && $nama === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama peminatan tidak boleh kosong.']);
    exit;
}

if ($nama !== null && mb_strlen($nama) > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama peminatan maksimal 100 karakter.']);
    exit;
}

$hasJurusanPayload = isset($body['jurusan_ids']) && is_array($body['jurusan_ids']);
$jurusanIds = $hasJurusanPayload
    ? array_values(array_unique(array_filter(array_map('intval', $body['jurusan_ids']))))
    : null;

if ($hasJurusanPayload && empty($jurusanIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Pilih minimal satu Program Studi (Prodi) untuk peminatan ini.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Check existence
    $stmtExist = $pdo->prepare("SELECT id FROM peminatan WHERE id = :id");
    $stmtExist->execute([':id' => $id]);
    if (!$stmtExist->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Peminatan tidak ditemukan.']);
        exit;
    }

    // Check nama uniqueness (if changing name)
    if ($nama !== null) {
        $stmtDup = $pdo->prepare("SELECT id FROM peminatan WHERE nama = :nama AND id != :id");
        $stmtDup->execute([':nama' => $nama, ':id' => $id]);
        if ($stmtDup->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => "Peminatan '$nama' sudah ada."]);
            exit;
        }
    }

    // Build dynamic update
    $setClauses = [];
    $params = [':id' => $id];

    if ($nama !== null) {
        $setClauses[] = 'nama = :nama';
        $params[':nama'] = $nama;
    }
    if ($deskripsi !== null) {
        $setClauses[] = 'deskripsi = :deskripsi';
        $params[':deskripsi'] = $deskripsi ?: null;
    }
    if ($aktif !== null) {
        $setClauses[] = 'aktif = :aktif';
        $params[':aktif'] = $aktif ? 1 : 0;
    }

    if (empty($setClauses) && !$hasJurusanPayload) {
        http_response_code(400);
        echo json_encode(['error' => 'Tidak ada data yang diubah.']);
        exit;
    }

    Database::transaction(function (PDO $pdo) use ($id, $setClauses, $params, $hasJurusanPayload, $jurusanIds) {
        if (!empty($setClauses)) {
            $sql = "UPDATE peminatan SET " . implode(', ', $setClauses) . " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        if ($hasJurusanPayload && $jurusanIds !== null) {
            $pdo->prepare("DELETE FROM peminatan_jurusan WHERE peminatan_id = ?")->execute([$id]);
            $stmtIns = $pdo->prepare("INSERT INTO peminatan_jurusan (peminatan_id, jurusan_id) VALUES (?, ?)");
            foreach ($jurusanIds as $jid) {
                $stmtIns->execute([$id, $jid]);
            }
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Peminatan berhasil diperbarui.',
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui peminatan.')]);
}


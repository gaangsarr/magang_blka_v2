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

$kode = isset($body['kode']) ? strtoupper(trim((string)$body['kode'])) : null;
$jenjang = isset($body['jenjang']) ? strtoupper(trim((string)$body['jenjang'])) : null;
$namaJurusan = isset($body['nama_jurusan']) ? trim((string)$body['nama_jurusan']) : null;
$aktif = isset($body['aktif']) ? ((int)$body['aktif'] === 1 ? 1 : 0) : null;

if ($kode !== null) {
    if ($kode === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Kode program studi tidak boleh kosong.']);
        exit;
    }
    if (strlen($kode) !== 2 || !ctype_alnum($kode)) {
        http_response_code(400);
        echo json_encode(['error' => 'Kode program studi harus terdiri dari 2 digit alfanumerik (contoh: 11, 31).']);
        exit;
    }
}

if ($jenjang !== null) {
    $validJenjang = ['D3', 'D4', 'S1', 'S2', 'S3', 'Profesi'];
    if (!in_array($jenjang, $validJenjang, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Pilihan jenjang tidak valid.']);
        exit;
    }
}

if ($namaJurusan !== null) {
    if ($namaJurusan === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Nama program studi tidak boleh kosong.']);
        exit;
    }
    if (mb_strlen($namaJurusan) > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'Nama program studi maksimal 100 karakter.']);
        exit;
    }
}

try {
    $pdo = Database::getInstance();

    // Check existence
    $stmtExist = $pdo->prepare("SELECT id, kode, jenjang, nama_jurusan FROM jurusan WHERE id = :id");
    $stmtExist->execute([':id' => $id]);
    $existing = $stmtExist->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Program studi tidak ditemukan.']);
        exit;
    }

    // Check unique kode if changed
    if ($kode !== null) {
        $stmtDupKode = $pdo->prepare("SELECT id, nama_jurusan FROM jurusan WHERE kode = :kode AND id != :id");
        $stmtDupKode->execute([':kode' => $kode, ':id' => $id]);
        $dupKode = $stmtDupKode->fetch(PDO::FETCH_ASSOC);
        if ($dupKode) {
            http_response_code(409);
            echo json_encode(['error' => "Kode '$kode' sudah digunakan oleh program studi '{$dupKode['nama_jurusan']}'."]);
            exit;
        }
    }

    // Check unique nama + jenjang if changed
    $checkNama = $namaJurusan !== null ? $namaJurusan : $existing['nama_jurusan'];
    $checkJenjang = $jenjang !== null ? $jenjang : $existing['jenjang'];

    if ($namaJurusan !== null || $jenjang !== null) {
        $stmtDupNama = $pdo->prepare("SELECT id FROM jurusan WHERE LOWER(nama_jurusan) = LOWER(:nama) AND jenjang = :jenjang AND id != :id");
        $stmtDupNama->execute([':nama' => $checkNama, ':jenjang' => $checkJenjang, ':id' => $id]);
        if ($stmtDupNama->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => "Program studi $checkJenjang '$checkNama' sudah terdaftar."]);
            exit;
        }
    }

    $setClauses = [];
    $params = [':id' => $id];

    if ($kode !== null) {
        $setClauses[] = 'kode = :kode';
        $params[':kode'] = $kode;
    }
    if ($jenjang !== null) {
        $setClauses[] = 'jenjang = :jenjang';
        $params[':jenjang'] = $jenjang;
    }
    if ($namaJurusan !== null) {
        $setClauses[] = 'nama_jurusan = :nama';
        $params[':nama'] = $namaJurusan;
    }
    if ($aktif !== null) {
        $setClauses[] = 'aktif = :aktif';
        $params[':aktif'] = $aktif;
    }

    if (empty($setClauses)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tidak ada data yang diubah.']);
        exit;
    }

    $sql = "UPDATE jurusan SET " . implode(', ', $setClauses) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode([
        'ok'      => true,
        'message' => 'Data program studi berhasil diperbarui.',
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui program studi.')]);
}

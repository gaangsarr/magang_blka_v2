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

$tipe           = trim($body['tipe'] ?? '');
$parentId       = isset($body['parent_id']) && $body['parent_id'] !== '' ? (int)$body['parent_id'] : null;
$nama           = trim($body['nama'] ?? '');
$singkatan      = trim($body['singkatan'] ?? '') ?: null;
$alamat         = trim($body['alamat'] ?? '') ?: null;
$lat            = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
$lng            = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
$aktif          = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;
$menerimaMagang = isset($body['menerima_magang']) ? (int)(bool)$body['menerima_magang'] : (in_array($tipe, ['unit_pelaksana', 'unit_layanan'], true) ? 1 : 0);
$peminatanIds   = isset($body['peminatan_ids']) && is_array($body['peminatan_ids'])
    ? array_values(array_unique(array_filter(array_map('intval', $body['peminatan_ids']))))
    : [];
$prodiIds       = isset($body['prodi_ids']) && is_array($body['prodi_ids'])
    ? array_values(array_unique(array_filter(array_map('intval', $body['prodi_ids']))))
    : [];

// ── Validasi tipe ────────────────────────────────────────────────────────────
$validTipe = ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana', 'unit_layanan'];
if (!in_array($tipe, $validTipe, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipe tidak valid. Pilih: ' . implode(', ', $validTipe)]);
    exit;
}

// ── Validasi nama wajib ──────────────────────────────────────────────────────
if (empty($nama)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama entitas wajib diisi.']);
    exit;
}

// ── Validasi parent_id ───────────────────────────────────────────────────────
// holding tidak boleh punya parent; tipe lain WAJIB punya parent
if ($tipe !== 'holding' && $parentId === null) {
    http_response_code(400);
    echo json_encode(['error' => "Tipe '$tipe' wajib memiliki parent_id."]);
    exit;
}
if ($tipe === 'holding' && $parentId !== null) {
    http_response_code(400);
    echo json_encode(['error' => 'Holding tidak boleh punya parent.']);
    exit;
}

// ── Validasi aturan hierarki parent-child ────────────────────────────────────
$parentRules = [
    'subholding'      => ['holding'],
    'anak_perusahaan' => ['holding', 'subholding'],
    'unit_induk'      => ['holding', 'subholding', 'anak_perusahaan'],
    'unit_pelaksana'  => ['unit_induk'],
    'unit_layanan'    => ['unit_pelaksana'],
];

// ── Validasi peminatan jika menerima_magang = 1 ──────────────────────────────
if ($menerimaMagang === 1 && empty($peminatanIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Entitas yang menerima magang wajib memiliki minimal 1 peminatan.']);
    exit;
}
if ($menerimaMagang === 0) {
    $peminatanIds = [];
    $prodiIds = [];
}

try {
    $pdo = Database::getInstance();

    // Cek parent valid dan tipe parent sesuai aturan
    if ($parentId !== null) {
        $stmtParent = $pdo->prepare("SELECT tipe FROM entitas_perusahaan WHERE id = ?");
        $stmtParent->execute([$parentId]);
        $parentRow = $stmtParent->fetch(PDO::FETCH_ASSOC);

        if (!$parentRow) {
            http_response_code(400);
            echo json_encode(['error' => 'parent_id tidak ditemukan.']);
            exit;
        }

        $allowedParentTipe = $parentRules[$tipe] ?? [];
        if (!empty($allowedParentTipe) && !in_array($parentRow['tipe'], $allowedParentTipe, true)) {
            http_response_code(400);
            echo json_encode([
                'error' => "Tipe '$tipe' hanya bisa berada di bawah: "
                    . implode(', ', $allowedParentTipe)
                    . ". Parent yang dipilih bertipe '{$parentRow['tipe']}'."
            ]);
            exit;
        }
    }

    $newId = 0;
    Database::transaction(function (PDO $pdo) use (
        $tipe, $parentId, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang, $peminatanIds, $prodiIds, &$newId
    ) {
        $stmt = $pdo->prepare("
            INSERT INTO entitas_perusahaan
                (tipe, parent_id, nama, singkatan, alamat, latitude, longitude, aktif, menerima_magang)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tipe, $parentId, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang]);
        $newId = (int)$pdo->lastInsertId();

        // Insert peminatan jika menerima magang
        if ($menerimaMagang === 1 && !empty($peminatanIds)) {
            $stmtPem = $pdo->prepare(
                "INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)"
            );
            foreach ($peminatanIds as $pid) {
                $stmtPem->execute([$newId, $pid]);
            }
        }

        // Insert default prodi jika ada
        if ($menerimaMagang === 1 && !empty($prodiIds)) {
            $stmtJur = $pdo->prepare(
                "INSERT INTO unit_jurusan (entitas_id, jurusan_id) VALUES (?, ?)"
            );
            foreach ($prodiIds as $jid) {
                $stmtJur->execute([$newId, $jid]);
            }
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Entitas berhasil ditambahkan.',
        'id'      => $newId,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menambahkan entitas.')]);
}


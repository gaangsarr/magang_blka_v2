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

$tipe      = trim($body['tipe'] ?? '');
$parentId  = isset($body['parent_id']) && $body['parent_id'] !== '' ? (int)$body['parent_id'] : null;
$nama      = trim($body['nama'] ?? '');
$singkatan = trim($body['singkatan'] ?? '') ?: null;
$alamat    = trim($body['alamat'] ?? '') ?: null;
$lat       = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
$lng       = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
$aktif     = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids'])
    ? array_map('intval', $body['peminatan_ids'])
    : [];

// ── Validasi tipe ────────────────────────────────────────────────────────────
$validTipe = ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana'];
if (!in_array($tipe, $validTipe, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipe tidak valid. Pilih: ' . implode(', ', $validTipe)]);
    exit;
}

// ── Validasi nama wajib ──────────────────────────────────────────────────────
if (empty($nama)) {
    http_response_code(400);
    echo json_encode(['error' => 'Nama wajib diisi.']);
    exit;
}

// ── Validasi parent_id ───────────────────────────────────────────────────────
// holding tidak perlu parent; semua tipe lain WAJIB punya parent
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
// Aturan: tipe child → tipe parent yang diizinkan
$parentRules = [
    'subholding'      => ['holding'],
    'anak_perusahaan' => ['holding', 'subholding'],
    'unit_induk'      => ['anak_perusahaan', 'holding', 'subholding'],
    'unit_pelaksana'  => ['unit_induk'],
];

// ── Validasi peminatan hanya untuk unit_pelaksana ────────────────────────────
if ($tipe === 'unit_pelaksana' && empty($peminatanIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unit pelaksana wajib memiliki minimal 1 peminatan.']);
    exit;
}
if ($tipe !== 'unit_pelaksana' && !empty($peminatanIds)) {
    // Abaikan peminatan untuk tipe selain unit_pelaksana (bukan error, cukup ignore)
    $peminatanIds = [];
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

    Database::transaction(function (PDO $pdo) use (
        $tipe, $parentId, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $peminatanIds
    ) {
        $stmt = $pdo->prepare("
            INSERT INTO entitas_perusahaan
                (tipe, parent_id, nama, singkatan, alamat, latitude, longitude, aktif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tipe, $parentId, $nama, $singkatan, $alamat, $lat, $lng, $aktif]);
        $entitasId = (int)$pdo->lastInsertId();

        // Insert peminatan hanya untuk unit_pelaksana
        if (!empty($peminatanIds)) {
            $stmtPem = $pdo->prepare(
                "INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)"
            );
            foreach ($peminatanIds as $pid) {
                $stmtPem->execute([$entitasId, $pid]);
            }
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Entitas berhasil ditambahkan.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\Models\PicNarahubung;

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

$entitasId = (int)($body['entitas_id'] ?? 0);
$namaPic   = trim((string)($body['nama_pic'] ?? ''));
$noWa      = trim((string)($body['no_wa'] ?? ''));
$areaHcbp  = trim((string)($body['area_hcbp'] ?? ''));
$email     = trim((string)($body['email'] ?? ''));
$keterangan= trim((string)($body['keterangan'] ?? ''));
$aktif     = isset($body['aktif']) ? ((int)$body['aktif'] === 1 ? 1 : 0) : 1;

if ($entitasId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Unit Induk / Perusahaan wajib dipilih.']);
    exit;
}

if ($namaPic === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nama PIC wajib diisi.']);
    exit;
}

if ($noWa === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Nomor WhatsApp / HP wajib diisi.']);
    exit;
}

// Format phone number
$cleanPhone = preg_replace('/[^0-9]/', '', $noWa);
if (strpos($cleanPhone, '62') === 0) {
    $cleanPhone = '0' . substr($cleanPhone, 2);
} elseif (strpos($cleanPhone, '8') === 0) {
    $cleanPhone = '0' . $cleanPhone;
}

try {
    $pdo = Database::getInstance();

    // Check if entity already has a PIC
    $stmtCheck = $pdo->prepare("SELECT id FROM pic_narahubung WHERE entitas_id = :eid LIMIT 1");
    $stmtCheck->execute([':eid' => $entitasId]);
    if ($stmtCheck->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Unit yang dipilih sudah memiliki data PIC Narahubung. Gunakan fitur edit untuk memperbarui.']);
        exit;
    }

    $newId = PicNarahubung::create([
        'entitas_id' => $entitasId,
        'nama_pic'   => $namaPic,
        'no_wa'      => $cleanPhone,
        'area_hcbp'  => $areaHcbp ?: null,
        'email'      => $email ?: null,
        'keterangan' => $keterangan ?: null,
        'aktif'      => $aktif
    ]);

    echo json_encode([
        'ok'      => true,
        'message' => 'PIC Narahubung berhasil ditambahkan.',
        'id'      => $newId
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menambahkan PIC Narahubung.')]);
}

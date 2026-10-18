<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\SuratPengantarGenerator;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan. Gunakan POST.']);
    exit;
}

Auth::requireCsrfApi();

$periodeId = isset($_POST['periode_id']) ? (int)$_POST['periode_id'] : 0;
if ($periodeId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter periode_id wajib diisi.']);
    exit;
}

if (!isset($_FILES['ttd_file']) || !is_array($_FILES['ttd_file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'File gambar tanda tangan wajib diunggah.']);
    exit;
}

$file = $_FILES['ttd_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Gagal mengunggah file tanda tangan (Kode error: ' . $file['error'] . ').']);
    exit;
}

// Maksimal 3 MB
$maxBytes = 3 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'Ukuran file tanda tangan melebihi batas maksimal 3 MB.']);
    exit;
}

$clientExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$allowedExts = ['png', 'jpg', 'jpeg', 'webp'];
if (!in_array($clientExt, $allowedExts, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'Format file harus berupa gambar PNG, JPG, JPEG, atau WEBP.']);
    exit;
}

$tmpPath = (string)$file['tmp_name'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpPath);
finfo_close($finfo);

$allowedMimes = ['image/png', 'image/jpeg', 'image/webp'];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'MIME type file tidak valid (MIME: ' . htmlspecialchars((string)$mimeType) . ').']);
    exit;
}

$destDir = $root . '/storage/signatures';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

$newFileName = sprintf('ttd_periode_%d_%d.%s', $periodeId, time(), $clientExt);
$targetPath = $destDir . '/' . $newFileName;
$relativeStoragePath = 'storage/signatures/' . $newFileName;

$moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $targetPath) : copy($tmpPath, $targetPath);
if (!$moved) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal menyimpan file tanda tangan ke server.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil path ttd lama jika ada untuk dibersihkan
    $stmtOld = $pdo->prepare("SELECT ttd_image_path FROM konfigurasi_surat_pengantar WHERE periode_id = ?");
    $stmtOld->execute([$periodeId]);
    $oldPath = (string)($stmtOld->fetchColumn() ?: '');

    // Simpan path baru ke database
    SuratPengantarGenerator::saveConfig($pdo, $periodeId, [
        'ttd_image_path' => $relativeStoragePath
    ]);

    if (!empty($oldPath) && $oldPath !== $relativeStoragePath) {
        $oldReal = $root . '/' . ltrim($oldPath, '/');
        if (file_exists($oldReal) && is_file($oldReal)) {
            @unlink($oldReal);
        }
    }

    $base64Preview = SuratPengantarGenerator::getBase64Image($targetPath);

    echo json_encode([
        'ok' => true,
        'message' => 'File tanda tangan berhasil diunggah.',
        'file_path' => $relativeStoragePath,
        'preview_base64' => $base64Preview
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat menyimpan tanda tangan.')]);
}

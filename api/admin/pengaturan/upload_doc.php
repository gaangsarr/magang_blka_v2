<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Guard Admin & CSRF
Auth::requireAdminApi();
Auth::requireCsrfApi();

$admin = Auth::getAdmin();
if (!$admin || !in_array($admin['role'], ['super_admin', 'superadmin', 'admin_blka', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses ditolak. Fitur ini hanya untuk Super Admin dan Admin BLKA.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$docType = trim((string)($_POST['doc_type'] ?? ''));
if ($docType !== 'pedoman' && $docType !== 'logbook') {
    http_response_code(400);
    echo json_encode(['error' => 'Tipe dokumen tidak valid. Pilih "pedoman" atau "logbook".']);
    exit;
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Berkas dokumen wajib diunggah.']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas upload server (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas maksimal formulir.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian. Silakan ulangi.',
        UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke media penyimpanan server.',
        UPLOAD_ERR_EXTENSION  => 'Ekstensi file diblokir oleh sistem server.'
    ];
    $msg = $uploadErrors[$file['error']] ?? 'Gagal mengunggah file (Kode error: ' . $file['error'] . ').';
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

// Batas ukuran file: 10 MB (10 * 1024 * 1024 bytes)
$maxBytes = 10 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'Ukuran file melebihi batas maksimal 10 MB.']);
    exit;
}

$clientExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
$tmpPath   = (string)$file['tmp_name'];

// Validasi format file berdasarkan tipe dokumen
if ($docType === 'pedoman') {
    if ($clientExt !== 'pdf') {
        http_response_code(415);
        echo json_encode(['error' => 'Format file Buku Pedoman harus berupa PDF (.pdf).']);
        exit;
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($mimeType !== 'application/pdf') {
        http_response_code(415);
        echo json_encode(['error' => 'File bukan PDF yang valid (MIME: ' . htmlspecialchars($mimeType ?: 'unknown') . ').']);
        exit;
    }

    // Magic Bytes: %PDF-
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal membaca berkas sementara.']);
        exit;
    }
    $magic = fread($handle, 4);
    fclose($handle);

    if ($magic !== '%PDF') {
        http_response_code(415);
        echo json_encode(['error' => 'Header berkas tidak valid untuk format PDF.']);
        exit;
    }

    $filePrefix = 'pedoman-magang';
    $fileExt    = 'pdf';
    $settingKey = 'doc_pedoman_file';
    $tipeKey    = 'doc_pedoman_tipe';
    $docName    = 'Buku Pedoman Magang';
} else { // logbook
    if ($clientExt !== 'docx') {
        http_response_code(415);
        echo json_encode(['error' => 'Format file Log Book harus berupa Word (.docx).']);
        exit;
    }

    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    $allowedMimes = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/x-zip-compressed'
    ];
    if (!in_array($mimeType, $allowedMimes, true)) {
        http_response_code(415);
        echo json_encode(['error' => 'File bukan DOCX yang valid (MIME: ' . htmlspecialchars($mimeType ?: 'unknown') . ').']);
        exit;
    }

    // Magic Bytes: PK\x03\x04 (ZIP signature)
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal membaca berkas sementara.']);
        exit;
    }
    $magic = fread($handle, 4);
    fclose($handle);

    if ($magic !== "PK\x03\x04") {
        http_response_code(415);
        echo json_encode(['error' => 'Header berkas tidak valid untuk format DOCX.']);
        exit;
    }

    $filePrefix = 'logbook';
    $fileExt    = 'docx';
    $settingKey = 'doc_logbook_file';
    $tipeKey    = 'doc_logbook_tipe';
    $docName    = 'Log Book Magang';
}

$destDir = $root . '/public/assets/docs';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}

// Generate unique timestamped filename to prevent browser cache issues
$newFileName = sprintf('%s-%d.%s', $filePrefix, time(), $fileExt);
$targetPath  = $destDir . '/' . $newFileName;
$publicPath  = '/assets/docs/' . $newFileName;

$moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $targetPath) : copy($tmpPath, $targetPath);
if (!$moved) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal memindahkan file ke direktori tujuan server.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil file lama untuk dibersihkan jika merupakan file ber-timestamp
    $stmtOld = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = :kunci");
    $stmtOld->execute([':kunci' => $settingKey]);
    $oldFilePath = (string)($stmtOld->fetchColumn() ?: '');

    // Simpan path file baru dan set tipe ke 'file'
    $stmtUpd = $pdo->prepare("UPDATE pengaturan SET nilai = :nilai WHERE kunci = :kunci");
    $stmtUpd->execute([':nilai' => $publicPath, ':kunci' => $settingKey]);

    $stmtUpdTipe = $pdo->prepare("UPDATE pengaturan SET nilai = 'file' WHERE kunci = :kunci");
    $stmtUpdTipe->execute([':kunci' => $tipeKey]);

    // Hapus file lama jika ada dan polanya cocok dengan timestamp upload kita
    if (!empty($oldFilePath) && $oldFilePath !== $publicPath) {
        $oldReal = $root . '/public' . $oldFilePath;
        $isTimestamped = preg_match('/^(pedoman-magang|logbook)-\d+\.(pdf|docx)$/', basename($oldReal));
        if ($isTimestamped && file_exists($oldReal)) {
            @unlink($oldReal);
        }
    }

    // Catat log aktivitas
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $adminId = $admin['id'] ?? 1;
    $detail = json_encode([
        'doc_type'     => $docType,
        'doc_name'     => $docName,
        'file_name'    => $newFileName,
        'original_name'=> (string)$file['name'],
        'size_bytes'   => $file['size'],
    ], JSON_UNESCAPED_UNICODE);

    $stmtLog = $pdo->prepare("
        INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
        VALUES (?, 'upload_dokumen_panduan', 'pengaturan', NULL, ?, ?, NOW())
    ");
    $stmtLog->execute([$adminId, $detail, $ip]);

    echo json_encode([
        'ok'        => true,
        'message'   => "Berkas {$docName} berhasil diperbarui dan aktif.",
        'file_path' => $publicPath,
        'file_name' => $newFileName,
        'file_size' => $file['size'],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data pengaturan berkas.')]);
}

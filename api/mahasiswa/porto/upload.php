<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard Mahasiswa & CSRF
Auth::requireMahasiswaApi();
Auth::requireCsrfApi();
Auth::rateLimit('porto_upload', 10, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

try {
    $mahasiswa = Auth::getMahasiswa();
    if (!$mahasiswa || empty($mahasiswa['nim'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Data mahasiswa tidak ditemukan.']);
        exit;
    }

    $nim = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$mahasiswa['nim']);
    if (empty($nim)) {
        http_response_code(400);
        echo json_encode(['error' => 'NIM mahasiswa tidak valid.']);
        exit;
    }

    if (!isset($_FILES['porto']) || !is_array($_FILES['porto'])) {
        http_response_code(400);
        echo json_encode(['error' => 'File Portofolio wajib diunggah.']);
        exit;
    }

    $file = $_FILES['porto'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas upload server.',
            UPLOAD_ERR_FORM_SIZE  => 'Ukuran file melebihi batas form.',
            UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian. Silakan coba lagi.',
            UPLOAD_ERR_NO_FILE    => 'Tidak ada file yang diunggah.',
            UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary server tidak ditemukan.',
            UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk server.',
            UPLOAD_ERR_EXTENSION  => 'Ekstensi file diblokir oleh server.'
        ];
        $msg = $uploadErrors[$file['error']] ?? 'Gagal mengunggah file (Kode error: ' . $file['error'] . ').';
        http_response_code(400);
        echo json_encode(['error' => $msg]);
        exit;
    }

    // 1. Batas ukuran Portofolio: 5 MB (5 * 1024 * 1024 = 5.242.880 bytes)
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) {
        http_response_code(413);
        echo json_encode(['error' => 'Ukuran file melebihi batas maksimal 5 MB.']);
        exit;
    }

    // 2. Cek ekstensi file (.pdf)
    $clientExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if ($clientExt !== 'pdf') {
        http_response_code(415);
        echo json_encode(['error' => 'Format file harus PDF (.pdf).']);
        exit;
    }

    // 3. Cek MIME Type menggunakan fileinfo
    $tmpPath = (string)$file['tmp_name'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if ($mimeType !== 'application/pdf') {
        http_response_code(415);
        echo json_encode(['error' => 'File bukan PDF yang valid (MIME: ' . htmlspecialchars($mimeType ?: 'unknown') . ').']);
        exit;
    }

    // 4. Cek Magic Bytes (%PDF-)
    $handle = fopen($tmpPath, 'rb');
    if (!$handle) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal membaca file yang diunggah.']);
        exit;
    }
    $magicBytes = fread($handle, 4);
    fclose($handle);

    if ($magicBytes !== '%PDF') {
        http_response_code(415);
        echo json_encode(['error' => 'Format file tidak valid. Header dokumen bukan PDF.']);
        exit;
    }

    // 5. Tentukan nama periode untuk struktur folder
    $pdo = Database::getInstance();
    $periodeNama = 'default';
    $periodeId = 0;

    // Prioritas 1: Cari dari reservasi aktif mahasiswa
    $stmtRes = $pdo->prepare(
        "SELECT p.id, p.nama 
         FROM reservasi r 
         JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id 
         JOIN periode p ON upp.periode_id = p.id 
         WHERE r.mahasiswa_id = :mid AND r.status = 'ditahan' 
         ORDER BY r.id DESC LIMIT 1"
    );
    $stmtRes->execute([':mid' => Auth::getMahasiswaId()]);
    $resRow = $stmtRes->fetch(PDO::FETCH_ASSOC);

    if ($resRow && !empty($resRow['nama'])) {
        $periodeNama = (string)$resRow['nama'];
        $periodeId = (int)$resRow['id'];
    } else {
        // Prioritas 2: Periode yang sedang dibuka
        $stmtPer = $pdo->query("SELECT id, nama FROM periode WHERE status = 'dibuka' ORDER BY id DESC LIMIT 1");
        $perRow = $stmtPer->fetch(PDO::FETCH_ASSOC);
        if ($perRow && !empty($perRow['nama'])) {
            $periodeNama = (string)$perRow['nama'];
            $periodeId = (int)$perRow['id'];
        }
    }

    // Sanitasi nama periode agar aman untuk path folder
    $sanitizedPeriode = preg_replace('/[\/\\\\\s]+/', '_', trim($periodeNama));
    $sanitizedPeriode = preg_replace('/[^a-zA-Z0-9_\-]/', '', $sanitizedPeriode);
    if (empty($sanitizedPeriode)) {
        $sanitizedPeriode = 'periode_' . ($periodeId ?: 'general');
    }

    // Direktori target di storage/porto/<periode>
    $storageDir = $root . '/storage';
    $targetDir = $storageDir . '/porto/' . $sanitizedPeriode;

    if (!is_dir($targetDir)) {
        if (!mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            http_response_code(500);
            echo json_encode(['error' => 'Gagal membuat direktori penyimpanan Portofolio di server.']);
            exit;
        }
    }

    // Amankan folder storage dengan .htaccess jika belum ada
    $htaccessFile = $storageDir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        @file_put_contents($htaccessFile, "Require all denied\nDeny from all\n");
    }

    $fileName = $nim . '.pdf';
    $targetFilePath = $targetDir . '/' . $fileName;
    $relativeStoragePath = 'porto/' . $sanitizedPeriode . '/' . $fileName;

    // Pindahkan file uploaded
    if (!move_uploaded_file($tmpPath, $targetFilePath)) {
        http_response_code(500);
        echo json_encode(['error' => 'Gagal menyimpan file Portofolio ke server.']);
        exit;
    }

    // Simpan path ke session untuk verifikasi saat submit
    Auth::startSession(startPHP: true);
    $_SESSION['porto_temp_path'] = $relativeStoragePath;
    $_SESSION['porto_periode_id'] = $periodeId;

    echo json_encode([
        'ok' => true,
        'message' => 'Portofolio berhasil diunggah.',
        'path' => $relativeStoragePath,
        'filename' => (string)$file['name'],
        'size' => (int)$file['size']
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem saat memproses upload Portofolio.']);
}

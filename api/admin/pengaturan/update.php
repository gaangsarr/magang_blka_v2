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
Auth::requireAdminApi();
Auth::requireCsrfApi();

$admin = Auth::getAdmin();
if (!$admin || !in_array($admin['role'], ['super_admin', 'superadmin', 'admin_blka', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Akses ditolak. Fitur ini hanya untuk Super Admin dan Admin BLKA.']);
    exit;
}

$isSuperAdmin = ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin' || !empty($admin['is_super']));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Data pengaturan tidak valid.']);
    exit;
}

// Cek jika ada pengaturan email tetapi bukan Super Admin
$emailKeys = ['email_notifikasi_penolakan', 'email_notifikasi_pengumuman', 'email_notifikasi_submit'];
foreach ($emailKeys as $ekey) {
    if (array_key_exists($ekey, $body) && !$isSuperAdmin) {
        http_response_code(403);
        echo json_encode(['error' => 'Akses ditolak. Pengaturan notifikasi email hanya dapat diubah oleh Super Admin.']);
        exit;
    }
}

// Whitelist of allowed keys and their validation rules
$allowedKeys = [
    'min_ipk_5bulan'              => ['type' => 'decimal', 'min' => 0, 'max' => 4.00],
    'min_sks_5bulan'              => ['type' => 'integer', 'min' => 0, 'max' => 200],
    'email_notifikasi_penolakan'  => ['type' => 'boolean'],
    'email_notifikasi_pengumuman' => ['type' => 'boolean'],
    'email_notifikasi_submit'     => ['type' => 'boolean'],
    'doc_pedoman_tipe'            => ['type' => 'enum', 'values' => ['file', 'url']],
    'doc_pedoman_file'            => ['type' => 'string', 'max' => 255],
    'doc_pedoman_url'             => ['type' => 'url', 'max' => 500],
    'doc_pedoman_judul'           => ['type' => 'string', 'max' => 100],
    'doc_pedoman_meta'            => ['type' => 'string', 'max' => 100],
    'doc_pedoman_deskripsi'       => ['type' => 'string', 'max' => 1000],
    'doc_pedoman_badge'           => ['type' => 'string', 'max' => 30],
    'doc_pedoman_btn_text'        => ['type' => 'string', 'max' => 50],
    'doc_logbook_tipe'            => ['type' => 'enum', 'values' => ['file', 'url']],
    'doc_logbook_file'            => ['type' => 'string', 'max' => 255],
    'doc_logbook_url'             => ['type' => 'url', 'max' => 500],
    'doc_logbook_judul'           => ['type' => 'string', 'max' => 100],
    'doc_logbook_meta'            => ['type' => 'string', 'max' => 100],
    'doc_logbook_deskripsi'       => ['type' => 'string', 'max' => 1000],
    'doc_logbook_badge'           => ['type' => 'string', 'max' => 30],
    'doc_logbook_btn_text'        => ['type' => 'string', 'max' => 50],
];

try {
    $pdo = Database::getInstance();
    $updated = [];

    foreach ($body as $kunci => $nilai) {
        if (!isset($allowedKeys[$kunci])) {
            continue; // Skip unknown keys
        }

        $rule = $allowedKeys[$kunci];

        // Validate value
        if ($rule['type'] === 'decimal') {
            $val = (float) $nilai;
            if ($val < $rule['min'] || $val > $rule['max']) {
                http_response_code(400);
                echo json_encode(['error' => "Nilai '{$kunci}' harus antara {$rule['min']} dan {$rule['max']}."]);
                exit;
            }
            $nilai = number_format($val, 2, '.', '');
        } elseif ($rule['type'] === 'integer') {
            $val = (int) $nilai;
            if ($val < $rule['min'] || $val > $rule['max']) {
                http_response_code(400);
                echo json_encode(['error' => "Nilai '{$kunci}' harus antara {$rule['min']} dan {$rule['max']}."]);
                exit;
            }
            $nilai = (string) $val;
        } elseif ($rule['type'] === 'boolean') {
            $val = !empty($nilai) && in_array(strtolower((string)$nilai), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
            $nilai = $val;
        } elseif ($rule['type'] === 'enum') {
            $val = strtolower(trim((string)$nilai));
            if (!in_array($val, $rule['values'], true)) {
                http_response_code(400);
                echo json_encode(['error' => "Pilihan '{$kunci}' tidak valid."]);
                exit;
            }
            $nilai = $val;
        } elseif ($rule['type'] === 'url') {
            $val = trim((string)$nilai);
            if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                http_response_code(400);
                echo json_encode(['error' => "Format URL tautan pada '{$kunci}' tidak valid. Pastikan diawali https:// atau http://"]);
                exit;
            }
            $nilai = $val;
        } elseif ($rule['type'] === 'string') {
            $val = trim(strip_tags((string)$nilai));
            if (mb_strlen($val) > ($rule['max'] ?? 255)) {
                http_response_code(400);
                echo json_encode(['error' => "Teks '{$kunci}' melebihi batas maksimal {$rule['max']} karakter."]);
                exit;
            }
            $nilai = $val;
        }

        $stmt = $pdo->prepare("UPDATE pengaturan SET nilai = :nilai WHERE kunci = :kunci");
        $stmt->execute([':nilai' => $nilai, ':kunci' => $kunci]);
        $updated[] = $kunci;
    }

    if (empty($updated)) {
        http_response_code(400);
        echo json_encode(['error' => 'Tidak ada pengaturan yang valid untuk diperbarui.']);
        exit;
    }

    // Catat log aktivitas admin
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $adminId = $admin['id'] ?? 1;
    $detail = json_encode([
        'updated_keys' => $updated,
        'role'         => $admin['role'] ?? 'admin_blka',
    ], JSON_UNESCAPED_UNICODE);

    $stmtLog = $pdo->prepare("
        INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
        VALUES (?, 'update_pengaturan_sistem', 'pengaturan', NULL, ?, ?, NOW())
    ");
    $stmtLog->execute([$adminId, $detail, $ip]);

    echo json_encode([
        'ok'      => true,
        'message' => 'Pengaturan berhasil disimpan.',
        'updated' => $updated,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menyimpan pengaturan.')]);
}



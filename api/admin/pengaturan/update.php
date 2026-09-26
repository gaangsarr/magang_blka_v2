<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireSuperAdminApi();
Auth::requireCsrfApi();

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

// Whitelist of allowed keys and their validation rules
$allowedKeys = [
    'min_ipk_5bulan'              => ['type' => 'decimal', 'min' => 0, 'max' => 4.00],
    'min_sks_5bulan'              => ['type' => 'integer', 'min' => 0, 'max' => 200],
    'email_notifikasi_penolakan'  => ['type' => 'boolean'],
    'email_notifikasi_pengumuman' => ['type' => 'boolean'],
    'email_notifikasi_submit'     => ['type' => 'boolean'],
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

    echo json_encode([
        'ok'      => true,
        'message' => 'Pengaturan berhasil disimpan.',
        'updated' => $updated,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menyimpan pengaturan.')]);
}


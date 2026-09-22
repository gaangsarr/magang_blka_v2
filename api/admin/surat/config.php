<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\SuratGenerator;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

try {
    $pdo = Database::getInstance();

    // 1. Ambil seluruh list periode
    $stmtAll = $pdo->query("SELECT id, nama, status, program_1_bulan, program_3_bulan, program_4_bulan, program_5_bulan, pengumuman_dibuka FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status, program_1_bulan, program_3_bulan, program_4_bulan, program_5_bulan, pengumuman_dibuka FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        $stmtPeriode = $pdo->query("SELECT id, nama, status, program_1_bulan, program_3_bulan, program_4_bulan, program_5_bulan, pengumuman_dibuka FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    if (!$periode) {
        echo json_encode([
            'ok' => true,
            'periode_terpilih' => null,
            'all_periode' => [],
            'config' => null,
            'units' => [],
            'message' => 'Belum ada data periode magang.'
        ]);
        exit;
    }

    $periodeId = (int)$periode['id'];

    // 2. Ambil konfigurasi surat
    $config = SuratGenerator::getConfig($pdo, $periodeId);

    // 3. Ambil daftar unit yang ada mahasiswa diterima
    $units = SuratGenerator::getUnitsSummary($pdo, $periodeId);

    // Format nomor surat untuk masing-masing unit preview
    $startNum = (int)($config['nomor_surat_start'] ?? 1);
    foreach ($units as $idx => &$u) {
        $u['nomor_surat_preview'] = SuratGenerator::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $startNum + $idx);
    }

    echo json_encode([
        'ok' => true,
        'periode_terpilih' => $periode,
        'all_periode' => $allPeriode,
        'config' => $config,
        'units' => $units
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat konfigurasi surat.')]);
}

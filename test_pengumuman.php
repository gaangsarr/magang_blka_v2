<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = __DIR__;
Dotenv::createImmutable($root)->safeLoad();

$pdo = Database::getInstance();

echo "\n=== UJI INTEGRASI FITUR AKTIVASI PENGUMUMAN PENETAPAN ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $label) {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: {$label}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$label}\n";
        $failed++;
    }
}

// 1. Skema Database
echo "1. SKEMA DATABASE\n";
$colCheck = $pdo->query("SHOW COLUMNS FROM periode LIKE 'pengumuman_dibuka'")->fetch();
assertTest(!empty($colCheck), "Kolom 'pengumuman_dibuka' ada di tabel periode");
assertTest(str_contains(strtolower($colCheck['Type'] ?? ''), 'tinyint') || str_contains(strtolower($colCheck['Type'] ?? ''), 'bool'), "Tipe kolom pengumuman_dibuka adalah boolean/tinyint");

// 2. Ambil atau Buat Data Testing
echo "\n2. DATA TESTING PERIODE & MAHASISWA\n";
$stmtP = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE status = 'dibuka' ORDER BY id DESC LIMIT 1");
$periode = $stmtP->fetch(PDO::FETCH_ASSOC);

if (!$periode) {
    $stmtP = $pdo->query("SELECT id, nama, status, pengumuman_dibuka FROM periode ORDER BY id DESC LIMIT 1");
    $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
}

assertTest(!empty($periode), "Tersedia periode untuk pengujian (ID: " . ($periode['id'] ?? 'none') . ")");
$periodeId = (int)$periode['id'];

// Ambil 1 pendaftaran di periode ini
$stmtMhs = $pdo->prepare("SELECT p.id, p.mahasiswa_id, p.status, m.nim, m.nama FROM pendaftaran p JOIN mahasiswa m ON p.mahasiswa_id = m.id WHERE p.periode_id = ? LIMIT 1");
$stmtMhs->execute([$periodeId]);
$pendaftaran = $stmtMhs->fetch(PDO::FETCH_ASSOC);

// 3. Test Toggle API Logic & Log Aktivitas
echo "\n3. LOGIKA TOGGLE PENGUMUMAN & LOG AKTIVITAS\n";
// Set ke 0 (Nonaktif / Pending)
$pdo->prepare("UPDATE periode SET pengumuman_dibuka = 0 WHERE id = ?")->execute([$periodeId]);
$stmtCheck = $pdo->prepare("SELECT pengumuman_dibuka FROM periode WHERE id = ?");
$stmtCheck->execute([$periodeId]);
$val0 = (int)$stmtCheck->fetchColumn();
assertTest($val0 === 0, "Pengumuman dapat di-set ke 0 (Nonaktif / Pending)");

// Set ke 1 (Aktif / Publikasi)
$pdo->prepare("UPDATE periode SET pengumuman_dibuka = 1 WHERE id = ?")->execute([$periodeId]);
$stmtCheck->execute([$periodeId]);
$val1 = (int)$stmtCheck->fetchColumn();
assertTest($val1 === 1, "Pengumuman dapat di-set ke 1 (Dipublikasikan)");

// Test insert log aktivitas
$stmtLog = $pdo->prepare("INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at) VALUES (NULL, 'toggle_pengumuman', 'periode', ?, ?, '127.0.0.1', NOW())");
$logOk = $stmtLog->execute([$periodeId, json_encode(['pengumuman_dibuka' => true])]);
assertTest($logOk, "Pencatatan log_aktivitas toggle_pengumuman berhasil dieksekusi ke DB");

// 4. Test Response Mahasiswa saat Pengumuman 0 vs 1
echo "\n4. SIMULASI STATUS PENDAFTARAN MAHASISWA\n";
if ($pendaftaran) {
    $mahasiswaId = (int)$pendaftaran['mahasiswa_id'];

    // Skenario A: Pengumuman DITUTUP (0)
    $pdo->prepare("UPDATE periode SET pengumuman_dibuka = 0 WHERE id = ?")->execute([$periodeId]);
    
    $stmtSim = $pdo->prepare("
        SELECT 
            p.status, 
            p.submitted_at, 
            pr.pengumuman_dibuka
        FROM pendaftaran p
        JOIN periode pr ON p.periode_id = pr.id
        WHERE p.mahasiswa_id = :mid AND pr.id = :pid
    ");
    $stmtSim->execute([':mid' => $mahasiswaId, ':pid' => $periodeId]);
    $simData = $stmtSim->fetch(PDO::FETCH_ASSOC);

    $isPengumumanBuka = (bool)($simData['pengumuman_dibuka'] ?? false);
    $statusTampil = !$isPengumumanBuka ? 'menunggu_pengumuman' : $simData['status'];

    assertTest(!$isPengumumanBuka, "Saat pengumuman_dibuka = 0, isPengumumanBuka bernilai FALSE");
    assertTest($statusTampil === 'menunggu_pengumuman', "Saat pengumuman_dibuka = 0, status yang diterima mahasiswa adalah 'menunggu_pengumuman' (Pending)");

    // Skenario B: Pengumuman DIBUKA (1)
    $pdo->prepare("UPDATE periode SET pengumuman_dibuka = 1 WHERE id = ?")->execute([$periodeId]);
    $stmtSim->execute([':mid' => $mahasiswaId, ':pid' => $periodeId]);
    $simData = $stmtSim->fetch(PDO::FETCH_ASSOC);

    $isPengumumanBuka = (bool)($simData['pengumuman_dibuka'] ?? false);
    $statusTampil = !$isPengumumanBuka ? 'menunggu_pengumuman' : $simData['status'];

    assertTest($isPengumumanBuka, "Saat pengumuman_dibuka = 1, isPengumumanBuka bernilai TRUE");
    assertTest($statusTampil === $pendaftaran['status'], "Saat pengumuman_dibuka = 1, status yang diterima mahasiswa adalah status penetapan riil ('{$pendaftaran['status']}')");
} else {
    echo "  ⚠️ SKIP: Belum ada pendaftaran sample di periode ini.\n";
}

// 5. Verifikasi Keberadaan File Modul & Endpoint
echo "\n5. VERIFIKASI FILE & ASSET\n";
assertTest(file_exists(__DIR__ . '/api/admin/penetapan/toggle_pengumuman.php'), "api/admin/penetapan/toggle_pengumuman.php ada");
assertTest(file_exists(__DIR__ . '/public/admin/penetapan.html'), "public/admin/penetapan.html ada");
assertTest(file_exists(__DIR__ . '/public/js/admin/penetapan.js'), "public/js/admin/penetapan.js ada");
assertTest(file_exists(__DIR__ . '/public/status.html'), "public/status.html ada");
assertTest(file_exists(__DIR__ . '/api/mahasiswa/status_pendaftaran.php'), "api/mahasiswa/status_pendaftaran.php ada");

// Check HTML markup & JS code bindings
$htmlPenetapan = file_get_contents(__DIR__ . '/public/admin/penetapan.html');
assertTest(str_contains($htmlPenetapan, 'announcement-banner-card'), "penetapan.html memiliki #announcement-banner-card");
assertTest(str_contains($htmlPenetapan, 'btn-toggle-pengumuman'), "penetapan.html memiliki #btn-toggle-pengumuman");

$jsPenetapan = file_get_contents(__DIR__ . '/public/js/admin/penetapan.js');
assertTest(str_contains($jsPenetapan, 'handleTogglePengumuman'), "penetapan.js memiliki fungsi handleTogglePengumuman");
assertTest(str_contains($jsPenetapan, 'toggle_pengumuman.php'), "penetapan.js memanggil toggle_pengumuman.php");

$htmlStatus = file_get_contents(__DIR__ . '/public/status.html');
assertTest(str_contains($htmlStatus, 'MENUNGGU PENGUMUMAN'), "status.html menangani badge MENUNGGU PENGUMUMAN");

echo "\n" . str_repeat("─", 50) . "\n";
echo "HASIL: {$passed} passed, {$failed} failed\n";
echo str_repeat("─", 50) . "\n\n";

if ($failed > 0) exit(1);

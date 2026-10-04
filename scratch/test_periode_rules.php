<?php
/**
 * test_periode_rules.php
 * Script pengujian otomatis untuk aturan:
 * 1. Periode 'draft', 'ditutup', 'diarsipkan' tidak menampilkan data di dashboard
 * 2. Periode 'persiapan' dan 'dibuka' menampilkan data di dashboard
 * 3. Sistem hanya mengizinkan maksimal 1 periode aktif ('persiapan' atau 'dibuka')
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_id'] = 1;
$_SESSION['admin_nama'] = 'Super Admin BLKA';
$_SESSION['admin_role'] = 'super_admin';
$_SESSION['role'] = 'admin';

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__)->safeLoad();

echo "\n=== UJI INTEGRASI ATURAN PERIODE & FILTER DASHBOARD ===\n\n";

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: {$message}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$message}\n";
        $failed++;
    }
}

$pdo = Database::getInstance();

// Backup status periode yang ada
$stmtBackup = $pdo->query("SELECT id, status FROM periode");
$originalStatuses = $stmtBackup->fetchAll(PDO::FETCH_KEY_PAIR);

try {
    $admin = $pdo->query("SELECT id, email, nama FROM admin LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $adminEmail = $admin['email'];
    $testPass = 'admin123';
    $pdo->prepare("UPDATE admin SET password_hash = ? WHERE id = ?")->execute([
        password_hash($testPass, PASSWORD_BCRYPT),
        $admin['id']
    ]);

    $cookieJar = tempnam(sys_get_temp_dir(), 'intern_rules_cookie');

    // Login via API
    $ch = curl_init('http://127.0.0.1:8001/api/admin/auth.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $adminEmail, 'password' => $testPass]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar
    ]);
    $loginResp = curl_exec($ch);
    $loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $loginData = json_decode($loginResp, true);
    assertTest($loginCode === 200 && !empty($loginData['ok']), 'Login admin via API berhasil');

    // 1. SET SEMUA PERIODE KE 'draft' / 'ditutup'
    $pdo->query("UPDATE periode SET status = 'draft'");

    $ch = curl_init('http://127.0.0.1:8001/api/admin/dashboard.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar
    ]);
    $dashResp = curl_exec($ch);
    $dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($dashResp, true);
    assertTest($dashCode === 200, 'Dashboard API merespon HTTP 200 OK');
    assertTest(isset($data['ok']) && $data['ok'] === true, 'Response dashboard status ok = true');
    assertTest($data['periode'] === null, 'Periode bernilai NULL saat semua status draft/ditutup');
    assertTest($data['data']['total_pendaftar'] === 0, 'Total pendaftar = 0 (data tidak bocor)');
    assertTest($data['data']['total_kuota'] === 0, 'Total kuota = 0 saat tidak ada periode aktif');

    // 2. UJI SINGLE ACTIVE PERIOD RULE
    $periodeIds = array_keys($originalStatuses);
    if (count($periodeIds) >= 2) {
        $p1 = $periodeIds[0];
        $p2 = $periodeIds[1];

        // A. Update Periode 1 -> 'persiapan'
        $chStatus = curl_init('http://127.0.0.1:8001/api/admin/periode/update_status.php');
        curl_setopt_array($chStatus, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['id' => $p1, 'status' => 'persiapan']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar
        ]);
        $resP1 = curl_exec($chStatus);
        $codeP1 = curl_getinfo($chStatus, CURLINFO_HTTP_CODE);
        curl_close($chStatus);
        assertTest($codeP1 === 200, 'API update_status Periode 1 -> persiapan berhasil (200 OK)');

        $stmtCek = $pdo->prepare("SELECT status FROM periode WHERE id = ?");
        $stmtCek->execute([$p1]);
        assertTest($stmtCek->fetchColumn() === 'persiapan', 'Periode 1 berstatus persiapan di DB');

        // Dashboard sekarang HARUS mendeteksi p1 (status: persiapan)
        $ch = curl_init('http://127.0.0.1:8001/api/admin/dashboard.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar
        ]);
        $dashResp = curl_exec($ch);
        $dashCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($dashResp, true);
        assertTest(!empty($data['periode']) && $data['periode']['id'] == $p1, 'Dashboard menampilkan data periode persiapan yang aktif');

        // B. Ubah Periode 2 -> 'dibuka'
        $chStatus = curl_init('http://127.0.0.1:8001/api/admin/periode/update_status.php');
        curl_setopt_array($chStatus, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['id' => $p2, 'status' => 'dibuka']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar
        ]);
        $resP2 = curl_exec($chStatus);
        $codeP2 = curl_getinfo($chStatus, CURLINFO_HTTP_CODE);
        curl_close($chStatus);
        assertTest($codeP2 === 200, 'API update_status Periode 2 -> dibuka berhasil (200 OK)');

        // Periksa: Periode 2 harus 'dibuka', dan Periode 1 HARUS OTOMATIS 'ditutup'
        $stmtCek->execute([$p2]);
        assertTest($stmtCek->fetchColumn() === 'dibuka', 'Periode 2 berstatus dibuka di DB');

        $stmtCek->execute([$p1]);
        assertTest($stmtCek->fetchColumn() === 'ditutup', 'Periode 1 otomatis ditutup saat Periode 2 dibuka (Single Active Rule)');

        // Dashboard sekarang HARUS mendeteksi p2 (status: dibuka)
        $ch = curl_init('http://127.0.0.1:8001/api/admin/dashboard.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar
        ]);
        $dashResp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($dashResp, true);
        assertTest(!empty($data['periode']) && $data['periode']['id'] == $p2, 'Dashboard menampilkan data periode dibuka yang baru');

        // C. Ubah Periode 1 -> 'persiapan' lagi
        $chStatus = curl_init('http://127.0.0.1:8001/api/admin/periode/update_status.php');
        curl_setopt_array($chStatus, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['id' => $p1, 'status' => 'persiapan']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_COOKIEJAR => $cookieJar,
            CURLOPT_COOKIEFILE => $cookieJar
        ]);
        $resP1 = curl_exec($chStatus);
        $codeP1 = curl_getinfo($chStatus, CURLINFO_HTTP_CODE);
        curl_close($chStatus);
        assertTest($codeP1 === 200, 'API update_status Periode 1 -> persiapan berhasil (200 OK)');

        // Periksa: Periode 1 harus 'persiapan', dan Periode 2 HARUS OTOMATIS 'ditutup'
        $stmtCek->execute([$p1]);
        assertTest($stmtCek->fetchColumn() === 'persiapan', 'Periode 1 kembali berstatus persiapan');

        $stmtCek->execute([$p2]);
        assertTest($stmtCek->fetchColumn() === 'ditutup', 'Periode 2 otomatis ditutup saat Periode 1 masuk persiapan (Single Active Rule)');
    }

    if (file_exists($cookieJar)) @unlink($cookieJar);

} finally {
    // Kembalikan status periode ke semula
    foreach ($originalStatuses as $id => $st) {
        $stmtRestore = $pdo->prepare("UPDATE periode SET status = ? WHERE id = ?");
        $stmtRestore->execute([$st, $id]);
    }
}

echo "\n──────────────────────────────────────────────────\n";
echo "HASIL: $passed passed, $failed failed\n";
echo "──────────────────────────────────────────────────\n\n";

exit($failed > 0 ? 1 : 0);

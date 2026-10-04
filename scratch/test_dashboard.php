<?php
/**
 * test_dashboard.php
 * Script pengujian otomatis untuk Dashboard Eksekutif Admin INTERN ITPLN
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

$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ PASS: {$label}\n";
        $passed++;
    } else {
        echo "  ❌ FAIL: {$label}\n";
        $failed++;
    }
}

echo "=== UJI INTEGRASI EXECUTIVE DASHBOARD INTERN ITPLN ===\n\n";

echo "1. VERIFIKASI ASSET FRONTEND & BACKEND\n";
assertTest(file_exists(__DIR__ . '/public/admin/index.html'), 'public/admin/index.html ada');
assertTest(file_exists(__DIR__ . '/public/js/admin/dashboard.js'), 'public/js/admin/dashboard.js ada');
assertTest(file_exists(__DIR__ . '/api/admin/dashboard.php'), 'api/admin/dashboard.php ada');
assertTest(file_exists(__DIR__ . '/api/admin/dashboard_export.php'), 'api/admin/dashboard_export.php ada');

$html = file_get_contents(__DIR__ . '/public/admin/index.html');
assertTest(strpos($html, 'btn-print-dashboard') !== false, 'index.html memiliki tombol Cetak Laporan');
assertTest(strpos($html, 'btn-export-dashboard') !== false, 'index.html memiliki tombol Export Excel');
assertTest(strpos($html, 'executive-banner') !== false, 'index.html memiliki Banner Eksekutif Periode');
assertTest(strpos($html, 'tbody-unit-penuh') !== false, 'index.html memiliki tabel Unit Kuota Penuh');
assertTest(strpos($html, 'tbody-unit-under') !== false, 'index.html memiliki tabel Rekomendasi Relokasi');
assertTest(strpos($html, 'hierarkiChart') !== false, 'index.html memiliki chart Hierarki PLN');
assertTest(strpos($html, 'peminatanChart') !== false, 'index.html memiliki chart Distribusi Peminatan');
assertTest(strpos($html, 'jurusanChart') !== false, 'index.html memiliki chart Pendaftar Jurusan');

echo "\n2. UJI ENDPOINT API DASHBOARD VIA HTTP DEV SERVER\n";

$pdo = Database::getInstance();
$admin = $pdo->query("SELECT id, email, nama FROM admin LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    // Update password hash to known password for testing
    $testPass = 'admin123';
    $pdo->prepare("UPDATE admin SET password_hash = ? WHERE id = ?")->execute([
        password_hash($testPass, PASSWORD_BCRYPT),
        $admin['id']
    ]);
    $adminEmail = $admin['email'];
} else {
    $adminEmail = 'admin@itpln.ac.id';
    $testPass = 'admin123';
    $pdo->prepare("INSERT INTO admin (nama, email, password_hash, role) VALUES (?, ?, ?, ?)")->execute([
        'Super Admin', $adminEmail, password_hash($testPass, PASSWORD_BCRYPT), 'super_admin'
    ]);
}

$cookieJar = tempnam(sys_get_temp_dir(), 'intern_cookie');

// Login Admin
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

// Fetch Dashboard Data
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
assertTest($dashCode === 200, 'HTTP status code 200 OK');
assertTest(is_array($data), 'API mengembalikan output JSON valid');
assertTest(isset($data['ok']) && $data['ok'] === true, 'Response API status ok = true');
assertTest(array_key_exists('periode', $data), 'Response memiliki key periode');
assertTest(isset($data['data']['total_pendaftar']), 'Response memiliki data total_pendaftar');
assertTest(isset($data['data']['total_kuota']), 'Response memiliki data total_kuota');
assertTest(isset($data['data']['sisa_kuota']), 'Response memiliki data sisa_kuota');
assertTest(isset($data['data']['persen_okupansi']), 'Response memiliki data persen_okupansi');
assertTest(isset($data['data']['status_counts']['diterima']), 'Response memiliki breakdown status diterima');
assertTest(isset($data['data']['status_counts']['dipindahkan']), 'Response memiliki breakdown status dipindahkan (relokasi)');
assertTest(isset($data['data']['status_counts']['menunggu']), 'Response memiliki breakdown status menunggu');
assertTest(isset($data['data']['status_counts']['ditolak']), 'Response memiliki breakdown status ditolak');
assertTest(isset($data['data']['unit_penuh']) && is_array($data['data']['unit_penuh']), 'Response memiliki data array unit_penuh');
assertTest(isset($data['data']['unit_butuh_mahasiswa']) && is_array($data['data']['unit_butuh_mahasiswa']), 'Response memiliki data array unit_butuh_mahasiswa');
assertTest(isset($data['data']['sebaran_hierarki']) && is_array($data['data']['sebaran_hierarki']), 'Response memiliki data array sebaran_hierarki');
assertTest(isset($data['data']['sebaran_peminatan']) && is_array($data['data']['sebaran_peminatan']), 'Response memiliki data array sebaran_peminatan');
assertTest(isset($data['data']['sebaran_jurusan']) && is_array($data['data']['sebaran_jurusan']), 'Response memiliki data array sebaran_jurusan');

// Test Dashboard Export
$ch = curl_init('http://127.0.0.1:8001/api/admin/dashboard_export.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookieJar,
    CURLOPT_COOKIEFILE => $cookieJar
]);
$exportResp = curl_exec($ch);
$exportCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

assertTest($exportCode === 200, 'HTTP status export code 200 OK');
assertTest(strpos($exportResp, 'INTERN ITPLN — SISTEM MAGANG TERPADU') !== false, 'Export CSV memuat header resmi INTERN ITPLN');
assertTest(strpos($exportResp, 'INDIKATOR UTAMA (KPI)') !== false, 'Export CSV memuat bagian KPI');

@unlink($cookieJar);

echo "\n──────────────────────────────────────────────────\n";
echo "HASIL: {$passed} passed, {$failed} failed\n";
echo "──────────────────────────────────────────────────\n";

if ($failed > 0) exit(1);

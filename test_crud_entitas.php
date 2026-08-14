<?php
/**
 * Test script untuk verifikasi modul hierarki entitas PLN dan magang di semua level.
 * Jalankan: php test_crud_entitas.php
 */

require_once __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__)->safeLoad();

$pdo = Database::getInstance();

echo "\n=== VERIFIKASI MODUL HIERARKI PLN & MAGANG SEMUA LEVEL (v2) ===\n\n";

$pass = 0;
$fail = 0;

function test(string $label, bool $result, string $detail = ''): void {
    global $pass, $fail;
    if ($result) {
        echo "  ✅ PASS: $label\n";
        $pass++;
    } else {
        echo "  ❌ FAIL: $label" . ($detail ? " — $detail" : '') . "\n";
        $fail++;
    }
}

// ── 1. Verifikasi Skema Database ─────────────────────────────────────────────
echo "1. SKEMA DATABASE\n";

$stmt = $pdo->query("SHOW COLUMNS FROM entitas_perusahaan LIKE 'menerima_magang'");
$hasCol = $stmt->fetch(PDO::FETCH_ASSOC);
test("Kolom menerima_magang ada di entitas_perusahaan", !empty($hasCol));

$stmt = $pdo->query("SHOW COLUMNS FROM periode WHERE Field = 'status'");
$statusCol = $stmt->fetch(PDO::FETCH_ASSOC);
test("Enum status periode memiliki 'persiapan'", strpos($statusCol['Type'], "'persiapan'") !== false);

// ── 2. Verifikasi Data Hierarki & Seeder ──────────────────────────────────────
echo "\n2. DATA HIERARKI & SEEDER\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan");
$total = (int)$stmt->fetchColumn();
test("Total entitas terdaftar >= 12", $total >= 12, "Got: $total");

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'holding'");
test("Holding terdaftar", (int)$stmt->fetchColumn() >= 1);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'unit_pelaksana'");
test("Unit Pelaksana terdaftar", (int)$stmt->fetchColumn() >= 7);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE menerima_magang = 1");
$totalMagang = (int)$stmt->fetchColumn();
test("Ada entitas dengan menerima_magang = 1", $totalMagang > 0, "Got: $totalMagang");

// ── 3. Verifikasi File Frontend Baru ──────────────────────────────────────────
echo "\n3. FILE FRONTEND & MODUL\n";

test("holding.html ada",          file_exists(__DIR__ . '/public/admin/holding.html'));
test("subholding.html ada",       file_exists(__DIR__ . '/public/admin/subholding.html'));
test("anak-perusahaan.html ada",  file_exists(__DIR__ . '/public/admin/anak-perusahaan.html'));
test("unit-induk.html ada",       file_exists(__DIR__ . '/public/admin/unit-induk.html'));
test("unit-pelaksana.html ada",   file_exists(__DIR__ . '/public/admin/unit-pelaksana.html'));
test("peminatan.html ada",        file_exists(__DIR__ . '/public/admin/peminatan.html'));
test("hierarki.js ada",           file_exists(__DIR__ . '/public/js/admin/hierarki.js'));
test("peminatan.js ada",          file_exists(__DIR__ . '/public/js/admin/peminatan.js'));

// ── 4. Verifikasi Logika API ─────────────────────────────────────────────────
echo "\n4. VERIFIKASI LOGIKA FILE API\n";

$createContent = file_get_contents(__DIR__ . '/api/admin/entitas/create.php');
test("create.php: validasi menerima_magang", strpos($createContent, "menerima_magang") !== false);
test("create.php: validasi peminatan jika menerima_magang = 1", strpos($createContent, "menerimaMagang === 1 && empty(\$peminatanIds)") !== false);

$updateContent = file_get_contents(__DIR__ . '/api/admin/entitas/update.php');
test("update.php: update menerima_magang", strpos($updateContent, "menerima_magang") !== false);
test("update.php: validasi peminatan saat menerima_magang diaktifkan", strpos($updateContent, "menerimaMagang === 1") !== false);

$listContent = file_get_contents(__DIR__ . '/api/admin/entitas/list.php');
test("list.php: select menerima_magang", strpos($listContent, "menerima_magang") !== false);
test("list.php: filter ?menerima_magang", strpos($listContent, "filterMagang") !== false);

$adminUnitList = file_get_contents(__DIR__ . '/api/admin/unit/list.php');
test("admin/unit/list.php: filter menerima_magang = 1", strpos($adminUnitList, "ep.menerima_magang = 1") !== false);
test("admin/unit/list.php: TIDAK hardcode ep.tipe = unit_pelaksana", strpos($adminUnitList, "ep.tipe = 'unit_pelaksana'") === false);

$deleteContent = file_get_contents(__DIR__ . '/api/admin/entitas/delete.php');
test("delete.php: soft-delete dengan cek reservasi aktif", strpos($deleteContent, "reservasiAktif") !== false);

// ── 5. Verifikasi CSS & Sidebar ──────────────────────────────────────────────
echo "\n5. VERIFIKASI CSS & SIDEBAR\n";

$cssContent = file_get_contents(__DIR__ . '/public/css/admin.css');
test("admin.css: nav-group styles", strpos($cssContent, ".nav-group") !== false);
test("admin.css: switch toggle styles", strpos($cssContent, ".switch") !== false);
test("admin.css: table group header styles", strpos($cssContent, ".table-group-header") !== false);

// Spot check modular sidebar di sidebar.js & placeholder di halaman
$sidebarJs = file_get_contents(__DIR__ . '/public/js/admin/sidebar.js');
test("sidebar.js: punya nested menu Hierarki Entitas PLN", strpos($sidebarJs, "Hierarki") !== false);
test("sidebar.js: punya link ke holding.html", strpos($sidebarJs, "holding.html") !== false);
test("sidebar.js: punya link ke peminatan.html", strpos($sidebarJs, "peminatan.html") !== false);

$unitHtml = file_get_contents(__DIR__ . '/public/admin/unit.html');
test("unit.html: menggunakan dynamic sidebar container", strpos($unitHtml, 'id="admin-sidebar"') !== false || strpos($unitHtml, 'class="admin-sidebar"') !== false);

$dashboardHtml = file_get_contents(__DIR__ . '/public/admin/index.html');
test("index.html: menggunakan dynamic sidebar container", strpos($dashboardHtml, 'id="admin-sidebar"') !== false || strpos($dashboardHtml, 'class="admin-sidebar"') !== false);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "HASIL: $pass passed, $fail failed\n";
echo str_repeat('─', 50) . "\n\n";

exit($fail > 0 ? 1 : 0);

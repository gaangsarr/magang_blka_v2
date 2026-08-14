<?php
/**
 * Test script untuk verifikasi perbaikan CRUD entitas v2.
 * Jalankan: php test_crud_entitas.php
 */

require_once __DIR__ . '/vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__)->safeLoad();

$pdo = Database::getInstance();

echo "\n=== VERIFIKASI PERBAIKAN CRUD ENTITAS v2 ===\n\n";

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

// ── 1. Verifikasi data hierarki ──────────────────────────────────────────────
echo "1. DATA HIERARKI\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan");
$total = (int)$stmt->fetchColumn();
test("Total entitas = 12", $total === 12, "Got: $total");

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'holding'");
test("1 holding", (int)$stmt->fetchColumn() === 1);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'anak_perusahaan'");
test("1 anak_perusahaan", (int)$stmt->fetchColumn() === 1);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'unit_induk'");
test("3 unit_induk", (int)$stmt->fetchColumn() === 3);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe = 'unit_pelaksana'");
test("7 unit_pelaksana", (int)$stmt->fetchColumn() === 7);

// ── 2. Verifikasi parent-child relationship ──────────────────────────────────
echo "\n2. PARENT-CHILD RELATIONSHIP\n";

$stmt = $pdo->query("SELECT parent_id FROM entitas_perusahaan WHERE tipe = 'holding'");
$holdingParent = $stmt->fetchColumn();
test("Holding parent_id = NULL", $holdingParent === false || $holdingParent === null);

$stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan WHERE tipe != 'holding' AND parent_id IS NULL");
test("Non-holding semua punya parent", (int)$stmt->fetchColumn() === 0);

// Cek unit_pelaksana hanya parent ke unit_induk
$stmt = $pdo->query("
    SELECT COUNT(*) FROM entitas_perusahaan e
    JOIN entitas_perusahaan p ON e.parent_id = p.id
    WHERE e.tipe = 'unit_pelaksana' AND p.tipe != 'unit_induk'
");
test("Unit pelaksana parent = unit_induk", (int)$stmt->fetchColumn() === 0);

// ── 3. Verifikasi unit_peminatan ─────────────────────────────────────────────
echo "\n3. UNIT PEMINATAN\n";

$stmt = $pdo->query("SELECT COUNT(*) FROM unit_peminatan");
$totalPem = (int)$stmt->fetchColumn();
test("Total relasi unit_peminatan = 16", $totalPem === 16, "Got: $totalPem");

// Cek semua unit_pelaksana punya peminatan
$stmt = $pdo->query("
    SELECT e.nama FROM entitas_perusahaan e
    WHERE e.tipe = 'unit_pelaksana'
    AND e.id NOT IN (SELECT DISTINCT entitas_id FROM unit_peminatan)
");
$missing = $stmt->fetchAll(PDO::FETCH_COLUMN);
test("Semua unit_pelaksana punya peminatan", empty($missing), "Missing: " . implode(', ', $missing));

// ── 4. Verifikasi file API ada ───────────────────────────────────────────────
echo "\n4. FILE API\n";

test("create.php ada", file_exists(__DIR__ . '/api/admin/entitas/create.php'));
test("update.php ada", file_exists(__DIR__ . '/api/admin/entitas/update.php'));
test("list.php ada",   file_exists(__DIR__ . '/api/admin/entitas/list.php'));
test("delete.php ada", file_exists(__DIR__ . '/api/admin/entitas/delete.php'));

// ── 5. Verifikasi isi file (spot check) ──────────────────────────────────────
echo "\n5. SPOT CHECK ISI FILE\n";

$createContent = file_get_contents(__DIR__ . '/api/admin/entitas/create.php');
test("create.php: punya validasi tipe", strpos($createContent, "validTipe") !== false);
test("create.php: punya parent_id", strpos($createContent, "parent_id") !== false);
test("create.php: punya parentRules", strpos($createContent, "parentRules") !== false);
test("create.php: TIDAK hardcode unit_pelaksana di INSERT", strpos($createContent, "VALUES ('unit_pelaksana'") === false);

$updateContent = file_get_contents(__DIR__ . '/api/admin/entitas/update.php');
test("update.php: TIDAK hardcode tipe di WHERE", strpos($updateContent, "AND tipe = 'unit_pelaksana'") === false);
test("update.php: punya singkatan", strpos($updateContent, "singkatan") !== false);

$listContent = file_get_contents(__DIR__ . '/api/admin/entitas/list.php');
test("list.php: TIDAK filter hardcode unit_pelaksana", strpos($listContent, "WHERE e.tipe = 'unit_pelaksana'") === false);
test("list.php: punya nama_parent", strpos($listContent, "nama_parent") !== false);
test("list.php: punya filter tipe opsional", strpos($listContent, "filterTipe") !== false);

$deleteContent = file_get_contents(__DIR__ . '/api/admin/entitas/delete.php');
test("delete.php: soft-delete (aktif = 0)", strpos($deleteContent, "SET aktif = 0") !== false);
test("delete.php: cek child node", strpos($deleteContent, "parent_id") !== false);
test("delete.php: cek reservasi aktif", strpos($deleteContent, "reservasi") !== false);

$unitListContent = file_get_contents(__DIR__ . '/api/admin/unit/list.php');
test("unit/list.php: filter unit_pelaksana", strpos($unitListContent, "ep.tipe = 'unit_pelaksana'") !== false);
test("unit/list.php: punya nama_unit_induk", strpos($unitListContent, "nama_unit_induk") !== false);

// ── Summary ──────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "HASIL: $pass passed, $fail failed\n";
echo str_repeat('─', 50) . "\n\n";

exit($fail > 0 ? 1 : 0);

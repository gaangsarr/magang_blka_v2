<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use PhpOffice\PhpSpreadsheet\IOFactory;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$pdo = Database::getInstance();

echo "=== Seeding Master PIC Narahubung dari Sheet HCBP & SHAP (DATA PIC MAGANG.xlsx) ===\n\n";

function normalizePhone(?string $phone): string {
    if (empty($phone)) return '';
    $phone = trim((string)$phone);
    if (is_numeric($phone) && (stripos($phone, 'e') !== false)) {
        $phone = sprintf('%.0f', (float)$phone);
    }
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strpos($phone, '62') === 0) {
        $phone = '0' . substr($phone, 2);
    } elseif (strpos($phone, '8') === 0) {
        $phone = '0' . $phone;
    }
    return $phone;
}

$excelPath = dirname(__DIR__) . '/docs/DATA PIC MAGANG.xlsx';
if (!file_exists($excelPath)) {
    die("Error: File '$excelPath' tidak ditemukan.\n");
}

$reader = IOFactory::createReaderForFile($excelPath);
$spreadsheet = $reader->load($excelPath);

$stmtInsert = $pdo->prepare("
    INSERT INTO pic_narahubung (entitas_id, area_hcbp, nama_pic, no_wa, email, keterangan, aktif)
    VALUES (:entitas_id, :area_hcbp, :nama_pic, :no_wa, :email, :keterangan, 1)
    ON DUPLICATE KEY UPDATE
        area_hcbp = VALUES(area_hcbp),
        nama_pic = VALUES(nama_pic),
        no_wa = VALUES(no_wa),
        email = VALUES(email),
        keterangan = VALUES(keterangan),
        aktif = 1
");

// -------------------------------------------------------------
// 1. PROCESS SHEET 1: HCBP (Holding, Unit Induk, Pusat)
// -------------------------------------------------------------
echo "--- Memproses Sheet 1: HCBP ---\n";

$hcbpMapping = [
    'Kantor Pusat' => 1,
    'Pusat Sertifikasi (PUSERTIF)' => 14,
    'Pusat Penelitian Dan Pengembangan Ketenagalistrikan (PUSLITBANG)' => 16,
    'Pusat Pendidikan Dan Pelatihan (PUSDIKLAT)' => 13,
    'Pusat Pemeliharaan Ketenagalistrikan (PUSHARLIS)' => 15,
    'Pusat Manajemen Proyek (PUSMANPRO)' => 17,
    
    // UIK
    'Unit Induk Pembangkitan (UIK) Tanjung Jati B' => 50,
    'Unit Induk Pembangkitan (UIK) Dwipantara' => 1296,

    // UIP2B / UIP3B
    'Unit Induk Pusat Pengatur Beban (UIP2B) Jawa, Madura, dan Bali' => 62,
    'Unit Induk Penyaluran Dan Pusat Pengatur Beban (UIP3B) Sumatera' => 65,
    'Unit Induk Penyaluran Dan Pusat Pengatur Beban (UIP3B) Kalimantan' => 63,
    'Unit Induk Penyaluran Dan Pusat Pengatur Beban (UIP3B) Sulawesi' => 64,

    // UIT
    'Unit Induk Transmisi (UIT) Jawa Bagian Barat' => 66,
    'Unit Induk Transmisi (UIT) Jawa Bagian Tengah' => 68,
    'Unit Induk Transmisi (UIT) Jawa Bagian Timur dan Bali' => 67,

    // UIP
    'Unit Induk Pembangunan (UIP) Sumatera Bagian Utara' => 60,
    'Unit Induk Pembangunan (UIP) Sumatera Bagian Tengah' => 59,
    'Unit Induk Pembangunan (UIP) Sumatera Bagian Selatan' => 58,
    'Unit Induk Pembangunan (UIP) Jawa Bagian Barat' => 51,
    'Unit Induk Pembangunan (UIP) Jawa Bagian Tengah' => 52,
    'Unit Induk Pembangunan (UIP) Jawa Bagian Timur dan Bali' => 53,
    'Unit Induk Pembangunan (UIP) Kalimantan Bagian Barat' => 54,
    'Unit Induk Pembangunan (UIP) Kalimantan Bagian TImur' => 55,
    'Unit Induk Pembangunan (UIP) Nusa Tenggara' => 57,
    'Unit Induk Pembangunan (UIP) Sulawesi' => 61,
    'Unit Induk Pembangunan (UIP) Maluku dan Papua' => 56,

    // UID / UIW
    'Unit Induk Distribusi (UID) Aceh' => 28,
    'Unit Induk Distribusi (UID) Sumatera Utara' => 44,
    'Unit Induk Distribusi (UID) Sumatera Barat' => 43,
    'Unit Induk Distribusi (UID) Riau dan Kepulauan Riau' => 39,
    'Unit Induk Distribusi (UID) Sumatera Selatan, Jambi, dan Bengkulu' => 40,
    'Unit Induk Distribusi (UID) Lampung' => 38,
    'Unit Induk Wilayah (UIW) Bangka Belitung' => 45,
    'Unit Induk Distribusi (UID) Kalimantan Barat' => 35,
    'Unit Induk Distribusi (UID) Kalimantan Selatan dan Kalimantan Tengah' => 36,
    'Unit Induk Distribusi (UID) Kalimantan Timur dan Kalimantan Utara' => 37,
    'Unit Induk Distribusi (UID) Banten' => 30,
    'Unit Induk Distribusi (UID) Jakarta Raya' => 34,
    'Unit Induk Distribusi (UID) Jawa Barat' => 31,
    'Unit Induk Distribusi (UID) Jawa Tengah' => 32,
    'Unit Induk Distribusi (UID) Yogyakarta' => 32,
    'Unit Induk Distribusi (UID) Jawa Timur' => 33,
    'Unit Induk Distribusi (UID) Bali' => 29,
    'Unit Induk Wilayah (UIW) Nusa Tenggara Barat' => 47,
    'Unit Induk Wilayah (UIW) Nusa Tenggara Timur' => 48,
    'Unit Induk Distribusi (UID) Sulawesi Utara, Sulawesi Tengah, dan Gorontalo' => 42,
    'Unit Induk Distribusi (UID) Sulawesi Selatan, Sulawesi Tenggara, dan Sulawesi Barat' => 41,
    'Unit Induk Wilayah (UIW) Maluku dan Maluku Utara' => 46,
    'Unit Induk Wilayah (UIW) Papua dan Papua Barat' => 49
];

$sheetHcbp = $spreadsheet->getSheetByName('HCBP');
$hcbpRows = $sheetHcbp->toArray();
$currentArea = '';
$hcbpCount = 0;

for ($i = 2; $i < count($hcbpRows); $i++) {
    $row = $hcbpRows[$i];
    $areaCell = trim($row[0] ?? '');
    $unitCell = trim($row[1] ?? '');
    $namaCell = trim($row[2] ?? '');
    $hpCell   = trim($row[3] ?? '');

    if (!empty($areaCell)) {
        $currentArea = $areaCell;
    }

    if (empty($unitCell)) {
        continue;
    }

    $matchedEntitasId = $hcbpMapping[$unitCell] ?? null;
    if (!$matchedEntitasId) {
        continue;
    }

    $finalName = !empty($namaCell) ? $namaCell : "HC / SDM " . strtoupper($unitCell);
    $finalPhone = normalizePhone($hpCell);

    $stmtInsert->execute([
        ':entitas_id' => $matchedEntitasId,
        ':area_hcbp'  => $currentArea ?: 'HCBP Kantor Pusat',
        ':nama_pic'   => $finalName,
        ':no_wa'      => $finalPhone ?: null,
        ':email'      => null,
        ':keterangan' => null
    ]);
    $hcbpCount++;
    echo "  [HCBP] Terimpor: $unitCell -> Entitas ID $matchedEntitasId (PIC: $finalName, WA: $finalPhone)\n";
}

// -------------------------------------------------------------
// 2. PROCESS SHEET 2: SHAP (Subholding & Anak Perusahaan)
// -------------------------------------------------------------
echo "\n--- Memproses Sheet 2: SHAP (Subholding & Anak Perusahaan) ---\n";

$shapMapping = [
    'PLN INDONESIA POWER' => ['id' => 19, 'default_name' => 'HC / SDM PLN Indonesia Power'],
    'PLN NUSANTARA POWER' => ['id' => 18, 'default_name' => 'HC / SDM PLN Nusantara Power'],
    'PLN ICON PLUS'       => ['id' => 21, 'default_name' => 'HC / SDM PLN Icon Plus'],
    'PLN EPI'             => ['id' => 20, 'default_name' => 'HC / SDM PLN Energi Primer Indonesia'],
    'PLN ENJINIRING'      => ['id' => 25, 'default_name' => 'HC / SDM PLN Enjiniring'],
    'PLN BATAM'           => ['id' => 22, 'default_name' => 'HC / SDM PLN Batam'],
    'PLN ES'              => ['id' => 24, 'default_name' => 'HC / SDM PT Haleyora Power (Electricity Services)'],
    'PT EMI'              => ['id' => 26, 'default_name' => 'HC / SDM PT Energi Manajemen Indonesia'],
    'PT MCTN'             => ['id' => 27, 'default_name' => 'HC / SDM PT Mandau Cipta Tenaga Nusantara'],
    'PLN TARAKAN'         => ['id' => 23, 'default_name' => 'HC / SDM PT PLN Tarakan']
];

$sheetShap = $spreadsheet->getSheetByName('SHAP');
$shapRows = $sheetShap ? $sheetShap->toArray() : [];
$shapCount = 0;

for ($i = 2; $i < count($shapRows); $i++) {
    $row = $shapRows[$i];
    $shName   = trim($row[1] ?? '');
    $namaCell = trim($row[2] ?? '');
    $hpCell   = trim($row[3] ?? '');

    if (empty($shName)) continue;

    $map = $shapMapping[$shName] ?? null;
    if (!$map) {
        continue;
    }

    $entitasId = $map['id'];
    $finalName = !empty($namaCell) ? $namaCell : $map['default_name'];
    $finalPhone = normalizePhone($hpCell);

    $stmtInsert->execute([
        ':entitas_id' => $entitasId,
        ':area_hcbp'  => 'HCBP Subholding & Anak Perusahaan',
        ':nama_pic'   => $finalName,
        ':no_wa'      => $finalPhone ?: null,
        ':email'      => null,
        ':keterangan' => null
    ]);
    $shapCount++;
    echo "  [SHAP] Terimpor: $shName -> Entitas ID $entitasId (PIC: $finalName, WA: $finalPhone)\n";
}

// Ensure PT PLN Tarakan is also populated if not in excel
$checkTarakan = $pdo->query("SELECT id FROM pic_narahubung WHERE entitas_id = 23")->fetch();
if (!$checkTarakan) {
    $stmtInsert->execute([
        ':entitas_id' => 23,
        ':area_hcbp'  => 'HCBP Subholding & Anak Perusahaan',
        ':nama_pic'   => 'HC / SDM PT PLN Tarakan',
        ':no_wa'      => null,
        ':email'      => null,
        ':keterangan' => null
    ]);
    $shapCount++;
    echo "  [SHAP] Terimpor: PLN TARAKAN -> Entitas ID 23 (PIC Default)\n";
}

echo "\n=======================================================\n";
echo "PROSES SEEDING SELESAI:\n";
echo "Total Terimpor dari HCBP : $hcbpCount Entitas\n";
echo "Total Terimpor dari SHAP : $shapCount Entitas Subholding & Anak Perusahaan\n";
$totalInDb = $pdo->query("SELECT COUNT(*) FROM pic_narahubung")->fetchColumn();
echo "Total Keseluruhan PIC di Database: $totalInDb PIC\n";
echo "=======================================================\n";

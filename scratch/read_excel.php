<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = __DIR__ . '/../docs/DATA ALAMAT UNIT PLN 2024.xlsx';
$spreadsheet = IOFactory::load($path);
$sheets = $spreadsheet->getSheetNames();

// Baca semua sheet
foreach($sheets as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    $rows = $sheet->toArray();
    echo "\n\n============================\n";
    echo "SHEET: $sheetName | Total rows: " . count($rows) . "\n";
    echo "============================\n";
    echo "Header:\n";
    // Print header row
    if (!empty($rows[0])) {
        echo "  " . implode(" | ", array_map('strval', $rows[0])) . "\n";
    }
    echo "\nSample 30 baris:\n";
    for ($i = 1; $i < min(31, count($rows)); $i++) {
        $filtered = array_filter($rows[$i], fn($v) => $v !== null && $v !== '');
        if (!empty($filtered)) {
            echo "  Row " . ($i+1) . ": " . implode(" | ", array_values($filtered)) . "\n";
        }
    }
}

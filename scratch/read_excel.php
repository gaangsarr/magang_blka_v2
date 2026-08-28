<?php
require_once __DIR__ . '/../vendor/autoload.php';

$reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
$spreadsheet = $reader->load(__DIR__ . '/../docs/DATA ALAMAT UNIT PLN 2024.xlsx');

echo "Sheets:\n";
foreach ($spreadsheet->getSheetNames() as $sheetName) {
    $sheet = $spreadsheet->getSheetByName($sheetName);
    echo "=== Sheet: {$sheetName} (Rows: {$sheet->getHighestRow()}, Cols: {$sheet->getHighestColumn()}) ===\n";
    $highestRow = min(15, $sheet->getHighestRow());
    for ($row = 1; $row <= $highestRow; $row++) {
        $rowData = [];
        $highestCol = $sheet->getHighestColumn();
        for ($col = 'A'; $col <= $highestCol; $col++) {
            $val = $sheet->getCell($col . $row)->getValue();
            if ($val !== null && $val !== '') {
                $rowData[$col] = $val;
            }
        }
        if (!empty($rowData)) {
            echo "Row $row: " . json_encode($rowData, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    echo "\n";
}

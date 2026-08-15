<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::requireAdminApi();

try {
    $pdo = Database::getInstance();

    // 1. Get active period or filter period
    $periodeId = $_GET['periode_id'] ?? null;
    if (!$periodeId) {
        $stmtP = $pdo->query("SELECT id FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $p = $stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            $stmtP = $pdo->query("SELECT id FROM periode ORDER BY id DESC LIMIT 1");
            $p = $stmtP->fetch(PDO::FETCH_ASSOC);
        }
        if ($p) {
            $periodeId = $p['id'];
        }
    }

    // 2. Fetch data pendaftar
    $where = [];
    $params = [];

    if ($periodeId) {
        $where[] = "p.periode_id = :pid";
        $params[':pid'] = $periodeId;
    }

    $programFilter = $_GET['program'] ?? null;
    if ($programFilter) {
        $where[] = "p.program = :program";
        $params[':program'] = $programFilter;
    }

    $statusFilter = $_GET['status'] ?? null;
    if ($statusFilter) {
        $where[] = "p.status = :status";
        $params[':status'] = $statusFilter;
    }

    $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            j.nama_jurusan AS jurusan,
            p.program,
            e.nama AS unit_nama,
            p.status,
            CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili,
            p.submitted_at
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        {$whereSql}
        ORDER BY p.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "Data_Pendaftar_Magang_" . date('Ymd_His');

    // Check if PhpSpreadsheet is available
    if (class_exists(Spreadsheet::class)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pendaftar');

        // Header Styling & Titles
        $headers = ['No', 'NIM', 'Nama Mahasiswa', 'Email', 'No. HP', 'Jurusan', 'Program Magang', 'Unit Pelaksana Pilihan', 'Status Pendaftaran', 'Alamat Domisili', 'Tanggal Pengajuan'];
        $sheet->fromArray($headers, null, 'A1');

        // Header font bold & fill
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0B3D6B']
            ]
        ];
        $sheet->getStyle('A1:K1')->applyFromArray($headerStyle);

        $rowNum = 2;
        foreach ($rows as $idx => $r) {
            $progText = $r['program'] === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan (KRS)';
            $statusText = strtoupper($r['status']);

            $sheet->setCellValue('A' . $rowNum, $idx + 1);
            $sheet->setCellValueExplicit('B' . $rowNum, (string)($r['nim'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowNum, $r['nama'] ?? '-');
            $sheet->setCellValue('D' . $rowNum, $r['email'] ?? '-');
            $sheet->setCellValueExplicit('E' . $rowNum, (string)($r['no_hp'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rowNum, $r['jurusan'] ?? '-');
            $sheet->setCellValue('G' . $rowNum, $progText);
            $sheet->setCellValue('H' . $rowNum, $r['unit_nama'] ?? '-');
            $sheet->setCellValue('I' . $rowNum, $statusText);
            $sheet->setCellValue('J' . $rowNum, $r['alamat_domisili'] ?? '-');
            $sheet->setCellValue('K' . $rowNum, $r['submitted_at'] ?? '-');
            $rowNum++;
        }

        // Auto width for columns
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // Fallback to CSV with UTF-8 BOM
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Email', 'No. HP', 'Jurusan', 'Program Magang', 'Unit Pelaksana Pilihan', 'Status Pendaftaran', 'Alamat Domisili', 'Tanggal Pengajuan']);

    foreach ($rows as $idx => $r) {
        $progText = $r['program'] === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan (KRS)';
        fputcsv($output, [
            $idx + 1,
            "'" . ($r['nim'] ?? '-'),
            $r['nama'] ?? '-',
            $r['email'] ?? '-',
            "'" . ($r['no_hp'] ?? '-'),
            $r['jurusan'] ?? '-',
            $progText,
            $r['unit_nama'] ?? '-',
            strtoupper($r['status']),
            $r['alamat_domisili'] ?? '-',
            $r['submitted_at'] ?? '-'
        ]);
    }
    fclose($output);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo Auth::safeErrorMessage($e, 'Gagal membuat file export pendaftar.');
}


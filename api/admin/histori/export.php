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

$periodeId = $_GET['periode_id'] ?? null;
if (!$periodeId) {
    http_response_code(400);
    echo 'Parameter periode_id wajib.';
    exit;
}

try {
    $pdo = Database::getInstance();
    $periodeId = (int) $periodeId;

    // Get period name
    $stmtP = $pdo->prepare("SELECT nama FROM periode WHERE id = :id");
    $stmtP->execute([':id' => $periodeId]);
    $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    $periodeNama = $periode ? $periode['nama'] : 'Periode_' . $periodeId;

    // Fetch registrants
    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            m.email,
            p.no_hp,
            j.nama_jurusan AS jurusan,
            p.program,
            p.status,
            p.submitted_at,
            e.nama AS unit_nama,
            CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE p.periode_id = :pid
        ORDER BY p.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pid' => $periodeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $safeNama = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $periodeNama);
    $filename = "Histori_{$safeNama}_" . date('Ymd_His');

    if (class_exists(Spreadsheet::class)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Histori Pendaftar');

        $headers = ['No', 'NIM', 'Nama Mahasiswa', 'Email', 'No. HP', 'Jurusan', 'Program Magang', 'Unit Pelaksana', 'Status', 'Alamat Domisili', 'Tanggal Pengajuan'];
        $sheet->fromArray($headers, null, 'A1');

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

            $sheet->setCellValue('A' . $rowNum, $idx + 1);
            $sheet->setCellValueExplicit('B' . $rowNum, (string)($r['nim'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowNum, $r['nama'] ?? '-');
            $sheet->setCellValue('D' . $rowNum, $r['email'] ?? '-');
            $sheet->setCellValueExplicit('E' . $rowNum, (string)($r['no_hp'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rowNum, $r['jurusan'] ?? '-');
            $sheet->setCellValue('G' . $rowNum, $progText);
            $sheet->setCellValue('H' . $rowNum, $r['unit_nama'] ?? '-');
            $sheet->setCellValue('I' . $rowNum, strtoupper($r['status']));
            $sheet->setCellValue('J' . $rowNum, $r['alamat_domisili'] ?? '-');
            $sheet->setCellValue('K' . $rowNum, $r['submitted_at'] ?? '-');
            $rowNum++;
        }

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

    // Fallback CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Email', 'No. HP', 'Jurusan', 'Program Magang', 'Unit Pelaksana', 'Status', 'Alamat Domisili', 'Tanggal Pengajuan']);

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
    echo "Gagal membuat file export histori: " . $e->getMessage();
}

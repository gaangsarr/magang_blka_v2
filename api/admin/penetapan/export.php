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

    // 1. Ambil periode aktif (atau query param periode_id)
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

    $where = [];
    $params = [];

    if ($periodeId) {
        $where[] = "p.periode_id = :pid";
        $params[':pid'] = $periodeId;
    }

    $statusFilter = $_GET['status'] ?? null;
    if ($statusFilter) {
        $where[] = "p.status = :status";
        $params[':status'] = $statusFilter;
    }

    $programFilter = $_GET['program'] ?? null;
    if ($programFilter) {
        $where[] = "p.program = :program";
        $params[':program'] = $programFilter;
    }

    $jurusanFilter = $_GET['jurusan_id'] ?? null;
    if ($jurusanFilter) {
        $where[] = "m.jurusan_id = :jid";
        $params[':jid'] = $jurusanFilter;
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
            p.status,
            p.is_dipindahkan,
            p.catatan_admin,
            CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili,
            p.submitted_at,
            e.nama AS unit_nama,
            e_asal.nama AS unit_asal_nama
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
        LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
        {$whereSql}
        ORDER BY p.id DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filename = "Hasil_Penetapan_Unit_Magang_" . date('Ymd_His');

    if (class_exists(Spreadsheet::class)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Penetapan Unit');

        $headers = [
            'No', 
            'NIM', 
            'Nama Mahasiswa', 
            'Email', 
            'No. HP', 
            'Jurusan', 
            'Program Magang', 
            'Unit Pilihan Awal', 
            'Unit Penetapan Final', 
            'Status Penetapan', 
            'Dipindahkan Paksa', 
            'Catatan Admin', 
            'Alamat Domisili',
            'Tanggal Pengajuan'
        ];
        $sheet->fromArray($headers, null, 'A1');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0B3D6B']
            ]
        ];
        $sheet->getStyle('A1:N1')->applyFromArray($headerStyle);

        $rowNum = 2;
        foreach ($rows as $idx => $r) {
            $progText = $r['program'] === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan (KRS)';
            $unitAwal = $r['unit_asal_nama'] ? $r['unit_asal_nama'] : $r['unit_nama'];
            $unitFinal = $r['unit_nama'];
            $isMoved = $r['is_dipindahkan'] == 1 ? 'YA (DIPINDAHKAN)' : 'TIDAK';
            $statusText = strtoupper($r['status']);

            $sheet->setCellValue('A' . $rowNum, $idx + 1);
            $sheet->setCellValueExplicit('B' . $rowNum, (string)($r['nim'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('C' . $rowNum, $r['nama'] ?? '-');
            $sheet->setCellValue('D' . $rowNum, $r['email'] ?? '-');
            $sheet->setCellValueExplicit('E' . $rowNum, (string)($r['no_hp'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('F' . $rowNum, $r['jurusan'] ?? '-');
            $sheet->setCellValue('G' . $rowNum, $progText);
            $sheet->setCellValue('H' . $rowNum, $unitAwal);
            $sheet->setCellValue('I' . $rowNum, $unitFinal);
            $sheet->setCellValue('J' . $rowNum, $statusText);
            $sheet->setCellValue('K' . $rowNum, $isMoved);
            $sheet->setCellValue('L' . $rowNum, $r['catatan_admin'] ?? '-');
            $sheet->setCellValue('M' . $rowNum, $r['alamat_domisili'] ?? '-');
            $sheet->setCellValue('N' . $rowNum, $r['submitted_at'] ?? '-');

            // Highlight relocated rows in light amber
            if ($r['is_dipindahkan'] == 1) {
                $sheet->getStyle('A' . $rowNum . ':N' . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FEF3C7');
            }

            $rowNum++;
        }

        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // Fallback to CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'Email', 'No. HP', 'Jurusan', 'Program Magang', 'Unit Pilihan Awal', 'Unit Penetapan Final', 'Status Penetapan', 'Dipindahkan Paksa', 'Catatan Admin', 'Alamat Domisili', 'Tanggal Pengajuan']);

    foreach ($rows as $idx => $r) {
        $progText = $r['program'] === '1_bulan' ? 'Magang 1 Bulan' : 'Magang 5 Bulan (KRS)';
        $unitAwal = $r['unit_asal_nama'] ? $r['unit_asal_nama'] : $r['unit_nama'];
        fputcsv($output, [
            $idx + 1,
            "'" . ($r['nim'] ?? '-'),
            $r['nama'] ?? '-',
            $r['email'] ?? '-',
            "'" . ($r['no_hp'] ?? '-'),
            $r['jurusan'] ?? '-',
            $progText,
            $unitAwal,
            $r['unit_nama'] ?? '-',
            strtoupper($r['status']),
            $r['is_dipindahkan'] == 1 ? 'YA (DIPINDAHKAN)' : 'TIDAK',
            $r['catatan_admin'] ?? '-',
            $r['alamat_domisili'] ?? '-',
            $r['submitted_at'] ?? '-'
        ]);
    }
    fclose($output);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Gagal membuat file export penetapan: " . $e->getMessage();
}

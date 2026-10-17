<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::requireProdiApi();

try {
    $pdo = Database::getInstance();
    $jurusanId = Auth::getProdiJurusanId();

    if (!$jurusanId) {
        http_response_code(403);
        echo 'Akun admin belum terikat dengan Program Studi manapun.';
        exit;
    }

    // Detail Prodi
    $stmtP = $pdo->prepare("SELECT nama_jurusan, jenjang, kode FROM jurusan WHERE id = ?");
    $stmtP->execute([$jurusanId]);
    $prodi = $stmtP->fetch(PDO::FETCH_ASSOC);
    $namaProdi = ($prodi['jenjang'] ?? 'S1') . ' ' . ($prodi['nama_jurusan'] ?? 'Prodi');

    // Filter Periode
    $periodeId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    if ($periodeId <= 0) {
        $stmtPer = $pdo->query("SELECT id, nama FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $per = $stmtPer->fetch(PDO::FETCH_ASSOC);
        if (!$per) {
            $stmtPer = $pdo->query("SELECT id, nama FROM periode ORDER BY id DESC LIMIT 1");
            $per = $stmtPer->fetch(PDO::FETCH_ASSOC);
        }
        $periodeId = $per ? (int)$per['id'] : 0;
        $namaPeriode = $per['nama'] ?? 'Semua_Periode';
    } else {
        $stmtPer = $pdo->prepare("SELECT id, nama FROM periode WHERE id = ?");
        $stmtPer->execute([$periodeId]);
        $per = $stmtPer->fetch(PDO::FETCH_ASSOC);
        $namaPeriode = $per['nama'] ?? "Periode_{$periodeId}";
    }

    $where = ['p.periode_id = :pid', 'm.jurusan_id = :jid'];
    $params = [':pid' => $periodeId, ':jid' => $jurusanId];

    $programFilter = trim((string)($_GET['program'] ?? ''));
    if ($programFilter !== '') {
        $rawProg = str_replace(' ', '_', strtolower($programFilter));
        $where[] = '(p.program = :program OR p.program = :program_raw)';
        $params[':program'] = $programFilter;
        $params[':program_raw'] = $rawProg;
    }

    $statusFilter = trim((string)($_GET['status'] ?? ''));
    if ($statusFilter !== '') {
        if ($statusFilter === 'diproses') {
            $where[] = "p.status IN ('menunggu', 'diproses')";
        } else {
            $where[] = 'p.status = :status';
            $params[':status'] = $statusFilter;
        }
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            p.jenis_kelamin,
            m.angkatan,
            p.ipk,
            p.jumlah_sks,
            m.email,
            p.no_hp,
            CASE p.program
                WHEN '1_bulan' THEN '1 Bulan'
                WHEN '3_bulan' THEN '3 Bulan'
                WHEN '4_bulan' THEN '4 Bulan'
                WHEN '5_bulan' THEN '5 Bulan'
                WHEN '6_bulan' THEN '6 Bulan'
                ELSE IFNULL(NULLIF(p.program, ''), '-')
            END AS program,
            e.nama AS unit_nama,
            p.cv_path,
            p.transkrip_path,
            p.porto_path,
            p.status,
            p.catatan_admin,
            p.submitted_at,
            pem_agg.peminatan_list
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN (
            SELECT pp.pendaftaran_id, GROUP_CONCAT(mp.nama ORDER BY mp.nama SEPARATOR ', ') AS peminatan_list
            FROM pendaftaran_peminatan pp
            JOIN peminatan mp ON pp.peminatan_id = mp.id
            GROUP BY pp.pendaftaran_id
        ) pem_agg ON pem_agg.pendaftaran_id = p.id
        WHERE {$whereSql}
        ORDER BY p.submitted_at DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $cleanProdiName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $namaProdi);
    $cleanPeriodeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $namaPeriode);
    $filename = "Pendaftar_{$cleanProdiName}_{$cleanPeriodeName}_" . date('Ymd_His');

    if (class_exists(Spreadsheet::class)) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pendaftar');

        // Title rows
        $sheet->setCellValue('A1', 'REKAPITULASI PENDAFTAR MAGANG INTERN ITPLN');
        $sheet->setCellValue('A2', 'Program Studi: ' . $namaProdi . ' | Periode: ' . $namaPeriode);
        $sheet->setCellValue('A3', 'Diekspor pada: ' . date('d/m/Y H:i:s') . ' WIB | Total: ' . count($rows) . ' Mahasiswa');

        $sheet->mergeCells('A1:P1');
        $sheet->mergeCells('A2:P2');
        $sheet->mergeCells('A3:P3');

        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);

        // Header Table
        $headers = [
            'No', 
            'NIM', 
            'Nama Mahasiswa', 
            'L/P', 
            'Angkatan', 
            'IPK', 
            'SKS', 
            'Email', 
            'No. WhatsApp', 
            'Program Magang', 
            'Unit PLN Tujuan', 
            'Peminatan Bidang', 
            'Berkas Terlampir',
            'Status Seleksi', 
            'Catatan Seleksi', 
            'Waktu Pendaftaran'
        ];
        $sheet->fromArray($headers, null, 'A5');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '0B3D6B']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true
            ]
        ];
        $sheet->getStyle('A5:P5')->applyFromArray($headerStyle);
        $sheet->getRowDimension(5)->setRowHeight(28);

        // Content Rows
        $rowIdx = 6;
        $no = 1;
        foreach ($rows as $r) {
            $statusText = strtoupper($r['status'] ?? 'MENUNGGU');
            if ($statusText === 'DITERIMA') $statusText = 'DITERIMA';
            elseif ($statusText === 'DITOLAK') $statusText = 'DITOLAK';
            elseif ($statusText === 'CADANGAN') $statusText = 'CADANGAN';
            elseif ($statusText === 'DIPINDAHKAN') $statusText = 'DIPINDAHKAN';
            else $statusText = 'DALAM PROSES';

            // Berkas terlampir list
            $docList = [];
            if (!empty($r['cv_path'])) $docList[] = 'CV';
            if (!empty($r['transkrip_path'])) $docList[] = 'Transkrip';
            if (!empty($r['porto_path'])) $docList[] = 'Portofolio';
            $docStr = !empty($docList) ? implode(', ', $docList) : '-';

            $dataRow = [
                $no++,
                "'" . (string)$r['nim'], // Format string text agar tidak dipotong Excel
                $r['nama'],
                $r['jenis_kelamin'] ?? '-',
                $r['angkatan'] ?? '-',
                number_format((float)($r['ipk'] ?? 0), 2),
                (int)($r['jumlah_sks'] ?? 0),
                $r['email'],
                "'" . (string)($r['no_hp'] ?? '-'),
                $r['program'] ?? '-',
                $r['unit_nama'],
                $r['peminatan_list'] ?: '-',
                $docStr,
                $statusText,
                $r['catatan_admin'] ?: '-',
                $r['submitted_at'] ? date('d/m/Y H:i', strtotime($r['submitted_at'])) : '-'
            ];

            $sheet->fromArray($dataRow, null, 'A' . $rowIdx);

            // Border style per row
            $sheet->getStyle("A{$rowIdx}:P{$rowIdx}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D0D5DD');

            // Alignment
            $sheet->getStyle("A{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("B{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("D{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("E{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("I{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("M{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("N{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("P{$rowIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Status color highlight
            if ($statusText === 'DITERIMA') {
                $sheet->getStyle("N{$rowIdx}")->getFont()->setBold(true)->getColor()->setRGB('166534');
            } elseif ($statusText === 'DITOLAK') {
                $sheet->getStyle("N{$rowIdx}")->getFont()->setBold(true)->getColor()->setRGB('991B1B');
            }

            $rowIdx++;
        }

        // Auto-size columns
        foreach (range('A', 'P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // Fallback: CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['No', 'NIM', 'Nama Mahasiswa', 'L/P', 'Angkatan', 'IPK', 'SKS', 'Email', 'No. HP', 'Program', 'Unit PLN', 'Peminatan', 'Berkas', 'Status', 'Catatan', 'Tanggal Daftar']);
    $no = 1;
    foreach ($rows as $r) {
        fputcsv($output, [
            $no++,
            $r['nim'],
            $r['nama'],
            $r['jenis_kelamin'],
            $r['angkatan'],
            $r['ipk'],
            $r['jumlah_sks'],
            $r['email'],
            $r['no_hp'],
            $r['program'],
            $r['unit_nama'],
            $r['peminatan_list'],
            $r['status'],
            $r['catatan_admin'],
            $r['submitted_at']
        ]);
    }
    fclose($output);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Terjadi kesalahan saat mengekspor data: ' . $e->getMessage();
}

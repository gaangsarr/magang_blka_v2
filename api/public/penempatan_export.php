<?php
/**
 * api/public/penempatan_export.php
 * Export Excel (.xlsx) Rekapitulasi Penempatan Mahasiswa Publik berdasarkan Token.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\SuratGenerator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

try {
    $pdo = Database::getInstance();

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        http_response_code(403);
        die('Akses Ditolak: Memerlukan token otentikasi resmi.');
    }

    $periode = SuratGenerator::getPeriodeByToken($pdo, $token);
    if (!$periode) {
        http_response_code(404);
        die('Tautan Pengumuman Tidak Ditemukan: Token tidak valid.');
    }

    $periodeId = (int)$periode['id'];

    $search = trim((string)($_GET['search'] ?? ''));
    $unitFilter = (int)($_GET['unit_id'] ?? 0);
    $jurusanFilter = (int)($_GET['jurusan_id'] ?? 0);
    $programFilter = trim((string)($_GET['program'] ?? ''));

    $where = ["p.periode_id = :pid", "p.status IN ('diterima', 'dipindahkan')"];
    $params = [':pid' => $periodeId];

    if ($unitFilter > 0) {
        $where[] = "upp.entitas_id = :uid";
        $params[':uid'] = $unitFilter;
    }

    if ($jurusanFilter > 0) {
        $where[] = "m.jurusan_id = :jid";
        $params[':jid'] = $jurusanFilter;
    }

    if ($programFilter !== '') {
        $where[] = "p.program = :program";
        $params[':program'] = $programFilter;
    }

    if ($search !== '') {
        $where[] = "(m.nim LIKE :s OR p.nama_snapshot LIKE :s OR e.nama LIKE :s OR j.nama_jurusan LIKE :s)";
        $params[':s'] = "%{$search}%";
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT 
            p.id AS pendaftaran_id,
            m.nim,
            p.nama_snapshot AS nama,
            j.nama_jurusan AS prodi,
            p.program,
            e.nama AS unit_penempatan,
            COALESCE(GROUP_CONCAT(DISTINCT pem.nama SEPARATOR ', '), '-') AS peminatan
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN pendaftaran_peminatan pp ON p.id = pp.pendaftaran_id
        LEFT JOIN peminatan pem ON pp.peminatan_id = pem.id
        WHERE {$whereSql}
        GROUP BY p.id, m.nim, p.nama_snapshot, j.nama_jurusan, p.program, e.nama
        ORDER BY e.nama ASC, j.nama_jurusan ASC, p.nama_snapshot ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Penempatan Mahasiswa');

    // Header Title
    $sheet->setCellValue('A1', 'REKAPITULASI PENEMPATAN MAHASISWA MAGANG PLN GROUP');
    $sheet->setCellValue('A2', 'Institut Teknologi PLN — Periode: ' . $periode['nama']);
    $sheet->mergeCells('A1:G1');
    $sheet->mergeCells('A2:G2');

    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0B3D6B'));
    $sheet->getStyle('A2')->getFont()->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
    $sheet->getStyle('A1:A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // Table Header
    $headers = ['No', 'NIM', 'Nama Mahasiswa', 'Program Studi', 'Program Magang', 'Unit Penempatan PLN', 'Peminatan'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G'];

    $headerRow = 4;
    foreach ($headers as $idx => $h) {
        $sheet->setCellValue($cols[$idx] . $headerRow, $h);
    }

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '0B3D6B']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]
        ]
    ];
    $sheet->getStyle('A4:G4')->applyFromArray($headerStyle);
    $sheet->getRowDimension(4)->setRowHeight(28);

    // Rows
    $rIdx = 5;
    foreach ($rows as $i => $r) {
        $progLabel = ($r['program'] === '5_bulan') ? 'Magang 5 Bulan' : 'Magang 1 Bulan';
        $sheet->setCellValue('A' . $rIdx, $i + 1);
        $sheet->setCellValueExplicit('B' . $rIdx, $r['nim'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $rIdx, $r['nama']);
        $sheet->setCellValue('D' . $rIdx, $r['prodi']);
        $sheet->setCellValue('E' . $rIdx, $progLabel);
        $sheet->setCellValue('F' . $rIdx, $r['unit_penempatan']);
        $sheet->setCellValue('G' . $rIdx, $r['peminatan']);

        $bg = ($i % 2 === 1) ? 'F8FAFC' : 'FFFFFF';
        $sheet->getStyle("A{$rIdx}:G{$rIdx}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ]);

        $sheet->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getRowDimension($rIdx)->setRowHeight(22);
        $rIdx++;
    }

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $periode['nama']);
    $filename = 'Rekap_Penempatan_' . $safeName . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    error_log('[penempatan_export.php] Error: ' . $e->getMessage());
    http_response_code(500);
    die('Terjadi kesalahan sistem saat mengekspor data.');
}

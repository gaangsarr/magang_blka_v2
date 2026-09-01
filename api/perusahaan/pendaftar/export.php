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

Auth::requirePerusahaanApi();

$pdo = Database::getInstance();
$entitasId = Auth::getPerusahaanEntitasId();
$admin = Auth::getAdmin();

if (!$entitasId) {
    if ($admin && ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin')) {
        $requestedEid = isset($_GET['entitas_id']) ? (int)$_GET['entitas_id'] : 0;
        if ($requestedEid > 0) {
            $entitasId = $requestedEid;
        } else {
            $stmtFirst = $pdo->query("SELECT id FROM entitas_perusahaan WHERE tipe IN ('unit_pelaksana', 'unit_layanan') ORDER BY id ASC LIMIT 1");
            $entitasId = (int)$stmtFirst->fetchColumn();
        }
    }
}

if (!$entitasId) {
    http_response_code(400);
    die('Entitas unit tidak valid.');
}

try {
    // 1. Ambil Info Entitas
    $stmtE = $pdo->prepare("SELECT nama, singkatan FROM entitas_perusahaan WHERE id = ?");
    $stmtE->execute([$entitasId]);
    $entitas = $stmtE->fetch(PDO::FETCH_ASSOC);
    $unitNama = $entitas['nama'] ?? 'Unit Mitra';

    // 2. Ambil Periode
    $periodeId = (int)($_GET['periode_id'] ?? 0);
    $periode = null;
    if ($periodeId > 0) {
        $stmtP = $pdo->prepare("SELECT id, nama FROM periode WHERE id = ?");
        $stmtP->execute([$periodeId]);
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }
    if (!$periode) {
        $stmtP = $pdo->query("SELECT id, nama FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY id DESC LIMIT 1");
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }
    if (!$periode) {
        $stmtP = $pdo->query("SELECT id, nama FROM periode ORDER BY id DESC LIMIT 1");
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

    $pid = $periode ? (int)$periode['id'] : 0;
    $periodeNama = $periode ? $periode['nama'] : 'Semua Periode';

    // 3. Query Data
    $stmtData = $pdo->prepare("
        SELECT 
            p.id AS pendaftaran_id,
            p.nama_snapshot,
            p.jenis_kelamin,
            p.ipk,
            p.jumlah_sks,
            p.no_hp,
            p.program,
            p.status,
            p.submitted_at,
            p.alamat, p.rt, p.rw, p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi,
            m.nim,
            m.email,
            m.angkatan,
            j.nama_jurusan
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        WHERE p.periode_id = :pid AND upp.entitas_id = :eid
        ORDER BY p.submitted_at DESC
    ");
    $stmtData->execute([':pid' => $pid, ':eid' => $entitasId]);
    $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // Ambil peminatan
    $pendaftaranIds = array_column($rows, 'pendaftaran_id');
    $peminatanMap = [];
    if (!empty($pendaftaranIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($pendaftaranIds), '?'));
        $stmtPem = $pdo->prepare("
            SELECT pp.pendaftaran_id, pem.nama AS nama_peminatan
            FROM pendaftaran_peminatan pp
            JOIN peminatan pem ON pp.peminatan_id = pem.id
            WHERE pp.pendaftaran_id IN ({$inPlaceholders})
            ORDER BY pem.nama ASC
        ");
        $stmtPem->execute($pendaftaranIds);
        while ($pemRow = $stmtPem->fetch(PDO::FETCH_ASSOC)) {
            $pId = (int)$pemRow['pendaftaran_id'];
            $peminatanMap[$pId][] = $pemRow['nama_peminatan'];
        }
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Daftar Pendaftar');

    // Title
    $sheet->mergeCells('A1:L1');
    $sheet->setCellValue('A1', 'DAFTAR PENDAFTAR MAGANG — ' . strtoupper($unitNama));
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF004687'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A2:L2');
    $sheet->setCellValue('A2', 'Periode: ' . $periodeNama . ' | Total Pendaftar: ' . count($rows) . ' Mahasiswa | Diekspor: ' . date('d-m-Y H:i:s'));
    $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Table Header
    $headers = [
        'No', 'NIM', 'Nama Mahasiswa', 'L/P', 'Program Studi', 'Angkatan', 
        'IPK', 'SKS', 'Program', 'Peminatan', 'No. WhatsApp / HP', 'Email Kampus'
    ];
    $colLetter = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($colLetter . '4', $h);
        $colLetter++;
    }

    $sheet->getStyle('A4:L4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '004687']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(4)->setRowHeight(25);

    $rowNum = 5;
    $no = 1;
    foreach ($rows as $r) {
        $pId = (int)$r['pendaftaran_id'];
        $peminatanStr = isset($peminatanMap[$pId]) ? implode(', ', $peminatanMap[$pId]) : '-';

        $sheet->setCellValue('A' . $rowNum, $no++);
        $sheet->setCellValue('B' . $rowNum, $r['nim']);
        $sheet->setCellValue('C' . $rowNum, $r['nama_snapshot']);
        $sheet->setCellValue('D' . $rowNum, $r['jenis_kelamin']);
        $sheet->setCellValue('E' . $rowNum, $r['nama_jurusan'] ?? '-');
        $sheet->setCellValue('F' . $rowNum, $r['angkatan'] ?? '-');
        $sheet->setCellValue('G' . $rowNum, $r['ipk'] ? number_format((float)$r['ipk'], 2) : '-');
        $sheet->setCellValue('H' . $rowNum, $r['jumlah_sks'] ?? '-');
        $sheet->setCellValue('I' . $rowNum, $r['program'] === '5_bulan' ? '5 Bulan' : '1 Bulan');
        $sheet->setCellValue('J' . $rowNum, $peminatanStr);
        $sheet->setCellValue('K' . $rowNum, $r['no_hp'] ?? '-');
        $sheet->setCellValue('L' . $rowNum, $r['email']);

        $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('D' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('H' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('I' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNum++;
    }

    $lastRow = $rowNum - 1;
    if ($lastRow >= 4) {
        $sheet->getStyle('A4:L' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCBD5E1'));
    }

    foreach (range('A', 'L') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $cleanUnitName = preg_replace('/[^a-zA-Z0-9]/', '_', $unitNama);
    $fileName = 'Pendaftar_' . $cleanUnitName . '_' . date('Ymd_His') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Gagal mengekspor pendaftar: " . htmlspecialchars($e->getMessage());
}

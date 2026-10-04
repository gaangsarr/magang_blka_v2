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

    // 2. Ambil Periode Beserta Persyaratan Berkas
    $periodeId = (int)($_GET['periode_id'] ?? 0);
    $periode = null;
    if ($periodeId > 0) {
        $stmtP = $pdo->prepare("SELECT id, nama, status, syarat_transkrip, syarat_cv, syarat_porto FROM periode WHERE id = ?");
        $stmtP->execute([$periodeId]);
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }
    if (!$periode) {
        $stmtP = $pdo->query("SELECT id, nama, status, syarat_transkrip, syarat_cv, syarat_porto FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY id DESC LIMIT 1");
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }
    if (!$periode) {
        $stmtP = $pdo->query("SELECT id, nama, status, syarat_transkrip, syarat_cv, syarat_porto FROM periode ORDER BY id DESC LIMIT 1");
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC);
    }

    $pid = $periode ? (int)$periode['id'] : 0;
    $periodeNama = $periode ? $periode['nama'] : 'Semua Periode';

    // 3. Query Data Pendaftar
    $statusFilter = isset($_GET['status']) && $_GET['status'] !== '' ? trim($_GET['status']) : '';
    $whereStatus = '';
    $queryParams = [':pid' => $pid, ':eid' => $entitasId];
    if ($statusFilter !== '') {
        $whereStatus = " AND p.status = :status ";
        $queryParams[':status'] = $statusFilter;
    }

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
            p.transkrip_path,
            p.cv_path,
            p.porto_path,
            m.nim,
            m.email,
            m.angkatan,
            j.nama_jurusan
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        WHERE p.periode_id = :pid AND upp.entitas_id = :eid {$whereStatus}
        ORDER BY p.submitted_at DESC
    ");
    $stmtData->execute($queryParams);
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

    // Helper format alamat lengkap mahasiswa
    $formatAlamat = function(array $r): string {
        $parts = [];
        if (!empty($r['alamat'])) {
            $parts[] = trim((string)$r['alamat']);
        }
        $rtrw = [];
        if (!empty($r['rt'])) {
            $rtrw[] = 'RT ' . trim((string)$r['rt']);
        }
        if (!empty($r['rw'])) {
            $rtrw[] = 'RW ' . trim((string)$r['rw']);
        }
        if (!empty($rtrw)) {
            $parts[] = implode('/', $rtrw);
        }
        if (!empty($r['kelurahan'])) {
            $parts[] = 'Kel. ' . trim((string)$r['kelurahan']);
        }
        if (!empty($r['kecamatan'])) {
            $parts[] = 'Kec. ' . trim((string)$r['kecamatan']);
        }
        if (!empty($r['kota_kabupaten'])) {
            $parts[] = trim((string)$r['kota_kabupaten']);
        }
        if (!empty($r['provinsi'])) {
            $parts[] = trim((string)$r['provinsi']);
        }
        return !empty($parts) ? implode(', ', $parts) : '-';
    };

    $isRoster = ($statusFilter === 'diterima');
    $sheetTitle = $isRoster ? 'Roster Peserta Sah' : 'Daftar Pendaftar';
    $mainHeading = $isRoster ? ('ROSTER RESMI MAHASISWA MAGANG (DITERIMA): ' . strtoupper($unitNama)) : ('DAFTAR PENDAFTAR MAGANG: ' . strtoupper($unitNama));

    // 4. Bangun Spreadsheet Rekapitulasi Data
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetTitle);

    // Title Block
    $sheet->mergeCells('A1:M1');
    $sheet->setCellValue('A1', $mainHeading);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF004687'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A2:M2');
    $sheet->setCellValue('A2', 'Periode: ' . $periodeNama . ' | Total Mahasiswa: ' . count($rows) . ' Mahasiswa | Diekspor: ' . date('d-m-Y H:i:s'));
    $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Table Headers (Kolom M ditambahkan untuk Alamat Mahasiswa)
    $headers = [
        'No', 'NIM', 'Nama Mahasiswa', 'L/P', 'Program Studi', 'Angkatan', 
        'IPK', 'SKS', 'Program', 'Peminatan', 'No. WhatsApp / HP', 'Email Kampus', 'Alamat Lengkap Mahasiswa'
    ];
    $colLetter = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($colLetter . '4', $h);
        $colLetter++;
    }

    $sheet->getStyle('A4:M4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '004687']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(4)->setRowHeight(26);

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
        $sheet->setCellValue('M' . $rowNum, $formatAlamat($r));

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
        $sheet->getStyle('A4:M' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCBD5E1'));
    }

    foreach (range('A', 'M') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $cleanUnitName = preg_replace('/[^a-zA-Z0-9]/', '_', $unitNama);
    $requestedFormat = strtolower(trim((string)($_GET['format'] ?? '')));

    // 5. Cek apakah pengguna hanya meminta format Excel saja
    if ($requestedFormat === 'excel' || $requestedFormat === 'xlsx') {
        $prefix = $isRoster ? 'Roster_Resmi_' : 'Pendaftar_';
        $fileName = $prefix . $cleanUnitName . '_' . date('Ymd_His') . '.xlsx';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // 6. DEFAULT: Buat Paket ZIP Lengkap (File Excel Rekap + Folder Berkas Tiap Mahasiswa)
    $storageRoot = realpath($root . '/storage');

    // Cek syarat berkas wajib pada periode ini
    $reqTranskrip = !isset($periode['syarat_transkrip']) || (int)$periode['syarat_transkrip'] === 1;
    $reqCv        = isset($periode['syarat_cv']) && (int)$periode['syarat_cv'] === 1;
    $reqPorto     = isset($periode['syarat_porto']) && (int)$periode['syarat_porto'] === 1;

    // Jika seluruh flag syarat 0 (misal data periode legacy), aktifkan berkas yang tersedia
    if (!$reqTranskrip && !$reqCv && !$reqPorto) {
        $reqTranskrip = true;
        $reqCv = true;
        $reqPorto = true;
    }

    $tempExcelPath = tempnam(sys_get_temp_dir(), 'intern_xlsx_');
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempExcelPath);

    $tempZipPath = tempnam(sys_get_temp_dir(), 'intern_zip_');
    $zip = new \ZipArchive();
    $zipRes = $zip->open($tempZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
    if ($zipRes !== true) {
        throw new \RuntimeException("Gagal menginisialisasi arsip ZIP di server.");
    }

    // 6.1 Tambahkan File Excel ke Root Arsip ZIP
    $excelFileNameInZip = ($isRoster ? 'Rekap_Roster_Resmi_' : 'Rekap_Pendaftar_') . $cleanUnitName . '_' . date('Ymd_His') . '.xlsx';
    $zip->addFile($tempExcelPath, $excelFileNameInZip);

    // 6.2 Tambahkan Folder Dokumen per Mahasiswa
    foreach ($rows as $r) {
        $cleanNim = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$r['nim']);
        $cleanNama = preg_replace('/[\\\\\/:\*\?"<>\|\r\n\t]/', '', (string)$r['nama_snapshot']);
        $cleanNama = trim(preg_replace('/\s+/', ' ', $cleanNama));
        if ($cleanNama === '') {
            $cleanNama = 'Mahasiswa';
        }

        $studentFolder = "{$cleanNim} - {$cleanNama}";
        $zip->addEmptyDir($studentFolder);

        $hasFileAdded = false;

        // Berkas Transkrip Nilai (sesuai syarat periode)
        if ($reqTranskrip && !empty($r['transkrip_path']) && $storageRoot) {
            $tRel = ltrim((string)$r['transkrip_path'], '/\\');
            $tFull = realpath($storageRoot . DIRECTORY_SEPARATOR . $tRel);
            if ($tFull && str_starts_with($tFull, $storageRoot) && is_file($tFull)) {
                $ext = strtolower(pathinfo($tFull, PATHINFO_EXTENSION)) ?: 'pdf';
                $zip->addFile($tFull, "{$studentFolder}/Transkrip_Nilai_{$cleanNim}.{$ext}");
                $hasFileAdded = true;
            }
        }

        // Berkas Curriculum Vitae / CV (sesuai syarat periode)
        if ($reqCv && !empty($r['cv_path']) && $storageRoot) {
            $cRel = ltrim((string)$r['cv_path'], '/\\');
            $cFull = realpath($storageRoot . DIRECTORY_SEPARATOR . $cRel);
            if ($cFull && str_starts_with($cFull, $storageRoot) && is_file($cFull)) {
                $ext = strtolower(pathinfo($cFull, PATHINFO_EXTENSION)) ?: 'pdf';
                $zip->addFile($cFull, "{$studentFolder}/Curriculum_Vitae_{$cleanNim}.{$ext}");
                $hasFileAdded = true;
            }
        }

        // Berkas Portofolio (sesuai syarat periode)
        if ($reqPorto && !empty($r['porto_path']) && $storageRoot) {
            $pRel = ltrim((string)$r['porto_path'], '/\\');
            $pFull = realpath($storageRoot . DIRECTORY_SEPARATOR . $pRel);
            if ($pFull && str_starts_with($pFull, $storageRoot) && is_file($pFull)) {
                $ext = strtolower(pathinfo($pFull, PATHINFO_EXTENSION)) ?: 'pdf';
                $zip->addFile($pFull, "{$studentFolder}/Portofolio_{$cleanNim}.{$ext}");
                $hasFileAdded = true;
            }
        }

        // Jika tidak ada berkas fisik yang berhasil dilampirkan, sertakan catatan informasi
        if (!$hasFileAdded) {
            $infoContent = "INFORMASI BERKAS PENDAFTARAN:\n";
            $infoContent .= "Nama Mahasiswa : {$r['nama_snapshot']}\n";
            $infoContent .= "NIM            : {$r['nim']}\n";
            $infoContent .= "Program Studi  : " . ($r['nama_jurusan'] ?? '-') . "\n";
            $infoContent .= "Status Berkas  : Mahasiswa tidak memiliki lampiran berkas yang diunggah atau berkas belum diarsipkan.\n";
            $zip->addFromString("{$studentFolder}/CATATAN_BERKAS.txt", $infoContent);
        }
    }

    $zip->close();

    $zipDownloadName = ($isRoster ? 'Arsip_Roster_Sah_' : 'Arsip_Pendaftar_') . $cleanUnitName . '_' . date('Ymd_His') . '.zip';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipDownloadName . '"');
    header('Content-Length: ' . (string)filesize($tempZipPath));
    header('Cache-Control: max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($tempZipPath);

    @unlink($tempExcelPath);
    @unlink($tempZipPath);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    echo $debug ? ("Gagal mengekspor data: " . htmlspecialchars($e->getMessage())) : "Gagal mengekspor data pendaftar. Silakan coba lagi.";
}

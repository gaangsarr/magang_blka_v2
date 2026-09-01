<?php
/**
 * api/public/penempatan_export.php
 * Export Excel (.xlsx) Rekapitulasi Penempatan Mahasiswa Publik berbasis Folder Hierarki.
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

    $groupType = trim((string)($_GET['group_type'] ?? ''));
    $category = trim((string)($_GET['category'] ?? ''));
    $entitasId = (int)($_GET['entitas_id'] ?? 0);
    $jurusanFilter = (int)($_GET['jurusan_id'] ?? 0);
    $search = trim((string)($_GET['search'] ?? ''));

    $groupedData = SuratGenerator::getGroupedSuratData($pdo, $periodeId);

    $allCandidateStudents = [];
    $folderTitle = 'Rekapitulasi Seluruh Penempatan';

    if ($groupType === 'holding_unit') {
        if ($category !== '' && isset($groupedData['holding_unit']['categories'][$category])) {
            $catObj = $groupedData['holding_unit']['categories'][$category];
            $allCandidateStudents = $catObj['students'];
            $folderTitle = $catObj['nama'];
        } else {
            $allCandidateStudents = $groupedData['holding_unit']['students'];
            $folderTitle = 'Kantor Pusat PLN dan Unit-Unit';
        }
    } elseif ($groupType === 'subholding') {
        if ($entitasId > 0) {
            foreach ($groupedData['subholdings'] as $sh) {
                if ($sh['id'] === $entitasId) {
                    $allCandidateStudents = $sh['students'];
                    $folderTitle = $sh['nama'];
                    break;
                }
            }
        } else {
            foreach ($groupedData['subholdings'] as $sh) {
                foreach ($sh['students'] as $st) {
                    $allCandidateStudents[] = $st;
                }
            }
            $folderTitle = 'Subholding PLN';
        }
    } elseif ($groupType === 'anak_perusahaan') {
        if ($entitasId > 0) {
            foreach ($groupedData['anak_perusahaan'] as $ap) {
                if ($ap['id'] === $entitasId) {
                    $allCandidateStudents = $ap['students'];
                    $folderTitle = $ap['nama'];
                    break;
                }
            }
        } else {
            foreach ($groupedData['anak_perusahaan'] as $ap) {
                foreach ($ap['students'] as $st) {
                    $allCandidateStudents[] = $st;
                }
            }
            $folderTitle = 'Anak Perusahaan PLN';
        }
    } else {
        foreach ($groupedData['holding_unit']['students'] as $st) {
            $allCandidateStudents[] = $st;
        }
        foreach ($groupedData['subholdings'] as $sh) {
            foreach ($sh['students'] as $st) {
                $allCandidateStudents[] = $st;
            }
        }
        foreach ($groupedData['anak_perusahaan'] as $ap) {
            foreach ($ap['students'] as $st) {
                $allCandidateStudents[] = $st;
            }
        }
    }

    $filtered = array_filter($allCandidateStudents, function($s) use ($jurusanFilter, $search, $pdo) {
        if ($search !== '') {
            $sLower = mb_strtolower($search, 'UTF-8');
            $nim = mb_strtolower((string)($s['nim'] ?? ''), 'UTF-8');
            $nama = mb_strtolower((string)($s['nama'] ?? ''), 'UTF-8');
            $prodi = mb_strtolower((string)($s['prodi'] ?? ''), 'UTF-8');
            $unit = mb_strtolower((string)($s['entitas_nama'] ?? ''), 'UTF-8');
            $peminatan = mb_strtolower((string)($s['peminatan'] ?? ''), 'UTF-8');

            if (!str_contains($nim, $sLower) &&
                !str_contains($nama, $sLower) &&
                !str_contains($prodi, $sLower) &&
                !str_contains($unit, $sLower) &&
                !str_contains($peminatan, $sLower)) {
                return false;
            }
        }
        return true;
    });

    if ($jurusanFilter > 0) {
        $stmtJ = $pdo->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
        $stmtJ->execute([$jurusanFilter]);
        $targetJName = $stmtJ->fetchColumn();
        if ($targetJName) {
            $filtered = array_filter($filtered, function($s) use ($targetJName) {
                return ($s['prodi'] ?? '') === $targetJName;
            });
        }
    }

    $rows = array_values($filtered);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Penempatan Mahasiswa');

    // Header Title
    $sheet->setCellValue('A1', 'REKAPITULASI PENEMPATAN MAHASISWA MAGANG PLN GROUP');
    $sheet->setCellValue('A2', 'Institut Teknologi PLN — ' . $folderTitle . ' (' . $periode['nama'] . ')');
    $sheet->mergeCells('A1:K1');
    $sheet->mergeCells('A2:K2');

    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('0B3D6B'));
    $sheet->getStyle('A2')->getFont()->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('475569'));
    $sheet->getStyle('A1:A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

    // Table Header
    $headers = ['No', 'NIM', 'Nama Mahasiswa', 'Jenis Kelamin', 'No. HP', 'Email', 'Program Studi', 'IPK', 'SKS', 'Peminatan', 'Unit Penempatan'];
    $cols = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];

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
    $sheet->getStyle('A4:K4')->applyFromArray($headerStyle);
    $sheet->getRowDimension(4)->setRowHeight(28);

    // Rows
    $rIdx = 5;
    foreach ($rows as $i => $r) {
        $jk = ($r['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : 'Laki - Laki';
        $ipkText = $r['ipk'] !== null ? number_format((float)$r['ipk'], 2) : '-';

        $sheet->setCellValue('A' . $rIdx, $i + 1);
        $sheet->setCellValueExplicit('B' . $rIdx, (string)($r['nim'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('C' . $rIdx, $r['nama'] ?? '-');
        $sheet->setCellValue('D' . $rIdx, $jk);
        $sheet->setCellValueExplicit('E' . $rIdx, (string)($r['no_hp'] ?? '-'), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValue('F' . $rIdx, $r['email'] ?? '-');
        $sheet->setCellValue('G' . $rIdx, $r['prodi'] ?? '-');
        $sheet->setCellValue('H' . $rIdx, $ipkText);
        $sheet->setCellValue('I' . $rIdx, (int)($r['jumlah_sks'] ?? 0));
        $sheet->setCellValue('J' . $rIdx, $r['peminatan'] ?? '-');
        $sheet->setCellValue('K' . $rIdx, $r['entitas_nama'] ?? '-');

        $bg = ($i % 2 === 1) ? 'F8FAFC' : 'FFFFFF';
        $sheet->getStyle("A{$rIdx}:K{$rIdx}")->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ]);

        $sheet->getStyle("A{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I{$rIdx}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getRowDimension($rIdx)->setRowHeight(22);
        $rIdx++;
    }

    foreach ($cols as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $safeFolderName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $folderTitle);
    $filename = 'Rekap_Penempatan_' . $safeFolderName . '_' . date('Ymd') . '.xlsx';

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

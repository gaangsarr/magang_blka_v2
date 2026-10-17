<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\ProdiCredentialsHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

Auth::requireSuperAdminApi();

$pdo = Database::getInstance();
$format = strtolower((string)($_GET['format'] ?? 'excel'));

try {
    $stmt = $pdo->query("
        SELECT 
            j.id AS jurusan_id,
            j.kode,
            j.jenjang,
            j.nama_jurusan,
            j.aktif AS jurusan_aktif,
            a.id AS admin_id,
            a.username,
            a.nama AS admin_nama,
            a.aktif AS admin_aktif,
            a.created_at AS admin_created_at
        FROM jurusan j
        LEFT JOIN admin a ON a.jurusan_id = j.id AND a.role = 'admin_prodi'
        ORDER BY j.id ASC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ==========================================
    // 1. FORMAT PRINT / PDF
    // ==========================================
    if ($format === 'print') {
        header('Content-Type: text/html; charset=utf-8');

        $logoPath = dirname(__DIR__, 3) . '/public/assets/img/logo_itpln.png';
        $logoBase64 = '';
        if (file_exists($logoPath)) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }
        ?>
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8">
            <title>Kredensial Akun Admin Program Studi — INTERN ITPLN</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
                    margin: 24px;
                    color: #0f172a;
                    background: #ffffff;
                }
                .header-print {
                    display: flex;
                    align-items: center;
                    gap: 16px;
                    border-bottom: 2px solid #004687;
                    padding-bottom: 16px;
                    margin-bottom: 24px;
                }
                .logo-img {
                    height: 54px;
                }
                .title-group h1 {
                    font-size: 1.25rem;
                    color: #004687;
                    margin: 0 0 4px 0;
                }
                .title-group p {
                    font-size: 0.85rem;
                    color: #64748b;
                    margin: 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 0.85rem;
                    margin-top: 12px;
                }
                th {
                    background-color: #f1f5f9;
                    color: #004687;
                    font-weight: 700;
                    text-align: left;
                    padding: 10px 12px;
                    border: 1px solid #cbd5e1;
                }
                td {
                    padding: 8px 12px;
                    border: 1px solid #cbd5e1;
                    vertical-align: middle;
                }
                tr:nth-child(even) td {
                    background-color: #f8fafc;
                }
                .badge-status {
                    display: inline-block;
                    padding: 2px 8px;
                    border-radius: 4px;
                    font-size: 0.75rem;
                    font-weight: 700;
                }
                .status-active { background: #dcfce7; color: #15803d; }
                .status-inactive { background: #fee2e2; color: #b91c1c; }
                .status-none { background: #f1f5f9; color: #64748b; }
                .cred-code {
                    font-family: monospace;
                    font-size: 0.9rem;
                    font-weight: 700;
                    background: #f1f5f9;
                    padding: 2px 6px;
                    border-radius: 4px;
                }
                .no-print {
                    margin-bottom: 20px;
                }
                .btn-print {
                    background: #004687;
                    color: #fff;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 6px;
                    font-weight: 700;
                    cursor: pointer;
                }
                @media print {
                    .no-print { display: none; }
                    body { margin: 0; }
                }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button class="btn-print" onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
            </div>
            <div class="header-print">
                <?php if ($logoBase64): ?>
                    <img src="<?= $logoBase64 ?>" alt="Logo ITPLN" class="logo-img">
                <?php endif; ?>
                <div class="title-group">
                    <h1>REKAPITULASI KREDENSIAL AKUN ADMIN PROGRAM STUDI</h1>
                    <p>Sistem Magang Terpadu (INTERN ITPLN) — Badan Layanan Karir & Alumni (BLKA)</p>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th width="4%">No</th>
                        <th width="10%">Jenjang</th>
                        <th width="8%">Kode</th>
                        <th>Program Studi</th>
                        <th width="20%">Username</th>
                        <th width="15%">Password Default</th>
                        <th width="12%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($data as $r): ?>
                        <?php 
                            $hasAcc = !empty($r['admin_id']);
                            $defPass = ProdiCredentialsHelper::generateDefaultPassword($r['nama_jurusan'], $r['kode'], $r['jenjang']);
                            $defUser = ProdiCredentialsHelper::generateDefaultUsername($r['nama_jurusan'], $r['jenjang']);
                            $user = $hasAcc ? $r['username'] : $defUser . ' (Belum Terbit)';
                        ?>
                        <tr>
                            <td style="text-align: center;"><?= $no++ ?></td>
                            <td style="text-align: center; font-weight: 700;"><?= htmlspecialchars((string)$r['jenjang']) ?></td>
                            <td style="text-align: center; font-family: monospace; font-weight: 700;"><?= htmlspecialchars((string)$r['kode']) ?></td>
                            <td><strong><?= htmlspecialchars((string)$r['nama_jurusan']) ?></strong></td>
                            <td><span class="cred-code"><?= htmlspecialchars($user) ?></span></td>
                            <td><span class="cred-code" style="color: #0369a1;"><?= htmlspecialchars($defPass) ?></span></td>
                            <td style="text-align: center;">
                                <?php if (!$hasAcc): ?>
                                    <span class="badge-status status-none">Belum Dibuat</span>
                                <?php elseif ($r['admin_aktif']): ?>
                                    <span class="badge-status status-active">Aktif</span>
                                <?php else: ?>
                                    <span class="badge-status status-inactive">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </body>
        </html>
        <?php
        exit;
    }

    // ==========================================
    // 2. FORMAT EXCEL (XLSX)
    // ==========================================
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Akun Admin Prodi');

    // Header Judul
    $sheet->setCellValue('A1', 'DAFTAR KREDENSIAL AKUN ADMIN PROGRAM STUDI (INTERN ITPLN)');
    $sheet->setCellValue('A2', 'Dicetak pada: ' . date('d F Y H:i:s') . ' WIB');
    $sheet->mergeCells('A1:G1');
    $sheet->mergeCells('A2:G2');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('64748B');

    // Kolom Header
    $headers = [
        'A4' => 'No',
        'B4' => 'Jenjang',
        'C4' => 'Kode',
        'D4' => 'Nama Program Studi',
        'E4' => 'Username',
        'F4' => 'Password Default',
        'G4' => 'Status Akun'
    ];

    foreach ($headers as $cell => $text) {
        $sheet->setCellValue($cell, $text);
    }

    $headerStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '004687']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]]
    ];
    $sheet->getStyle('A4:G4')->applyFromArray($headerStyle);
    $sheet->getRowDimension(4)->setRowHeight(26);

    $rowNum = 5;
    $no = 1;
    foreach ($data as $r) {
        $hasAcc = !empty($r['admin_id']);
        $defPass = ProdiCredentialsHelper::generateDefaultPassword($r['nama_jurusan'], $r['kode'], $r['jenjang']);
        $defUser = ProdiCredentialsHelper::generateDefaultUsername($r['nama_jurusan'], $r['jenjang']);

        $username = $hasAcc ? $r['username'] : $defUser;
        $statusStr = !$hasAcc ? 'Belum Dibuat' : ($r['admin_aktif'] ? 'Aktif' : 'Nonaktif');

        $sheet->setCellValue('A' . $rowNum, $no++);
        $sheet->setCellValue('B' . $rowNum, $r['jenjang']);
        $sheet->setCellValue('C' . $rowNum, (string)$r['kode']);
        $sheet->setCellValue('D' . $rowNum, $r['nama_jurusan']);
        $sheet->setCellValue('E' . $rowNum, $username);
        $sheet->setCellValue('F' . $rowNum, $defPass);
        $sheet->setCellValue('G' . $rowNum, $statusStr);

        $rowStyle = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E2E8F0']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ];
        $sheet->getStyle('A' . $rowNum . ':G' . $rowNum)->applyFromArray($rowStyle);
        $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('G' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $rowNum++;
    }

    foreach (range('A', 'G') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Kredensial_Admin_Prodi_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengekspor data akun prodi.')]);
}

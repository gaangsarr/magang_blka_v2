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

Auth::requireSuperAdminApi();

$pdo = Database::getInstance();
$format = strtolower((string)($_GET['format'] ?? 'excel'));

try {
    $stmt = $pdo->query("
        SELECT 
            ep.id AS entitas_id,
            ep.nama AS entitas_nama,
            ep.singkatan,
            ep.tipe,
            ep.alamat,
            ep.pic_nama,
            ep.pic_jabatan,
            ep.pic_kontak,
            ep.pic_email,
            parent.nama AS parent_nama,
            a.id AS admin_id,
            a.username,
            a.force_password_change,
            a.aktif AS admin_aktif
        FROM entitas_perusahaan ep
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
        WHERE ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding') AND ep.aktif = 1
        ORDER BY ep.parent_id ASC, ep.tipe ASC, ep.nama ASC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'print') {
        // Output clean, printable official HTML report ready for Save as PDF / Print
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
            <title>Lembar Kredensial Akun Mitra Perusahaan - BLKA ITPLN</title>
            <style>
                @page { size: A4 landscape; margin: 15mm; }
                body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1e293b; margin: 0; padding: 20px; background: #fff; }
                .header-table { width: 100%; border-bottom: 2px solid #004687; padding-bottom: 12px; margin-bottom: 20px; }
                .header-title { font-size: 18px; font-weight: bold; color: #004687; margin: 0; }
                .header-sub { font-size: 11px; color: #64748b; margin-top: 4px; }
                .info-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; margin-bottom: 20px; font-size: 11px; line-height: 1.5; }
                table.data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                table.data-table th { background: #004687; color: #fff; font-weight: 600; padding: 8px 6px; text-align: left; font-size: 10px; border: 1px solid #cbd5e1; }
                table.data-table td { padding: 7px 6px; border: 1px solid #e2e8f0; font-size: 10px; vertical-align: top; }
                table.data-table tr:nth-child(even) { background: #f8fafc; }
                .tag { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: 600; }
                .tag-active { background: #dcfce7; color: #166534; }
                .tag-pending { background: #fef9c3; color: #854d0e; }
                .tag-empty { background: #fee2e2; color: #991b1b; }
                .mono { font-family: 'Consolas', 'Courier New', monospace; font-weight: bold; }
                .footer { margin-top: 30px; display: flex; justify-content: space-between; font-size: 11px; }
                .btn-print { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #004687; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
                @media print { .btn-print { display: none; } body { padding: 0; } }
            </style>
        </head>
        <body>
            <button class="btn-print" onclick="window.print()" style="display: flex; align-items: center; gap: 8px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                <span>Cetak / Simpan ke PDF</span>
            </button>

            <table class="header-table">
                <tr>
                    <td style="vertical-align: middle;">
                        <div style="display: flex; align-items: center; gap: 16px;">
                            <?php if (!empty($logoBase64)): ?>
                                <img src="<?= $logoBase64 ?>" alt="Logo ITPLN" style="height: 48px; width: auto; object-fit: contain; flex-shrink: 0;">
                            <?php else: ?>
                                <div style="font-weight: 900; font-size: 24px; color: #004687; letter-spacing: -1px; flex-shrink: 0;">ITPLN</div>
                            <?php endif; ?>
                            <div>
                                <div class="header-title">LEMBAR KREDENSIAL AKUN MITRA PERUSAHAAN</div>
                                <div class="header-sub">Sistem Informasi Pendaftaran Magang — Bagian Layanan Karir & Alumni (BLKA) Institut Teknologi PLN</div>
                            </div>
                        </div>
                    </td>
                    <td style="text-align: right; vertical-align: middle; font-size: 10px; color: #64748b; white-space: nowrap; width: 180px;">
                        Tanggal Dokumen: <?= date('d F Y') ?><br>
                        Total Unit Terdata: <?= count($data) ?> Unit
                    </td>
                </tr>
            </table>

            <div class="info-box">
                <strong>PETUNJUK PENGGUNAAN KREDENSIAL UNTUK ADMIN MITRA PERUSAHAAN:</strong><br>
                1. Kredensial di bawah ini digunakan oleh PIC / Bagian SDM unit mitra untuk mengakses Dashboard Perusahaan di URL: <strong>https://magang.itpln.ac.id/perusahaan/</strong> atau <strong>/admin/login.html</strong>.<br>
                2. Saat login pertama kali menggunakan Username dan Password Awal, sistem akan <strong>mewajibkan perubahan password baru</strong> dan melengkapi data kontak PIC.<br>
                3. Jika terdapat kendala login atau lupa password, hubungi Administrator BLKA ITPLN.
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 25px; text-align: center;">No</th>
                        <th>Nama Entitas / Unit Kantor</th>
                        <th style="width: 85px;">Tipe Unit</th>
                        <th>Unit Induk</th>
                        <th style="width: 110px;">Username Login</th>
                        <th style="width: 140px;">Password Awal (First-Login)</th>
                        <th style="width: 95px; text-align: center;">Status Akun</th>
                        <th>Kontak PIC Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($data as $r): 
                        $singkat = !empty($r['singkatan']) ? $r['singkatan'] : $r['entitas_nama'];
                        $cleanCode = strtoupper(substr((string)preg_replace('/[^a-zA-Z0-9]/', '', $singkat), 0, 8));
                        if ($cleanCode === '') $cleanCode = 'UNIT' . $r['entitas_id'];
                        $defaultTempPass = 'PLN-' . $cleanCode . '-2026';
                    ?>
                    <tr>
                        <td style="text-align: center;"><?= $no++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($r['entitas_nama']) ?></strong>
                            <?php if (!empty($r['singkatan'])): ?>
                                <span style="color: #64748b;">(<?= htmlspecialchars($r['singkatan']) ?>)</span>
                            <?php endif; ?>
                            <div style="font-size: 9px; color: #64748b; margin-top: 2px;"><?= htmlspecialchars($r['alamat'] ?? '-') ?></div>
                        </td>
                        <td><?= htmlspecialchars(strtoupper(str_replace('_', ' ', $r['tipe']))) ?></td>
                        <td><?= htmlspecialchars($r['parent_nama'] ?? 'Kantor Pusat / Holding') ?></td>
                        <td class="mono" style="color: #004687; font-size: 11px;">
                            <?= !empty($r['username']) ? htmlspecialchars($r['username']) : '<span style="color:#94a3b8; font-style:italic;">Belum dibuat</span>' ?>
                        </td>
                        <td class="mono" style="font-size: 11px;">
                            <?php if (empty($r['admin_id'])): ?>
                                <span style="color:#94a3b8; font-style:italic;">-</span>
                            <?php elseif (!empty($r['force_password_change'])): ?>
                                <span style="color: #0369a1; font-weight: bold;"><?= htmlspecialchars($defaultTempPass) ?></span>
                                <div style="font-size: 8px; color: #d97706; font-style: italic;">(Wajib diubah saat aktivasi)</div>
                            <?php else: ?>
                                <span style="color: #059669; font-size: 9px; font-weight: 600;">✓ Telah Diaktivasi PIC</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if (empty($r['admin_id'])): ?>
                                <span class="tag tag-empty">Belum Ada Akun</span>
                            <?php elseif (!empty($r['force_password_change'])): ?>
                                <span class="tag tag-pending">Perlu First-Login</span>
                            <?php else: ?>
                                <span class="tag tag-active">Aktif & Terverifikasi</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['pic_nama'])): ?>
                                <strong><?= htmlspecialchars($r['pic_nama']) ?></strong><br>
                                <span style="color: #64748b; font-size: 9px;"><?= htmlspecialchars($r['pic_jabatan'] ?? 'PIC') ?> • <?= htmlspecialchars($r['pic_kontak'] ?? '-') ?></span>
                            <?php else: ?>
                                <span style="color: #94a3b8; font-style: italic;">Belum diisi</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="footer">
                <div>
                    <em>Dokumen ini bersifat rahasia dan diterbitkan secara resmi oleh BLKA Institut Teknologi PLN.</em>
                </div>
                <div style="text-align: right;">
                    Jakarta, <?= date('d F Y') ?><br>
                    <strong>Kepala Bagian Layanan Karir & Alumni</strong><br><br><br><br>
                    <u>( Tim Administrator BLKA )</u>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Default: Export Excel
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Kredensial Admin Perusahaan');

    // Header Styling
    $sheet->mergeCells('A1:I1');
    $sheet->setCellValue('A1', 'DAFTAR AKUN DAN KREDENSIAL ADMIN MITRA PERUSAHAAN — BLKA ITPLN');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF004687'));
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells('A2:I2');
    $sheet->setCellValue('A2', 'Diekspor pada: ' . date('d-m-Y H:i:s') . ' | Total Entitas: ' . count($data));
    $sheet->getStyle('A2')->getFont()->setSize(10)->setItalic(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    // Table Header
    $headers = ['No', 'Nama Entitas / Unit Kantor', 'Singkatan', 'Tipe Unit', 'Unit Induk', 'Username Login', 'Kata Sandi Awal (First-Login)', 'Status Akun', 'PIC / Kontak'];
    $colLetter = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($colLetter . '4', $h);
        $colLetter++;
    }

    $sheet->getStyle('A4:I4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '004687']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(4)->setRowHeight(26);

    $rowNum = 5;
    $no = 1;
    foreach ($data as $r) {
        $statusText = 'Belum Ada Akun';
        $passText = '-';
        if (!empty($r['admin_id'])) {
            if (!empty($r['force_password_change'])) {
                $statusText = 'Belum Ganti Password (First-Login)';
                $singkat = !empty($r['singkatan']) ? $r['singkatan'] : $r['entitas_nama'];
                $cleanCode = strtoupper(substr((string)preg_replace('/[^a-zA-Z0-9]/', '', $singkat), 0, 8));
                if ($cleanCode === '') $cleanCode = 'UNIT' . $r['entitas_id'];
                $passText = 'PLN-' . $cleanCode . '-2026';
            } else {
                $statusText = 'Aktif (Telah Ganti Password)';
                $passText = 'Telah Diganti PIC';
            }
        }

        $picInfo = !empty($r['pic_nama']) ? "{$r['pic_nama']} ({$r['pic_kontak']})" : '-';

        $sheet->setCellValue('A' . $rowNum, $no++);
        $sheet->setCellValue('B' . $rowNum, $r['entitas_nama']);
        $sheet->setCellValue('C' . $rowNum, $r['singkatan'] ?? '-');
        $sheet->setCellValue('D' . $rowNum, strtoupper(str_replace('_', ' ', $r['tipe'])));
        $sheet->setCellValue('E' . $rowNum, $r['parent_nama'] ?? 'Kantor Pusat / Holding');
        $sheet->setCellValue('F' . $rowNum, $r['username'] ?? '-');
        $sheet->setCellValue('G' . $rowNum, $passText);
        $sheet->setCellValue('H' . $rowNum, $statusText);
        $sheet->setCellValue('I' . $rowNum, $picInfo);

        $sheet->getStyle('A' . $rowNum)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F' . $rowNum)->getFont()->setBold(true);
        $sheet->getStyle('G' . $rowNum)->getFont()->setBold(true);

        $rowNum++;
    }

    // Border
    $lastRow = $rowNum - 1;
    $sheet->getStyle('A4:I' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCBD5E1'));

    // Auto Column Width
    foreach (range('A', 'I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $fileName = 'Kredensial_Admin_Perusahaan_' . date('Ymd_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo "Terjadi kesalahan saat mengekspor data: " . htmlspecialchars($e->getMessage());
}

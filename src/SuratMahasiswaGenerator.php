<?php

declare(strict_types=1);

namespace App;

use PDO;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\UserException;
use App\SuratGenerator;

/**
 * Class SuratMahasiswaGenerator
 * Menghasilkan Surat Rekomendasi & Pengantar Magang resmi per mahasiswa dalam format PDF.
 */
class SuratMahasiswaGenerator
{
    /**
     * Dapatkan path root aplikasi
     */
    private static function getRootPath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Helper konversi file gambar ke Base64 Data URI
     */
    private static function getBase64Image(string $filePath): ?string
    {
        if (file_exists($filePath) && is_readable($filePath)) {
            $type = pathinfo($filePath, PATHINFO_EXTENSION);
            $data = file_get_contents($filePath);
            return 'data:image/' . $type . ';base64,' . base64_encode($data);
        }
        return null;
    }

    /**
     * Ambil data lengkap pendaftaran untuk surat
     */
    public static function getRegistrationData(PDO $pdo, int $pendaftaranId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT 
                p.id AS pendaftaran_id,
                p.mahasiswa_id,
                p.periode_id,
                p.status,
                p.program,
                p.nama_snapshot AS nama,
                p.no_hp,
                p.ipk,
                p.jumlah_sks,
                p.alamat,
                p.rt,
                p.rw,
                p.kelurahan,
                p.kecamatan,
                p.kota_kabupaten,
                p.provinsi,
                p.submitted_at,
                p.is_dipindahkan,
                m.nim,
                m.email AS mhs_email,
                j.nama_jurusan AS jurusan_nama,
                pr.nama AS periode_nama,
                pr.tanggal_mulai,
                pr.tanggal_selesai,
                pr.pengumuman_dibuka,
                e.nama AS nama_unit,
                e.singkatan AS singkatan_unit,
                e.alamat AS alamat_unit,
                e.tipe AS tipe_unit,
                parent.nama AS nama_induk
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            LEFT JOIN jurusan j ON m.jurusan_id = j.id
            JOIN periode pr ON p.periode_id = pr.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Generate PDF Surat Pengantar Mahasiswa
     * 
     * @param PDO $pdo
     * @param int $pendaftaranId
     * @param int|null $checkMahasiswaId Jika diisi, memvalidasi kepemilikan dokumen (IDOR guard)
     * @return array ['pdf' => string (binary), 'filename' => string, 'nomor_surat' => string]
     * @throws UserException
     */
    public static function generatePdf(PDO $pdo, int $pendaftaranId, ?int $checkMahasiswaId = null): array
    {
        $data = self::getRegistrationData($pdo, $pendaftaranId);
        if (!$data) {
            throw new UserException('Data pendaftaran tidak ditemukan.', 404);
        }

        // 1. Validasi Kepemilikan Mahasiswa (IDOR Guard)
        if ($checkMahasiswaId !== null && (int)$data['mahasiswa_id'] !== $checkMahasiswaId) {
            throw new UserException('Akses ditolak: Dokumen rekomendasi ini bukan milik akun Anda.', 403);
        }

        // 2. Validasi Status Penerimaan
        if ($data['status'] !== 'diterima' && $data['status'] !== 'dipindahkan') {
            throw new UserException('Surat Pengantar & Rekomendasi Magang hanya dapat diterbitkan bagi mahasiswa yang berstatus DITERIMA.', 403);
        }

        // 3. Validasi Pengumuman Dibuka
        if (empty($data['pengumuman_dibuka'])) {
            throw new UserException('Pengumuman hasil seleksi magang untuk periode ini belum resmi dibuka oleh BLKA ITPLN.', 403);
        }

        // 4. Konfigurasi Surat Periode
        $cfg = SuratGenerator::getConfig($pdo, (int)$data['periode_id']);

        // Nomor Surat
        $paddedNo = str_pad((string)$pendaftaranId, 4, '0', STR_PAD_LEFT);
        $tpl = $cfg['nomor_surat_template'] ?? '';
        if (str_contains($tpl, '{nomor}')) {
            $nomorSurat = str_replace('{nomor}', $paddedNo, $tpl);
        } else {
            $nomorSurat = $paddedNo . '/BLKA-ITPLN/REK-MAGANG/' . date('m/Y');
        }

        // Tanggal Surat
        $tanggalSurat = !empty($cfg['tanggal_surat']) ? $cfg['tanggal_surat'] : ('Jakarta, ' . SuratGenerator::formatIndonesianDate(date('Y-m-d')));

        // Program Map
        $progMap = [
            '1_bulan' => 'Magang 1 Bulan',
            '3_bulan' => 'Magang 3 Bulan',
            '4_bulan' => 'Magang 4 Bulan',
            '5_bulan' => 'Magang 5 Bulan (KRS)'
        ];
        $programNama = $progMap[$data['program']] ?? 'Magang Mahasiswa';

        // Logo Base64
        $root = self::getRootPath();
        $logoItplnBase64 = self::getBase64Image($root . '/public/assets/img/logo_itpln.png');
        $logoPlnBase64 = self::getBase64Image($root . '/public/assets/img/logo_pln.png');

        // Security Hash
        $verifyCode = 'REMATE-' . strtoupper(substr(hash('sha256', (string)$pendaftaranId . $data['nim'] . 'BLKA_REMATE_SECURE_SALT_2026'), 0, 10));

        // Format HTML Dokumen
        $html = self::buildHtml([
            'nomor_surat'           => $nomorSurat,
            'tanggal_surat'         => $tanggalSurat,
            'nama_penandatangan'    => $cfg['nama_penandatangan'] ?? 'Ir. Purnomo, S.T., M.T.',
            'jabatan_penandatangan' => $cfg['jabatan_penandatangan'] ?? 'Wakil Rektor III Bidang Kemahasiswaan',
            'tahun_akademik'        => $cfg['tahun_akademik'] ?? (date('Y') . '/' . (date('Y') + 1)),
            'mhs_nama'              => $data['nama'],
            'mhs_nim'               => $data['nim'],
            'mhs_jurusan'           => $data['jurusan_nama'] ?? 'Program Sarjana ITPLN',
            'mhs_ipk'               => number_format((float)$data['ipk'], 2),
            'mhs_sks'               => (string)$data['jumlah_sks'],
            'mhs_hp'                => $data['no_hp'],
            'program_nama'          => $programNama,
            'periode_nama'          => $data['periode_nama'],
            'nama_unit'             => $data['nama_unit'],
            'singkatan_unit'        => $data['singkatan_unit'] ?: '',
            'alamat_unit'           => $data['alamat_unit'] ?: 'Lingkungan Kerja PT PLN (Persero)',
            'nama_induk'            => $data['nama_induk'] ?: '',
            'logo_itpln'            => $logoItplnBase64,
            'logo_pln'              => $logoPlnBase64,
            'verify_code'           => $verifyCode,
            'pendaftaran_id'        => $pendaftaranId
        ]);

        // Inisialisasi Dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $data['nama']);
        $filename = "Surat_Rekomendasi_Magang_{$data['nim']}_{$safeName}.pdf";

        return [
            'pdf'         => $pdfOutput,
            'filename'    => $filename,
            'nomor_surat' => $nomorSurat
        ];
    }

    /**
     * Susun Template HTML Surat Resmi PLN & ITPLN
     */
    private static function buildHtml(array $v): string
    {
        $logoItplnImg = !empty($v['logo_itpln']) 
            ? '<img src="' . $v['logo_itpln'] . '" style="height: 65px; max-width: 140px; object-fit: contain;">' 
            : '<div style="font-weight: bold; color: #005082; font-size: 14px;">ITPLN</div>';

        $logoPlnImg = !empty($v['logo_pln']) 
            ? '<img src="' . $v['logo_pln'] . '" style="height: 60px; max-width: 130px; object-fit: contain;">' 
            : '<div style="font-weight: bold; color: #00A2B9; font-size: 14px;">PLN</div>';

        $unitDisplay = htmlspecialchars($v['nama_unit'], ENT_QUOTES, 'UTF-8');
        if (!empty($v['singkatan_unit'])) {
            $unitDisplay .= ' (' . htmlspecialchars($v['singkatan_unit'], ENT_QUOTES, 'UTF-8') . ')';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Pengantar & Rekomendasi Magang - {$v['mhs_nim']}</title>
    <style>
        @page {
            margin: 18mm 20mm 18mm 20mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10.5pt;
            line-height: 1.45;
            color: #111827;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .header-table td {
            vertical-align: middle;
            padding: 0;
        }
        .header-text {
            text-align: center;
            padding: 0 10px;
        }
        .header-text .inst-sub {
            font-size: 9pt;
            letter-spacing: 0.5px;
            font-weight: bold;
            color: #4B5563;
            margin: 0;
            text-transform: uppercase;
        }
        .header-text .inst-main {
            font-size: 13pt;
            font-weight: bold;
            color: #0B192C;
            margin: 2px 0;
            letter-spacing: 0.5px;
        }
        .header-text .inst-unit {
            font-size: 11pt;
            font-weight: bold;
            color: #00A2B9;
            margin: 0 0 3px 0;
            letter-spacing: 0.3px;
        }
        .header-text .inst-address {
            font-size: 7.5pt;
            color: #4B5563;
            margin: 0;
            line-height: 1.3;
        }
        .header-divider-top {
            border-top: 2.5px solid #0B192C;
            margin-top: 8px;
            margin-bottom: 2px;
        }
        .header-divider-bottom {
            border-top: 1px solid #00A2B9;
            margin-bottom: 18px;
        }
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .meta-table td {
            vertical-align: top;
            padding: 2px 0;
            font-size: 10pt;
        }
        .recipient-block {
            margin-bottom: 16px;
            font-size: 10pt;
            line-height: 1.4;
        }
        .recipient-block .to {
            font-weight: bold;
            color: #111827;
        }
        .content-p {
            text-align: justify;
            margin-bottom: 12px;
            font-size: 10pt;
            line-height: 1.45;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            background-color: #FAFAFA;
            border: 1px solid #E5E7EB;
        }
        .data-table td {
            padding: 6px 12px;
            font-size: 9.5pt;
            vertical-align: top;
            border-bottom: 1px solid #F3F4F6;
        }
        .data-table .label {
            width: 28%;
            font-weight: bold;
            color: #374151;
        }
        .data-table .colon {
            width: 3%;
            text-align: center;
        }
        .data-table .value {
            width: 69%;
            color: #111827;
        }
        .ketentuan-list {
            margin: 10px 0 16px 20px;
            padding: 0;
            font-size: 9.5pt;
            line-height: 1.45;
        }
        .ketentuan-list li {
            margin-bottom: 5px;
            text-align: justify;
        }
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .signature-table td {
            vertical-align: top;
            padding: 0;
        }
        .auth-card {
            border: 1px solid #CFE2FF;
            background: #F8FAFC;
            padding: 8px 12px;
            border-radius: 6px;
            width: 90%;
        }
        .auth-badge {
            font-size: 7.5pt;
            color: #0369A1;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .auth-code {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            font-size: 8.5pt;
            color: #0B192C;
            margin-bottom: 3px;
        }
        .auth-desc {
            font-size: 6.5pt;
            color: #64748B;
            line-height: 1.25;
            margin: 0;
        }
        .signer-block {
            text-align: center;
            width: 250px;
            float: right;
        }
        .signer-date {
            font-size: 10pt;
            margin-bottom: 3px;
        }
        .signer-inst {
            font-size: 9.5pt;
            font-weight: bold;
            color: #111827;
            margin-bottom: 2px;
        }
        .signer-role {
            font-size: 9pt;
            color: #4B5563;
        }
        .signer-space {
            height: 60px;
        }
        .signer-name {
            font-size: 10pt;
            font-weight: bold;
            color: #0B192C;
            text-decoration: underline;
        }
        .footer-note {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #9CA3AF;
            text-align: center;
            border-top: 0.5px solid #E5E7EB;
            padding-top: 4px;
        }
    </style>
</head>
<body>

    <!-- KOP SURAT RESMI -->
    <table class="header-table">
        <tr>
            <td style="width: 18%; text-align: left;">
                {$logoItplnImg}
            </td>
            <td style="width: 64%;" class="header-text">
                <div class="inst-sub">Yayasan Pendidikan & Kesejahteraan PT PLN (Persero)</div>
                <div class="inst-main">INSTITUT TEKNOLOGI PLN</div>
                <div class="inst-unit">BADAN LAYANAN KARIR & ALUMNI (BLKA)</div>
                <div class="inst-address">
                    Kampus Menara PLN, Jl. Lingkar Luar Barat, Duri Kosambi, Cengkareng, Jakarta Barat 11750<br>
                    Telp: (021) 5440342 | Email: blka@itpln.ac.id | Web: https://itpln.ac.id
                </div>
            </td>
            <td style="width: 18%; text-align: right;">
                {$logoPlnImg}
            </td>
        </tr>
    </table>

    <div class="header-divider-top"></div>
    <div class="header-divider-bottom"></div>

    <!-- META SURAT -->
    <table class="meta-table">
        <tr>
            <td style="width: 14%; font-weight: bold;">Nomor</td>
            <td style="width: 3%;">:</td>
            <td style="width: 48%;">{$v['nomor_surat']}</td>
            <td style="width: 35%; text-align: right;">{$v['tanggal_surat']}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Lampiran</td>
            <td>:</td>
            <td>-</td>
            <td></td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Perihal</td>
            <td>:</td>
            <td colspan="2"><strong>Surat Rekomendasi & Pengantar Pelaksanaan Praktik Magang</strong></td>
        </tr>
    </table>

    <!-- TUJUAN SURAT -->
    <div class="recipient-block">
        Kepada Yth.<br>
        <span class="to">Pimpinan / Senior Manager / Manager Human Capital</span><br>
        <span class="to">{$unitDisplay}</span><br>
        di Tempat
    </div>

    <!-- PEMBUKA -->
    <div class="content-p">
        Dengan hormat,<br>
        Sehubungan dengan penyelenggaraan Program Praktik Kerja / Magang Mahasiswa Institut Teknologi PLN (ITPLN) Tahun Akademik <strong>{$v['tahun_akademik']}</strong>, bersama ini kami sampaikan bahwa mahasiswa di bawah ini telah dinyatakan <strong>LOLOS SELEKSI RESMI</strong> dan diberikan rekomendasi penempatan magang pada unit yang Bapak/Ibu pimpin:
    </div>

    <!-- DATA MAHASISWA -->
    <table class="data-table">
        <tr>
            <td class="label">Nama Lengkap</td>
            <td class="colon">:</td>
            <td class="value"><strong>{$v['mhs_nama']}</strong></td>
        </tr>
        <tr>
            <td class="label">Nomor Induk Mahasiswa (NIM)</td>
            <td class="colon">:</td>
            <td class="value"><strong>{$v['mhs_nim']}</strong></td>
        </tr>
        <tr>
            <td class="label">Program Studi</td>
            <td class="colon">:</td>
            <td class="value">{$v['mhs_jurusan']}</td>
        </tr>
        <tr>
            <td class="label">Kontak / WhatsApp</td>
            <td class="colon">:</td>
            <td class="value">{$v['mhs_hp']}</td>
        </tr>
        <tr>
            <td class="label">Program Magang</td>
            <td class="colon">:</td>
            <td class="value"><strong>{$v['program_nama']}</strong></td>
        </tr>
        <tr>
            <td class="label">Gelombang / Periode</td>
            <td class="colon">:</td>
            <td class="value">{$v['periode_nama']}</td>
        </tr>
        <tr>
            <td class="label">Unit Penempatan PLN</td>
            <td class="colon">:</td>
            <td class="value"><strong>{$unitDisplay}</strong></td>
        </tr>
        <tr>
            <td class="label">Alamat Unit Kerja</td>
            <td class="colon">:</td>
            <td class="value">{$v['alamat_unit']}</td>
        </tr>
    </table>

    <!-- KETENTUAN -->
    <div class="content-p" style="margin-bottom: 4px;">
        Sehubungan dengan hal tersebut, kami menyampaikan beberapa hal sebagai berikut:
    </div>
    <ol class="ketentuan-list">
        <li>Mahasiswa yang bersangkutan telah memenuhi seluruh persyaratan kualifikasi akademis serta administratif yang dipersyaratkan oleh Institut Teknologi PLN.</li>
        <li>Selama masa pelaksanaan kegiatan magang, mahasiswa diwajibkan untuk mematuhi seluruh peraturan kedinasan, tata tertib, etika profesional, dan prosedur Keselamatan & Kesehatan Kerja (K3) yang berlaku di lingkungan PT PLN (Persero).</li>
        <li>Kami memohon perkenan dan dukungan Bapak/Ibu untuk dapat menerima mahasiswa kami serta menugaskan mentor/pembimbing lapangan guna memberikan arahan operasional selama kegiatan magang berlangsung.</li>
    </ol>

    <!-- PENUTUP -->
    <div class="content-p">
        Demikian surat rekomendasi dan pengantar ini kami sampaikan sebagai kelengkapan administratif. Atas perhatian, penerimaan, dan kerjasama yang terjalin dengan baik, kami mengucapkan terima kasih.
    </div>

    <!-- TANDA TANGAN & VALIDASI -->
    <table class="signature-table">
        <tr>
            <td style="width: 50%; vertical-align: middle;">
                <div class="auth-card">
                    <div class="auth-badge">Otentikasi Dokumen Digital</div>
                    <div class="auth-code">{$v['verify_code']}</div>
                    <p class="auth-desc">
                        Dokumen ini diterbitkan secara sah oleh Sistem Rekrutmen & Magang Terpadu (REMATE) BLKA Institut Teknologi PLN. Dinyatakan berlaku dan sah tanpa memerlukan tanda tangan atau cap basah.
                    </p>
                </div>
            </td>
            <td style="width: 50%;">
                <div class="signer-block">
                    <div class="signer-date">Jakarta, {$v['tanggal_surat']}</div>
                    <div class="signer-inst">Institut Teknologi PLN</div>
                    <div class="signer-role">{$v['jabatan_penandatangan']}</div>
                    <div class="signer-space"></div>
                    <div class="signer-name">{$v['nama_penandatangan']}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        REMATE ITPLN &bull; Dicetak secara otomatis melalui portal resmi https://magang.itpln.ac.id &bull; Lembar 1 / 1
    </div>

</body>
</html>
HTML;
    }
}

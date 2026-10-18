<?php

declare(strict_types=1);

namespace App;

use PDO;
use ZipArchive;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\UserException;
use App\SuratGenerator;

/**
 * Class SuratPengantarGenerator
 * Menangani logika pembuatan dan pengelolaan Surat Pengantar Mahasiswa per Unit PLN dalam format PDF.
 */
class SuratPengantarGenerator
{
    /**
     * Dapatkan path root aplikasi
     */
    public static function getRootPath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Helper konversi file gambar ke Base64 Data URI
     */
    public static function getBase64Image(string $filePath): ?string
    {
        if (file_exists($filePath) && is_readable($filePath)) {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeMap = [
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                'svg'  => 'image/svg+xml'
            ];
            $type = $mimeMap[$ext] ?? 'image/png';
            $data = file_get_contents($filePath);
            if ($data !== false) {
                return 'data:' . $type . ';base64,' . base64_encode($data);
            }
        }
        return null;
    }

    /**
     * Ambil konfigurasi surat pengantar mahasiswa untuk periode tertentu
     */
    public static function getConfig(PDO $pdo, int $periodeId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM konfigurasi_surat_pengantar WHERE periode_id = ?");
        $stmt->execute([$periodeId]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ambil info periode
        $stmtP = $pdo->prepare("SELECT id, nama, status, pengumuman_dibuka FROM periode WHERE id = ?");
        $stmtP->execute([$periodeId]);
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC) ?: ['id' => $periodeId, 'nama' => 'Periode Magang', 'status' => 'dibuka', 'pengumuman_dibuka' => 0];

        $currentYear = (int)date('Y');
        $currentMonth = date('m');
        $tahunAkademikDefault = $currentYear . '/' . ($currentYear + 1);
        $tglDefault = 'Jakarta, ' . SuratGenerator::formatIndonesianDate(date('Y-m-d'));
        $perihalDefault = 'Penyampaian Penempatan Mahasiswa Magang ITPLN';

        if (!$cfg) {
            return [
                'id'                   => 0,
                'periode_id'           => $periodeId,
                'periode_nama'         => $periode['nama'] ?? 'Periode Magang',
                'pengumuman_dibuka'    => (int)($periode['pengumuman_dibuka'] ?? 0),
                'nomor_pokok'          => '',
                'format_sufiks'        => '/Kt/2/D06',
                'bulan'                => $currentMonth,
                'tahun'                => (string)$currentYear,
                'tanggal_surat'        => $tglDefault,
                'tahun_akademik'       => $tahunAkademikDefault,
                'perihal'              => $perihalDefault,
                'jabatan_penandatangan'=> 'Wakil Rektor III Bidang Kemahasiswaan',
                'nama_penandatangan'   => 'Ir. Purnomo, S.T., M.T.',
                'nip_penandatangan'    => '',
                'ttd_image_path'       => '',
                'is_default'           => true
            ];
        }

        $cfg['periode_nama'] = $periode['nama'] ?? 'Periode Magang';
        $cfg['pengumuman_dibuka'] = (int)($periode['pengumuman_dibuka'] ?? 0);
        $cfg['is_default'] = false;
        if (empty($cfg['format_sufiks'])) {
            $cfg['format_sufiks'] = '/Kt/2/D06';
        }
        if (empty($cfg['bulan'])) {
            $cfg['bulan'] = $currentMonth;
        }
        if (empty($cfg['tahun'])) {
            $cfg['tahun'] = (string)$currentYear;
        }
        return $cfg;
    }

    /**
     * Simpan / perbarui konfigurasi surat pengantar mahasiswa
     */
    public static function saveConfig(PDO $pdo, int $periodeId, array $data): bool
    {
        $nomorPokok = trim((string)($data['nomor_pokok'] ?? ''));
        $formatSufiks = trim((string)($data['format_sufiks'] ?? '/Kt/2/D06'));
        if ($formatSufiks === '') {
            $formatSufiks = '/Kt/2/D06';
        }
        // Pastikan format_sufiks diawali dengan '/'
        if (!str_starts_with($formatSufiks, '/')) {
            $formatSufiks = '/' . $formatSufiks;
        }
        // Hapus trailing slash jika ada
        $formatSufiks = rtrim($formatSufiks, '/');

        $bulan = trim((string)($data['bulan'] ?? date('m')));
        if ($bulan === '') {
            $bulan = date('m');
        }
        $bulan = str_pad($bulan, 2, '0', STR_PAD_LEFT);

        $tahun = trim((string)($data['tahun'] ?? date('Y')));
        if ($tahun === '') {
            $tahun = date('Y');
        }

        $tanggalSurat = trim((string)($data['tanggal_surat'] ?? ''));
        if ($tanggalSurat === '') {
            $tanggalSurat = 'Jakarta, ' . SuratGenerator::formatIndonesianDate(date('Y-m-d'));
        }

        $tahunAkademik = trim((string)($data['tahun_akademik'] ?? (date('Y') . '/' . (date('Y') + 1))));
        $perihal = trim((string)($data['perihal'] ?? 'Penyampaian Penempatan Mahasiswa Magang ITPLN'));
        $jabatan = trim((string)($data['jabatan_penandatangan'] ?? 'Wakil Rektor III Bidang Kemahasiswaan'));
        $nama = trim((string)($data['nama_penandatangan'] ?? 'Ir. Purnomo, S.T., M.T.'));
        $nip = trim((string)($data['nip_penandatangan'] ?? ''));
        $ttdPath = isset($data['ttd_image_path']) ? trim((string)$data['ttd_image_path']) : null;
        if ($ttdPath !== null && $ttdPath !== '') {
            $cleanTtd = ltrim(str_replace('\\', '/', $ttdPath), '/');
            if (preg_match('/^storage\/signatures\/ttd_periode_\d+_\d+\.(png|jpg|jpeg|webp)$/', $cleanTtd)) {
                $ttdPath = $cleanTtd;
            } else {
                $ttdPath = null;
            }
        }

        $sql = "
            INSERT INTO konfigurasi_surat_pengantar (
                periode_id,
                nomor_pokok,
                format_sufiks,
                bulan,
                tahun,
                tanggal_surat,
                tahun_akademik,
                perihal,
                jabatan_penandatangan,
                nama_penandatangan,
                nip_penandatangan,
                ttd_image_path,
                updated_at
            ) VALUES (
                :periode_id,
                :nomor_pokok,
                :format_sufiks,
                :bulan,
                :tahun,
                :tanggal_surat,
                :tahun_akademik,
                :perihal,
                :jabatan_penandatangan,
                :nama_penandatangan,
                :nip_penandatangan,
                :ttd_image_path,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                nomor_pokok = VALUES(nomor_pokok),
                format_sufiks = VALUES(format_sufiks),
                bulan = VALUES(bulan),
                tahun = VALUES(tahun),
                tanggal_surat = VALUES(tanggal_surat),
                tahun_akademik = VALUES(tahun_akademik),
                perihal = VALUES(perihal),
                jabatan_penandatangan = VALUES(jabatan_penandatangan),
                nama_penandatangan = VALUES(nama_penandatangan),
                nip_penandatangan = VALUES(nip_penandatangan),
                ttd_image_path = COALESCE(VALUES(ttd_image_path), konfigurasi_surat_pengantar.ttd_image_path),
                updated_at = NOW()
        ";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':periode_id'            => $periodeId,
            ':nomor_pokok'           => $nomorPokok,
            ':format_sufiks'         => $formatSufiks,
            ':bulan'                 => $bulan,
            ':tahun'                 => $tahun,
            ':tanggal_surat'         => $tanggalSurat,
            ':tahun_akademik'        => $tahunAkademik,
            ':perihal'               => $perihal,
            ':jabatan_penandatangan' => $jabatan,
            ':nama_penandatangan'    => $nama,
            ':nip_penandatangan'     => $nip,
            ':ttd_image_path'        => $ttdPath,
        ]);
    }

    /**
     * Format Nomor Surat: [nomorsurat].[kodeunit][format_sufiks]/[bulan]/[tahun]
     * Contoh: 0001.01/Kt/2/D06/10/2026
     */
    public static function formatNomorSurat(
        string $nomorPokok,
        int $unitSequence,
        string $formatSufiks = '/Kt/2/D06',
        string $bulan = '',
        string $tahun = ''
    ): string {
        $cleanPokok = trim($nomorPokok);
        if ($cleanPokok === '') {
            $cleanPokok = '[Nomor Belum Diset]';
        }

        $kodeUnit = str_pad((string)$unitSequence, 2, '0', STR_PAD_LEFT);

        $cleanSufiks = trim($formatSufiks);
        if ($cleanSufiks === '') {
            $cleanSufiks = '/Kt/2/D06';
        }
        if (!str_starts_with($cleanSufiks, '/')) {
            $cleanSufiks = '/' . $cleanSufiks;
        }
        $cleanSufiks = rtrim($cleanSufiks, '/');

        $b = trim($bulan) !== '' ? str_pad(trim($bulan), 2, '0', STR_PAD_LEFT) : date('m');
        $t = trim($tahun) !== '' ? trim($tahun) : date('Y');

        return "{$cleanPokok}.{$kodeUnit}{$cleanSufiks}/{$b}/{$t}";
    }

    /**
     * Ambil daftar unit yang memiliki mahasiswa diterima beserta hitungan mahasiswa
     */
    public static function getUnitsSummary(PDO $pdo, int $periodeId): array
    {
        $sql = "
            SELECT 
                e.id AS unit_id,
                e.nama AS unit_nama,
                e.alamat AS unit_alamat,
                e.singkatan AS unit_singkatan,
                COUNT(p.id) AS total_mahasiswa
            FROM pendaftaran p
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            WHERE p.periode_id = :pid AND p.status IN ('diterima', 'dipindahkan')
            GROUP BY e.id, e.nama, e.alamat, e.singkatan
            ORDER BY e.nama ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $periodeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Ambil seluruh mahasiswa yang diterima / dipindahkan pada unit tertentu
     */
    public static function getStudentsForUnit(PDO $pdo, int $periodeId, int $unitEntitasId): array
    {
        $sql = "
            SELECT 
                p.id AS pendaftaran_id,
                m.nim,
                p.nama_snapshot AS nama,
                COALESCE(p.no_hp, '-') AS no_hp,
                COALESCE(j.nama_jurusan, 'Program Sarjana ITPLN') AS prodi,
                p.program
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            LEFT JOIN jurusan j ON m.jurusan_id = j.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            WHERE p.periode_id = :pid AND upp.entitas_id = :uid AND p.status IN ('diterima', 'dipindahkan')
            ORDER BY m.nim ASC, p.nama_snapshot ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $periodeId, ':uid' => $unitEntitasId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Generate Dokumen PDF Surat Pengantar untuk satu Unit tertentu
     * 
     * @return array ['pdf' => binary, 'filename' => string, 'nomor_surat' => string, 'unit_nama' => string]
     */
    public static function generatePdfForUnit(PDO $pdo, int $periodeId, int $unitEntitasId): array
    {
        $cfg = self::getConfig($pdo, $periodeId);
        $units = self::getUnitsSummary($pdo, $periodeId);

        $targetUnit = null;
        $unitIndex = 0;
        foreach ($units as $idx => $u) {
            if ((int)$u['unit_id'] === $unitEntitasId) {
                $targetUnit = $u;
                $unitIndex = $idx;
                break;
            }
        }

        if (!$targetUnit) {
            throw new UserException('Unit tidak ditemukan atau belum memiliki mahasiswa diterima.', 404);
        }

        $students = self::getStudentsForUnit($pdo, $periodeId, $unitEntitasId);
        if (empty($students)) {
            throw new UserException('Tidak ada daftar mahasiswa diterima untuk unit ini.', 404);
        }

        // Hitung nomor surat otomatis
        $seq = $unitIndex + 1;
        $nomorSurat = self::formatNomorSurat(
            (string)($cfg['nomor_pokok'] ?? ''),
            $seq,
            (string)($cfg['format_sufiks'] ?? '/Kt/2/D06'),
            (string)($cfg['bulan'] ?? date('m')),
            (string)($cfg['tahun'] ?? date('Y'))
        );

        // Logo ITPLN Base64 persis dari Contoh Surat.docx (media/image1.jpeg)
        $root = self::getRootPath();
        $logoItplnBase64 = self::getBase64Image($root . '/public/assets/img/kop_itpln_logo.jpeg') 
            ?: self::getBase64Image($root . '/public/assets/img/logo_itpln.png');

        // Tanda tangan Base64 jika ada
        $ttdBase64 = null;
        if (!empty($cfg['ttd_image_path'])) {
            $cleanTtd = ltrim(str_replace('\\', '/', (string)$cfg['ttd_image_path']), '/');
            if (preg_match('/^storage\/signatures\/ttd_periode_\d+_\d+\.(png|jpg|jpeg|webp)$/', $cleanTtd)) {
                $ttdFullPath = $root . '/' . $cleanTtd;
                $sigDirReal = realpath($root . '/storage/signatures');
                $realTtd = realpath($ttdFullPath);
                if ($realTtd && $sigDirReal && str_starts_with($realTtd, $sigDirReal) && is_file($realTtd)) {
                    $ttdBase64 = self::getBase64Image($realTtd);
                }
            }
        }

        // Render HTML
        $html = self::buildHtml([
            'nomor_surat'           => $nomorSurat,
            'tanggal_surat'         => $cfg['tanggal_surat'] ?: ('Jakarta, ' . SuratGenerator::formatIndonesianDate(date('Y-m-d'))),
            'tahun_akademik'        => $cfg['tahun_akademik'] ?: (date('Y') . '/' . (date('Y') + 1)),
            'perihal'               => $cfg['perihal'] ?: 'Penyampaian Penempatan Mahasiswa Magang ITPLN',
            'unit_nama'             => $targetUnit['unit_nama'],
            'unit_alamat'           => $targetUnit['unit_alamat'] ?: 'Lingkungan Kerja PT PLN (Persero)',
            'unit_singkatan'        => $targetUnit['unit_singkatan'] ?: '',
            'students'              => $students,
            'jabatan_penandatangan' => $cfg['jabatan_penandatangan'] ?: 'Wakil Rektor III Bidang Kemahasiswaan',
            'nama_penandatangan'    => $cfg['nama_penandatangan'] ?: 'Ir. Purnomo, S.T., M.T.',
            'nip_penandatangan'     => $cfg['nip_penandatangan'] ?: '',
            'logo_itpln'            => $logoItplnBase64,
            'ttd_image'             => $ttdBase64,
            'periode_nama'          => $cfg['periode_nama'] ?? 'Periode Magang',
        ]);

        // Inisialisasi Dompdf
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('chroot', $root);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        $safeUnit = preg_replace('/[^a-zA-Z0-9_-]/', '_', $targetUnit['unit_nama']);
        $filename = "Surat_Pengantar_Magang_{$safeUnit}.pdf";

        return [
            'pdf'         => $pdfOutput,
            'filename'    => $filename,
            'nomor_surat' => $nomorSurat,
            'unit_nama'   => $targetUnit['unit_nama'],
        ];
    }

    /**
     * Generate Dokumen PDF Surat Pengantar untuk Mahasiswa (Portal Mahasiswa)
     */
    public static function generatePdfForMahasiswa(PDO $pdo, int $pendaftaranId, ?int $checkMahasiswaId = null): array
    {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.mahasiswa_id,
                p.periode_id,
                p.status,
                pr.pengumuman_dibuka,
                upp.entitas_id AS unit_entitas_id
            FROM pendaftaran p
            JOIN periode pr ON p.periode_id = pr.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            WHERE p.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reg) {
            throw new UserException('Data pendaftaran tidak ditemukan.', 404);
        }

        // IDOR Guard
        if ($checkMahasiswaId !== null && (int)$reg['mahasiswa_id'] !== $checkMahasiswaId) {
            throw new UserException('Akses ditolak: Dokumen ini bukan milik akun Anda.', 403);
        }

        // Status Guard
        if ($reg['status'] !== 'diterima' && $reg['status'] !== 'dipindahkan') {
            throw new UserException('Surat Pengantar Magang hanya dapat diterbitkan bagi peserta yang berstatus DITERIMA.', 403);
        }

        // Validasi Pengumuman Dibuka
        if (empty($reg['pengumuman_dibuka'])) {
            throw new UserException('Pengumuman hasil seleksi magang untuk periode ini belum resmi dibuka.', 403);
        }

        // Validasi Nomor Surat Pengantar sudah diset oleh Admin
        $cfg = self::getConfig($pdo, (int)$reg['periode_id']);
        if (empty($cfg['nomor_pokok'])) {
            throw new UserException('Surat belum tersedia', 400);
        }

        return self::generatePdfForUnit($pdo, (int)$reg['periode_id'], (int)$reg['unit_entitas_id']);
    }

    /**
     * Generate Dokumen ZIP seluruh surat pengantar unit untuk periode tertentu
     */
    public static function generateAllZip(PDO $pdo, int $periodeId): string
    {
        $cfg = self::getConfig($pdo, $periodeId);
        $units = self::getUnitsSummary($pdo, $periodeId);

        if (empty($units)) {
            throw new UserException('Tidak ada unit yang menerima mahasiswa pada periode ini.', 404);
        }

        $tempDir = sys_get_temp_dir() . '/intern_zip_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $zipPath = $tempDir . '/Surat_Pengantar_Seluruh_Unit_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuat file arsip ZIP di server.');
        }

        foreach ($units as $u) {
            $unitId = (int)$u['unit_id'];
            try {
                $res = self::generatePdfForUnit($pdo, $periodeId, $unitId);
                $zip->addFromString($res['filename'], $res['pdf']);
            } catch (\Throwable $e) {
                error_log('[generateAllZip] Gagal generate unit ' . $unitId . ': ' . $e->getMessage());
            }
        }

        $zip->close();
        return $zipPath;
    }

    /**
     * Bangun HTML Template Dokumen PDF Surat Pengantar
     */
    private static function buildHtml(array $v): string
    {
        $e = fn($val) => htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');

        $logoItplnImg = !empty($v['logo_itpln']) 
            ? '<img src="' . $v['logo_itpln'] . '" style="width: 172pt; height: auto; max-height: 74pt; display: block;">' 
            : '<div style="font-weight: bold; color: #002060; font-size: 15px;">INSTITUT TEKNOLOGI PLN</div>';

        $nomorSurat          = $e($v['nomor_surat']);
        $tanggalSurat        = $e($v['tanggal_surat']);
        $tahunAkademik       = $e($v['tahun_akademik']);
        $periodeNama         = $e($v['periode_nama'] ?? 'Periode Magang');
        $perihal             = nl2br($e($v['perihal']));
        $unitNama            = $e($v['unit_nama']);
        $unitAlamat          = $e($v['unit_alamat']);
        $namaPenandatangan   = $e($v['nama_penandatangan']);
        $jabatanPenandatangan= $e($v['jabatan_penandatangan']);
        $nipPenandatangan    = $e($v['nip_penandatangan']);

        $ttdImgHtml = !empty($v['ttd_image'])
            ? '<img src="' . $v['ttd_image'] . '" style="max-height: 65px; max-width: 150px; object-fit: contain; display: block; margin: 0 auto;">'
            : '<div style="height: 60px;"></div>';

        // Susun baris tabel mahasiswa
        $students = $v['students'] ?? [];
        $rowsHtml = '';
        foreach ($students as $idx => $s) {
            $no = $idx + 1;
            $nim = $e($s['nim']);
            $nama = $e($s['nama']);
            $prodi = $e($s['prodi']);
            $hp = $e($s['no_hp']);
            $progRaw = (string)($s['program'] ?? '');
            $progFormatted = match($progRaw) {
                '1_bulan' => '1 Bulan',
                '3_bulan' => '3 Bulan',
                '4_bulan' => '4 Bulan',
                '5_bulan' => '5 Bulan',
                default => !empty($progRaw) ? ucfirst(str_replace('_', ' ', $progRaw)) : '-'
            };
            $bg = ($no % 2 === 0) ? '#F8FAFC' : '#FFFFFF';

            $rowsHtml .= <<<TR
            <tr style="background-color: {$bg};">
                <td style="text-align: center; font-size: 8.5pt; padding: 5px 3px; border: 1px solid #CBD5E1;">{$no}</td>
                <td style="text-align: center; font-size: 8.5pt; padding: 5px 3px; border: 1px solid #CBD5E1; font-family: monospace; font-weight: bold;">{$nim}</td>
                <td style="text-align: left; font-size: 8.5pt; padding: 5px 6px; border: 1px solid #CBD5E1; font-weight: 600;">{$nama}</td>
                <td style="text-align: left; font-size: 8.5pt; padding: 5px 6px; border: 1px solid #CBD5E1;">{$prodi}</td>
                <td style="text-align: center; font-size: 8.5pt; padding: 5px 4px; border: 1px solid #CBD5E1; font-weight: bold; color: #0284C7;">{$progFormatted}</td>
                <td style="text-align: center; font-size: 8.5pt; padding: 5px 4px; border: 1px solid #CBD5E1;">{$hp}</td>
            </tr>
TR;
        }

        $nipBlock = $nipPenandatangan !== '' 
            ? '<div class="signer-nip">NIP/NIDN: ' . $nipPenandatangan . '</div>' 
            : '';

        $root = self::getRootPath();
        $fontFile = $root . '/public/assets/fonts/micross.ttf';
        if (!file_exists($fontFile)) {
            $fontFile = 'C:/Windows/Fonts/micross.ttf';
        }
        $fontData = file_exists($fontFile) ? file_get_contents($fontFile) : false;
        $fontBase64 = $fontData !== false 
            ? 'data:font/truetype;charset=utf-8;base64,' . base64_encode($fontData)
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Pengantar Magang - {$unitNama}</title>
    <style>
        @font-face {
            font-family: 'Microsoft Sans Serif';
            src: url('{$fontBase64}') format('truetype');
            font-weight: normal;
            font-style: normal;
        }
        @page {
            margin: 11mm 18mm 14mm 18mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #111827;
            margin: 0;
            padding: 0;
        }
        /* Kop Surat Resmi Persis Sesuai Header Contoh Surat.docx */
        .kop-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0px;
        }
        .kop-email {
            color: #0563C1;
            text-decoration: underline;
        }
        .kop-web {
            color: #0070C0;
            text-decoration: underline;
        }
        .kop-divider {
            border-top: 1.6pt solid #002060;
            margin-top: 10px;
            margin-bottom: 14px;
        }
        /* Metadata Surat */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .meta-table td {
            vertical-align: top;
            padding: 2px 0;
            font-size: 9.5pt;
        }
        .recipient-block {
            margin-bottom: 12px;
            font-size: 9.5pt;
            line-height: 1.4;
        }
        .recipient-block .to-unit {
            font-weight: bold;
            color: #0B192C;
        }
        .content-p {
            text-align: justify;
            margin-bottom: 10px;
            font-size: 9.5pt;
            line-height: 1.45;
        }
        /* Tabel Mahasiswa */
        .student-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0 14px 0;
            page-break-inside: auto;
        }
        .student-table tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .student-table th {
            background-color: #0B3D6B;
            color: #FFFFFF;
            font-size: 8.5pt;
            font-weight: bold;
            padding: 6px 4px;
            border: 1px solid #0B3D6B;
            text-align: center;
        }
        /* Tanda Tangan */
        .signature-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            page-break-inside: avoid;
        }
        .signature-table td {
            vertical-align: top;
            padding: 0;
        }
        .signer-block {
            text-align: center;
            width: 260px;
            float: right;
        }
        .signer-date {
            font-size: 9.5pt;
            margin-bottom: 2px;
        }
        .signer-inst {
            font-size: 9.5pt;
            font-weight: bold;
            color: #0B192C;
            margin-bottom: 2px;
        }
        .signer-role {
            font-size: 9pt;
            color: #334155;
            margin-bottom: 4px;
        }
        .signer-name {
            font-size: 9.5pt;
            font-weight: bold;
            color: #0B192C;
            text-decoration: underline;
        }
        .signer-nip {
            font-size: 8.5pt;
            color: #475569;
            margin-top: 2px;
        }
        .footer-note {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 7pt;
            color: #94A3B8;
            text-align: center;
            border-top: 0.5px solid #E2E8F0;
            padding-top: 4px;
        }
    </style>
</head>
<body>

    <!-- KOP SURAT RESMI ITPLN PERSIS SESUAI CONTOH SURAT.DOCX -->
    <table class="kop-table">
        <tr>
            <td style="width: 47%; vertical-align: middle; text-align: left; padding: 0;">
                <div style="padding-right: 15px;">
                    {$logoItplnImg}
                </div>
            </td>
            <td style="width: 2px; vertical-align: middle; text-align: center; padding: 0 10px;">
                <div style="width: 1.5pt; height: 50pt; background-color: #002060; margin: 0 auto;"></div>
            </td>
            <td style="width: 53%; vertical-align: middle; text-align: left; padding: 0;">
                <div style="padding-left: 14px; font-family: 'Microsoft Sans Serif', Arial, sans-serif; font-size: 7.2pt; line-height: 1.34; color: #000000;">
                    Kampus: Jl. Lingkar Luar Barat, Kelurahan Duri Kosambi, Kecamatan<br>
                    Cengkareng, Kota Jakarta Barat, Provinsi DKI Jakarta, 11750, Indonesia<br>
                    Telp. +62 21 5440342, 5440344 Fax. +62 21 5440343<br>
                    Email: <span class="kop-email">rektorat@itpln.ac.id</span>, <span class="kop-email">humas@itpln.ac.id</span><br>
                    Website : <span class="kop-web">https://www.itpln.ac.id</span>
                </div>
            </td>
        </tr>
    </table>
    <div class="kop-divider"></div>

    <!-- METADATA SURAT -->
    <table class="meta-table">
        <tr>
            <td style="width: 12%; font-weight: bold;">Nomor</td>
            <td style="width: 2%;">:</td>
            <td style="width: 48%; font-weight: bold;">{$nomorSurat}</td>
            <td style="width: 38%; text-align: right;">{$tanggalSurat}</td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Lampiran</td>
            <td>:</td>
            <td>1 (satu) Berkas</td>
            <td></td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Periode</td>
            <td>:</td>
            <td colspan="2"><strong>{$periodeNama}</strong></td>
        </tr>
        <tr>
            <td style="font-weight: bold;">Perihal</td>
            <td>:</td>
            <td colspan="2"><strong>{$perihal}</strong></td>
        </tr>
    </table>

    <!-- TUJUAN SURAT -->
    <div class="recipient-block">
        Kepada Yth.<br>
        <strong>Pimpinan / General Manager / Senior Manager Human Capital</strong><br>
        <span class="to-unit">{$unitNama}</span><br>
        <span>{$unitAlamat}</span><br>
        di Tempat
    </div>

    <!-- NARASI PENGANTAR -->
    <div class="content-p">
        Dengan hormat,<br>
        Sehubungan dengan pelaksanaan kegiatan magang mahasiswa pada periode <strong>{$periodeNama}</strong> Institut Teknologi PLN (ITPLN) Tahun Akademik <strong>{$tahunAkademik}</strong>, bersama ini kami sampaikan daftar mahasiswa yang telah dinyatakan diterima dan dialokasikan untuk melaksanakan program magang pada unit yang Bapak/Ibu pimpin sebagai berikut:
    </div>

    <!-- TABEL MAHASISWA -->
    <table class="student-table">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 14%;">NIM</th>
                <th style="width: 27%;">Nama Mahasiswa</th>
                <th style="width: 25%;">Program Studi</th>
                <th style="width: 13%;">Program</th>
                <th style="width: 16%;">No. WhatsApp</th>
            </tr>
        </thead>
        <tbody>
            {$rowsHtml}
        </tbody>
    </table>

    <!-- PENUTUP -->
    <div class="content-p">
        Demikian surat pengantar ini kami sampaikan sebagai kelengkapan administratif. Atas perhatian, penerimaan, dan kerja sama yang terjalin dengan baik, kami mengucapkan terima kasih.
    </div>

    <!-- TANDA TANGAN RESMI -->
    <table class="signature-table">
        <tr>
            <td style="width: 50%;"></td>
            <td style="width: 50%;">
                <div class="signer-block">
                    <div class="signer-date">{$tanggalSurat}</div>
                    <div class="signer-inst">Institut Teknologi PLN</div>
                    <div class="signer-role">{$jabatanPenandatangan}</div>
                    {$ttdImgHtml}
                    <div class="signer-name">{$namaPenandatangan}</div>
                    {$nipBlock}
                </div>
            </td>
        </tr>
    </table>

    <!-- FOOTER RESMI -->
    <div class="footer-note">
        Dokumen Resmi Sistem Informasi Magang (INTERN ITPLN) — Badan Layanan Karir & Alumni (BLKA) Institut Teknologi PLN
    </div>

</body>
</html>
HTML;
    }
}

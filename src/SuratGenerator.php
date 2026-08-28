<?php

declare(strict_types=1);

namespace App;

use PDO;
use ZipArchive;

/**
 * Class SuratGenerator
 * Menangani logika ekstraksi data dan pembuatan dokumen Word (.docx) penempatan mahasiswa magang.
 */
class SuratGenerator
{
    /**
     * Konversi angka ke kalimat terbilang bahasa Indonesia
     */
    public static function terbilang(int $angka): string
    {
        $bilangan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        if ($angka < 12) {
            return $bilangan[$angka] ?? (string)$angka;
        } elseif ($angka < 20) {
            return self::terbilang($angka - 10) . ' belas';
        } elseif ($angka < 100) {
            return self::terbilang((int)($angka / 10)) . ' puluh' . ($angka % 10 > 0 ? ' ' . self::terbilang($angka % 10) : '');
        } elseif ($angka < 200) {
            return 'seratus' . ($angka - 100 > 0 ? ' ' . self::terbilang($angka - 100) : '');
        } elseif ($angka < 1000) {
            return self::terbilang((int)($angka / 100)) . ' ratus' . ($angka % 100 > 0 ? ' ' . self::terbilang($angka % 100) : '');
        }
        return (string)$angka;
    }

    /**
     * Format tanggal Indonesia, misal: 29 Agustus 2026
     */
    public static function formatIndonesianDate(string $dateStr): string
    {
        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $time = strtotime($dateStr) ?: time();
        $day = date('j', $time);
        $month = (int)date('n', $time);
        $year = date('Y', $time);
        return $day . ' ' . ($months[$month] ?? '') . ' ' . $year;
    }

    /**
     * Escape XML string untuk OpenXML
     */
    public static function escapeXml(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        return htmlspecialchars($text, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Dapatkan Base URL aplikasi (Memprioritaskan APP_URL dari .env, dengan fallback ke host request)
     */
    public static function getBaseUrl(): string
    {
        $envUrl = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        if ($envUrl !== '') {
            return rtrim($envUrl, '/');
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8001';
        return rtrim($protocol . $host, '/');
    }

    /**
     * Ambil atau buat konfigurasi default untuk periode tertentu
     */
    public static function getConfig(PDO $pdo, int $periodeId): array
    {
        $stmt = $pdo->prepare("SELECT * FROM konfigurasi_surat WHERE periode_id = ?");
        $stmt->execute([$periodeId]);
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);

        // Ambil info periode
        $stmtP = $pdo->prepare("SELECT nama, program_1_bulan, program_5_bulan FROM periode WHERE id = ?");
        $stmtP->execute([$periodeId]);
        $periode = $stmtP->fetch(PDO::FETCH_ASSOC) ?: ['nama' => 'Periode Magang', 'program_1_bulan' => 0, 'program_5_bulan' => 1];

        $currentYear = (int)date('Y');
        $nextYear = $currentYear + 1;
        $tahunAkademikDefault = $currentYear . '/' . $nextYear;
        $progText = $periode['program_1_bulan'] && !$periode['program_5_bulan'] ? '1 Bulan' : '5 Bulan';

        $baseUrl = self::getBaseUrl();
        $token = $cfg['public_token'] ?? null;
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
        }

        $defaultPublicUrl = $baseUrl . '/penempatan.html?token=' . $token;
        $tglDefault = 'Jakarta, ' . self::formatIndonesianDate(date('Y-m-d'));
        $perihalDefault = "Penyampaian Penempatan Mahasiswa\nMagang " . $progText . " PLN Group Tahun Ajaran " . $tahunAkademikDefault;

        if (!$cfg) {
            // Langsung simpan baris konfigurasi default ke database agar token publik segera terdaftar & valid diakses
            $stmtInsert = $pdo->prepare("
                INSERT INTO konfigurasi_surat (
                    periode_id, public_token, nomor_surat_template, nomor_surat_start,
                    tanggal_surat, tahun_akademik, perihal, jabatan_penandatangan,
                    nama_penandatangan, tembusan, link_data_publik, updated_at
                ) VALUES (
                    :pid, :token, :tpl, 1,
                    :tgl, :thn, :perihal, :jabatan,
                    :nama, :tembusan, :link, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    public_token = COALESCE(konfigurasi_surat.public_token, VALUES(public_token))
            ");
            $stmtInsert->execute([
                ':pid' => $periodeId,
                ':token' => $token,
                ':tpl' => '{nomor}/Srt/1/D0/' . date('m') . '/' . $currentYear,
                ':tgl' => $tglDefault,
                ':thn' => $tahunAkademikDefault,
                ':perihal' => $perihalDefault,
                ':jabatan' => 'Wakil Rektor III Bidang Kemahasiswaan',
                ':nama' => 'Ir. Purnomo, S.T., M.T.',
                ':tembusan' => "1.  HTD PLN Pusat",
                ':link' => $defaultPublicUrl
            ]);

            return [
                'periode_id' => $periodeId,
                'public_token' => $token,
                'nomor_surat_template' => '{nomor}/Srt/1/D0/' . date('m') . '/' . $currentYear,
                'nomor_surat_start' => 1,
                'tanggal_surat' => $tglDefault,
                'perihal' => $perihalDefault,
                'tahun_akademik' => $tahunAkademikDefault,
                'program_text' => $progText,
                'jabatan_penandatangan' => 'Wakil Rektor III Bidang Kemahasiswaan',
                'nama_penandatangan' => 'Ir. Purnomo, S.T., M.T.',
                'tembusan' => "1.  HTD PLN Pusat",
                'link_data_publik' => $defaultPublicUrl,
                'is_default' => true
            ];
        }

        if (empty($cfg['public_token'])) {
            $pdo->prepare("UPDATE konfigurasi_surat SET public_token = :token WHERE periode_id = :pid")->execute([':token' => $token, ':pid' => $periodeId]);
            $cfg['public_token'] = $token;
        }

        // Selalu gunakan baseUrl terkini dari APP_URL pada link_data_publik
        $cfg['link_data_publik'] = $defaultPublicUrl;

        $cfg['public_token'] = $token;
        $cfg['program_text'] = $progText;
        $cfg['is_default'] = false;
        return $cfg;
    }

    /**
     * Cari periode dan konfigurasi berdasarkan token publik yang valid (1 token permanen per periode)
     */
    public static function getPeriodeByToken(PDO $pdo, string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $sql = "
            SELECT 
                p.id,
                p.nama,
                p.status,
                p.program_1_bulan,
                p.program_5_bulan,
                p.pengumuman_dibuka,
                ks.public_token,
                ks.tahun_akademik,
                ks.perihal,
                ks.tanggal_surat
            FROM konfigurasi_surat ks
            JOIN periode p ON ks.periode_id = p.id
            WHERE ks.public_token = :token
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Simpan / update konfigurasi surat ke database
     */
    public static function saveConfig(PDO $pdo, int $periodeId, array $data): bool
    {
        $token = $data['public_token'] ?? null;
        if (empty($token)) {
            $token = bin2hex(random_bytes(16));
        }

        $sql = "
            INSERT INTO konfigurasi_surat (
                periode_id,
                public_token,
                nomor_surat_template,
                nomor_surat_start,
                tanggal_surat,
                perihal,
                tahun_akademik,
                jabatan_penandatangan,
                nama_penandatangan,
                tembusan,
                link_data_publik,
                updated_at
            ) VALUES (
                :periode_id,
                :public_token,
                :nomor_surat_template,
                :nomor_surat_start,
                :tanggal_surat,
                :perihal,
                :tahun_akademik,
                :jabatan_penandatangan,
                :nama_penandatangan,
                :tembusan,
                :link_data_publik,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                public_token = COALESCE(VALUES(public_token), public_token),
                nomor_surat_template = VALUES(nomor_surat_template),
                nomor_surat_start = VALUES(nomor_surat_start),
                tanggal_surat = VALUES(tanggal_surat),
                perihal = VALUES(perihal),
                tahun_akademik = VALUES(tahun_akademik),
                jabatan_penandatangan = VALUES(jabatan_penandatangan),
                nama_penandatangan = VALUES(nama_penandatangan),
                tembusan = VALUES(tembusan),
                link_data_publik = VALUES(link_data_publik),
                updated_at = NOW()
        ";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':periode_id' => $periodeId,
            ':public_token' => $token,
            ':nomor_surat_template' => $data['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/2026',
            ':nomor_surat_start' => (int)($data['nomor_surat_start'] ?? 1),
            ':tanggal_surat' => $data['tanggal_surat'] ?? null,
            ':perihal' => $data['perihal'] ?? null,
            ':tahun_akademik' => $data['tahun_akademik'] ?? null,
            ':jabatan_penandatangan' => $data['jabatan_penandatangan'] ?? null,
            ':nama_penandatangan' => $data['nama_penandatangan'] ?? null,
            ':tembusan' => $data['tembusan'] ?? null,
            ':link_data_publik' => $data['link_data_publik'] ?? null,
        ]);
    }

    /**
     * Ambil daftar unit yang memiliki mahasiswa diterima pada periode ini beserta rekap prodi
     */
    public static function getUnitsSummary(PDO $pdo, int $periodeId): array
    {
        $sql = "
            SELECT 
                e.id AS unit_id,
                e.nama AS unit_nama,
                e.alamat AS unit_alamat,
                COUNT(p.id) AS total_mahasiswa,
                COUNT(DISTINCT m.jurusan_id) AS total_prodi
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            WHERE p.periode_id = :pid AND p.status = 'diterima'
            GROUP BY e.id, e.nama, e.alamat
            ORDER BY e.nama ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $periodeId]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($units as &$u) {
            $uId = (int)$u['unit_id'];
            $sqlProdi = "
                SELECT 
                    COALESCE(j.nama_jurusan, 'Program Studi Lain') AS prodi,
                    COUNT(p.id) AS jumlah
                FROM pendaftaran p
                JOIN mahasiswa m ON p.mahasiswa_id = m.id
                LEFT JOIN jurusan j ON m.jurusan_id = j.id
                JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
                WHERE p.periode_id = :pid AND upp.entitas_id = :uid AND p.status = 'diterima'
                GROUP BY prodi
                ORDER BY jumlah DESC, prodi ASC
            ";
            $stmtProdi = $pdo->prepare($sqlProdi);
            $stmtProdi->execute([':pid' => $periodeId, ':uid' => $uId]);
            $u['prodi_breakdown'] = $stmtProdi->fetchAll(PDO::FETCH_ASSOC);
        }

        return $units;
    }

    /**
     * Ambil daftar detail mahasiswa diterima untuk unit tertentu
     */
    public static function getStudentsForUnit(PDO $pdo, int $periodeId, int $unitEntitasId): array
    {
        $sql = "
            SELECT 
                p.id AS pendaftaran_id,
                m.nim,
                p.nama_snapshot AS nama,
                p.jenis_kelamin,
                p.no_hp,
                CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili,
                p.ipk,
                p.jumlah_sks,
                COALESCE(GROUP_CONCAT(DISTINCT pem.nama SEPARATOR ', '), '-') AS peminatan,
                j.nama_jurusan AS prodi,
                e.nama AS lokasi_magang,
                p.program
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            LEFT JOIN jurusan j ON m.jurusan_id = j.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            LEFT JOIN pendaftaran_peminatan pp ON p.id = pp.pendaftaran_id
            LEFT JOIN peminatan pem ON pp.peminatan_id = pem.id
            WHERE p.periode_id = :pid AND upp.entitas_id = :uid AND p.status = 'diterima'
            GROUP BY p.id, m.nim, p.nama_snapshot, p.jenis_kelamin, p.no_hp, p.alamat, p.rt, p.rw, p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi, p.ipk, p.jumlah_sks, j.nama_jurusan, e.nama, p.program
            ORDER BY j.nama_jurusan ASC, p.nama_snapshot ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $periodeId, ':uid' => $unitEntitasId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Format nomor surat dengan increment sequence
     */
    public static function formatNomorSurat(string $template, int $number): string
    {
        $formattedNum = str_pad((string)$number, 3, '0', STR_PAD_LEFT);
        if (str_contains($template, '{nomor}')) {
            return str_replace('{nomor}', $formattedNum, $template);
        }
        if (str_contains($template, '{00x}')) {
            return str_replace('{00x}', $formattedNum, $template);
        }
        if (str_contains($template, '{no}')) {
            return str_replace('{no}', (string)$number, $template);
        }
        // Fallback jika tidak ada placeholder
        return $formattedNum . '/' . ltrim($template, '/');
    }

    /**
     * Format Judul Lampiran agar DI PLN tidak turun ke baris baru dan tidak dobel PLN PLN
     */
    public static function formatLampiranTitle(string $unitNama): array
    {
        $cleanUnit = trim($unitNama);

        // Jika unit diawali dengan "PLN " (misal: "PLN Unit Induk Distribusi Jawa Barat")
        if (preg_match('/^PLN\s+(.+)$/i', $cleanUnit, $m)) {
            $line1 = "DAFTAR NAMA MAHASISWA MAGANG MBKM PLN GROUP DI PLN";
            $line2 = strtoupper($m[1]);
        } elseif (preg_match('/^PT\s+PLN\s*(.*)$/i', $cleanUnit)) {
            $line1 = "DAFTAR NAMA MAHASISWA MAGANG MBKM PLN GROUP DI";
            $line2 = strtoupper($cleanUnit);
        } else {
            $line1 = "DAFTAR NAMA MAHASISWA MAGANG MBKM PLN GROUP DI PLN";
            $line2 = strtoupper($cleanUnit);
        }

        return [$line1, $line2];
    }

    /**
     * Render Tabel Header Surat 4-Kolom Flat (Nomor | : | Nilai | Tanggal & Kepada Yth.)
     * Menggunakan tabel datar standar OpenXML agar 100% valid dan tidak memicu pesan error unreadable content di MS Word.
     */
    public static function buildHeaderTableXml(
        string $nomorSurat,
        string $tanggalSurat,
        string $perihal,
        string $unitNama,
        string $unitAlamat
    ): string {
        $perihalLines = explode("\n", str_replace("\r", "", $perihal));
        $perihalXml = '';
        foreach ($perihalLines as $pIdx => $pLine) {
            $pLine = trim($pLine);
            if ($pLine !== '') {
                $perihalXml .= ($pIdx > 0 ? '<w:br/>' : '') . self::escapeXml($pLine);
            }
        }

        $cleanUnit = preg_replace('/^General Manager\s+/i', '', trim($unitNama));

        $xml = '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
          <w:tblPr>
            <w:tblW w:w="9775" w:type="dxa"/>
            <w:jc w:val="center"/>
            <w:tblBorders>
              <w:top w:val="none"/>
              <w:left w:val="none"/>
              <w:bottom w:val="none"/>
              <w:right w:val="none"/>
              <w:insideH w:val="none"/>
              <w:insideV w:val="none"/>
            </w:tblBorders>
            <w:tblCellMar>
              <w:top w:w="0" w:type="dxa"/>
              <w:left w:w="0" w:type="dxa"/>
              <w:bottom w:w="40" w:type="dxa"/>
              <w:right w:w="0" w:type="dxa"/>
            </w:tblCellMar>
          </w:tblPr>
          <w:tblGrid>
            <w:gridCol w:w="1250"/>
            <w:gridCol w:w="180"/>
            <w:gridCol w:w="3870"/>
            <w:gridCol w:w="4475"/>
          </w:tblGrid>
          <!-- Row 1: Nomor & Tanggal Surat -->
          <w:tr>
            <w:tc>
              <w:tcPr><w:tcW w:w="1250" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Nomor</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="180" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>:</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="3870" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($nomorSurat) . '</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="4475" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($tanggalSurat) . '</w:t></w:r></w:p>
            </w:tc>
          </w:tr>
          <!-- Row 2: Lampiran & Kepada Yth. -->
          <w:tr>
            <w:tc>
              <w:tcPr><w:tcW w:w="1250" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Lampiran</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="180" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>:</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="3870" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>1 (Satu) Set</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="4475" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto" w:before="60"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Kepada Yth.</w:t></w:r></w:p>
            </w:tc>
          </w:tr>
          <!-- Row 3: Perihal & General Manager [Unit] + Alamat -->
          <w:tr>
            <w:tc>
              <w:tcPr><w:tcW w:w="1250" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Perihal</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="180" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>:</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="3870" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . $perihalXml . '</w:t></w:r></w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="4475" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>
              <w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>General Manager</w:t></w:r></w:p>
              <w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($cleanUnit) . '</w:t></w:r></w:p>
              <w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto"/></w:pPr><w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($unitAlamat) . '</w:t></w:r></w:p>
            </w:tc>
          </w:tr>
        </w:tbl>';

        return $xml;
    }

    /**
     * Render Tabel Ringkasan Prodi (OpenXML)
     */
    public static function buildProdiTableXml(array $prodiCounts, int $totalMahasiswa): string
    {
        $xml = '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
          <w:tblPr>
            <w:tblW w:w="7229" w:type="dxa"/>
            <w:jc w:val="left"/>
            <w:tblInd w:w="1735" w:type="dxa"/>
            <w:tblBorders>
              <w:top w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:left w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:bottom w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:right w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>
              <w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>
            </w:tblBorders>
            <w:tblLayout w:type="fixed"/>
          </w:tblPr>
          <w:tblGrid>
            <w:gridCol w:w="4800"/>
            <w:gridCol w:w="2429"/>
          </w:tblGrid>
          <w:tr>
            <w:trPr><w:trHeight w:val="320" w:hRule="atLeast"/></w:trPr>
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="4800" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Prodi</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="2429" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Jumlah</w:t></w:r>
              </w:p>
            </w:tc>
          </w:tr>';

        foreach ($prodiCounts as $row) {
            $prodiName = self::escapeXml($row['prodi']);
            $count = (int)$row['jumlah'];
            $xml .= '
          <w:tr>
            <w:trPr><w:trHeight w:val="300" w:hRule="atLeast"/></w:trPr>
            <w:tc>
              <w:tcPr><w:tcW w:w="4800" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:ind w:left="120"/><w:jc w:val="left"/><w:rPr><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . $prodiName . '</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="2429" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . $count . '</w:t></w:r>
              </w:p>
            </w:tc>
          </w:tr>';
        }

        // Total Row
        $xml .= '
          <w:tr>
            <w:trPr><w:trHeight w:val="320" w:hRule="atLeast"/></w:trPr>
            <w:tc>
              <w:tcPr><w:tcW w:w="4800" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F9F9F9"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:ind w:left="120"/><w:jc w:val="left"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Total</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="2429" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F9F9F9"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . $totalMahasiswa . '</w:t></w:r>
              </w:p>
            </w:tc>
          </w:tr>
        </w:tbl>';

        return $xml;
    }

    /**
     * Render Tabel Lampiran Mahasiswa (OpenXML Landscape)
     */
    public static function buildLampiranTableXml(array $students): string
    {
        $cols = [
            ['title' => 'No', 'w' => 550, 'align' => 'center'],
            ['title' => 'NIM', 'w' => 1200, 'align' => 'center'],
            ['title' => 'Nama Mahasiswa', 'w' => 2200, 'align' => 'left'],
            ['title' => 'L/P', 'w' => 550, 'align' => 'center'],
            ['title' => 'No. HP', 'w' => 1400, 'align' => 'center'],
            ['title' => 'Alamat Domisili', 'w' => 2500, 'align' => 'left'],
            ['title' => 'IPK', 'w' => 700, 'align' => 'center'],
            ['title' => 'SKS', 'w' => 600, 'align' => 'center'],
            ['title' => 'Peminatan', 'w' => 2000, 'align' => 'left'],
            ['title' => 'Lokasi Magang', 'w' => 2740, 'align' => 'left'],
        ];

        $totalW = array_sum(array_column($cols, 'w'));

        $xml = '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
          <w:tblPr>
            <w:tblW w:w="' . $totalW . '" w:type="dxa"/>
            <w:jc w:val="center"/>
            <w:tblBorders>
              <w:top w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:left w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:bottom w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:right w:val="single" w:sz="6" w:space="0" w:color="000000"/>
              <w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>
              <w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>
            </w:tblBorders>
            <w:tblLayout w:type="fixed"/>
          </w:tblPr>
          <w:tblGrid>';
        foreach ($cols as $c) {
            $xml .= '<w:gridCol w:w="' . $c['w'] . '"/>';
        }
        $xml .= '</w:tblGrid>
          <w:tr>
            <w:trPr><w:trHeight w:val="380" w:hRule="atLeast"/><w:tblHeader/></w:trPr>';

        foreach ($cols as $c) {
            $xml .= '
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="' . $c['w'] . '" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="D9E1F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="18"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="18"/></w:rPr><w:t>' . self::escapeXml($c['title']) . '</w:t></w:r>
              </w:p>
            </w:tc>';
        }
        $xml .= '</w:tr>';

        foreach ($students as $idx => $s) {
            $no = $idx + 1;
            $nim = self::escapeXml((string)($s['nim'] ?? '-'));
            $nama = self::escapeXml((string)($s['nama'] ?? '-'));
            $jk = self::escapeXml((string)($s['jenis_kelamin'] ?? '-'));
            $hp = self::escapeXml((string)($s['no_hp'] ?? '-'));
            $domisili = self::escapeXml((string)($s['alamat_domisili'] ?? '-'));
            $ipk = self::escapeXml((string)($s['ipk'] ?? '-'));
            $sks = self::escapeXml((string)($s['jumlah_sks'] ?? '-'));
            $peminatan = self::escapeXml((string)($s['peminatan'] ?? '-'));
            $lokasi = self::escapeXml((string)($s['lokasi_magang'] ?? '-'));

            $rowVals = [
                ['val' => (string)$no, 'w' => $cols[0]['w'], 'align' => 'center'],
                ['val' => $nim, 'w' => $cols[1]['w'], 'align' => 'center'],
                ['val' => $nama, 'w' => $cols[2]['w'], 'align' => 'left'],
                ['val' => $jk, 'w' => $cols[3]['w'], 'align' => 'center'],
                ['val' => $hp, 'w' => $cols[4]['w'], 'align' => 'center'],
                ['val' => $domisili, 'w' => $cols[5]['w'], 'align' => 'left'],
                ['val' => $ipk, 'w' => $cols[6]['w'], 'align' => 'center'],
                ['val' => $sks, 'w' => $cols[7]['w'], 'align' => 'center'],
                ['val' => $peminatan, 'w' => $cols[8]['w'], 'align' => 'left'],
                ['val' => $lokasi, 'w' => $cols[9]['w'], 'align' => 'left'],
            ];

            $bg = ($no % 2 === 0) ? 'F9FAFB' : 'FFFFFF';

            $xml .= '
          <w:tr>
            <w:trPr><w:trHeight w:val="300" w:hRule="atLeast"/></w:trPr>';
            foreach ($rowVals as $rv) {
                $ind = ($rv['align'] === 'left') ? '<w:ind w:left="60" w:right="60"/>' : '';
                $xml .= '
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="' . $rv['w'] . '" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="' . $bg . '"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr>' . $ind . '<w:jc w:val="' . $rv['align'] . '"/><w:rPr><w:sz w:val="17"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:sz w:val="17"/></w:rPr><w:t>' . $rv['val'] . '</w:t></w:r>
              </w:p>
            </w:tc>';
            }
            $xml .= '</w:tr>';
        }

        $xml .= '</w:tbl>';
        return $xml;
    }

    /**
     * Generate file DOCX untuk satu unit tertentu
     */
    public static function generateDocxForUnit(array $config, array $unitData, array $students, int $unitIndex): string
    {
        $templatePath = dirname(__DIR__) . '/docs/Contoh Surat.docx';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('File template docx tidak ditemukan di: ' . $templatePath);
        }

        $tempDir = sys_get_temp_dir() . '/remate_surat_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $safeUnitName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $unitData['unit_nama'] ?? 'Unit');
        $outPath = $tempDir . '/Surat_Penempatan_' . $safeUnitName . '.docx';

        copy($templatePath, $outPath);

        $zip = new ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \RuntimeException('Gagal membuka template docx untuk penulisan.');
        }

        // 1. Inject Landscape Header (header2.xml & header2.xml.rels)
        $header2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.jpeg"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="mailto:rektorat@itpln.ac.id" TargetMode="External"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="mailto:humas@itpln.ac.id" TargetMode="External"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://www.itpln.ac.id/" TargetMode="External"/>
</Relationships>';

        $header2Xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
  <w:tbl>
    <w:tblPr>
      <w:tblW w:w="14440" w:type="dxa"/>
      <w:jc w:val="center"/>
      <w:tblBorders>
        <w:top w:val="none"/>
        <w:left w:val="none"/>
        <w:bottom w:val="single" w:sz="12" w:space="0" w:color="0B3D6B"/>
        <w:right w:val="none"/>
        <w:insideH w:val="none"/>
        <w:insideV w:val="none"/>
      </w:tblBorders>
      <w:tblCellMar>
        <w:top w:w="0" w:type="dxa"/>
        <w:left w:w="0" w:type="dxa"/>
        <w:bottom w:w="120" w:type="dxa"/>
        <w:right w:w="0" w:type="dxa"/>
      </w:tblCellMar>
    </w:tblPr>
    <w:tblGrid>
      <w:gridCol w:w="4200"/>
      <w:gridCol w:w="10240"/>
    </w:tblGrid>
    <w:tr>
      <w:trPr><w:trHeight w:val="1100" w:hRule="atLeast"/></w:trPr>
      <w:tc>
        <w:tcPr>
          <w:tcW w:w="4200" w:type="dxa"/>
          <w:tcBorders>
            <w:right w:val="single" w:sz="6" w:space="0" w:color="0B3D6B"/>
          </w:tcBorders>
          <w:vAlign w:val="center"/>
        </w:tcPr>
        <w:p>
          <w:pPr><w:jc w:val="left"/></w:pPr>
          <w:r>
            <w:drawing>
              <wp:inline distT="0" distB="0" distL="0" distR="0">
                <wp:extent cx="2286000" cy="742950"/>
                <wp:effectExtent l="0" t="0" r="0" b="0"/>
                <wp:docPr id="101" name="Logo ITPLN"/>
                <wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>
                <a:graphic>
                  <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                    <pic:pic>
                      <pic:nvPicPr>
                        <pic:cNvPr id="101" name="Logo ITPLN"/>
                        <pic:cNvPicPr/>
                      </pic:nvPicPr>
                      <pic:blipFill>
                        <a:blip r:embed="rId1"/>
                        <a:stretch><a:fillRect/></a:stretch>
                      </pic:blipFill>
                      <pic:spPr>
                        <a:xfrm><a:off x="0" y="0"/><a:ext cx="2286000" cy="742950"/></a:xfrm>
                        <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                      </pic:spPr>
                    </pic:pic>
                  </a:graphicData>
                </a:graphic>
              </wp:inline>
            </w:drawing>
          </w:r>
        </w:p>
      </w:tc>
      <w:tc>
        <w:tcPr>
          <w:tcW w:w="10240" w:type="dxa"/>
          <w:vAlign w:val="center"/>
        </w:tcPr>
        <w:p>
          <w:pPr><w:ind w:left="180"/><w:jc w:val="left"/><w:spacing w:line="220" w:lineRule="auto"/></w:pPr>
          <w:r><w:rPr><w:sz w:val="17"/><w:color w:val="333333"/></w:rPr><w:t>Kampus: Jl. Lingkar Luar Barat, Kelurahan Duri Kosambi, Kecamatan Cengkareng, Kota Jakarta Barat, DKI Jakarta, 11750</w:t></w:r>
        </w:p>
        <w:p>
          <w:pPr><w:ind w:left="180"/><w:jc w:val="left"/><w:spacing w:line="220" w:lineRule="auto"/></w:pPr>
          <w:r><w:rPr><w:sz w:val="17"/><w:color w:val="333333"/></w:rPr><w:t>Telp. +62 21 5440342, 5440344 | Fax. +62 21 5440343</w:t></w:r>
        </w:p>
        <w:p>
          <w:pPr><w:ind w:left="180"/><w:jc w:val="left"/><w:spacing w:line="220" w:lineRule="auto"/></w:pPr>
          <w:r><w:rPr><w:sz w:val="17"/><w:color w:val="333333"/></w:rPr><w:t>Email: rektorat@itpln.ac.id, humas@itpln.ac.id | Website: https://www.itpln.ac.id</w:t></w:r>
        </w:p>
      </w:tc>
    </w:tr>
  </w:tbl>
</w:hdr>';

        $zip->addFromString('word/header2.xml', $header2Xml);
        $zip->addFromString('word/_rels/header2.xml.rels', $header2Rels);

        $startNum = (int)($config['nomor_surat_start'] ?? 1);
        $currentSeq = $startNum + $unitIndex;
        $nomorSurat = self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $currentSeq);
        $tanggalSurat = $config['tanggal_surat'] ?? ('Jakarta, ' . self::formatIndonesianDate(date('Y-m-d')));
        $perihal = $config['perihal'] ?? 'Penyampaian Penempatan Mahasiswa Magang PLN Group';
        $tahunAkademik = $config['tahun_akademik'] ?? (date('Y') . '/' . (date('Y') + 1));
        $programText = $config['program_text'] ?? '5 Bulan';
        $jabatanTtd = $config['jabatan_penandatangan'] ?? 'Wakil Rektor III Bidang Kemahasiswaan';
        $namaTtd = $config['nama_penandatangan'] ?? 'Ir. Purnomo, S.T., M.T.';
        $linkData = $config['link_data_publik'] ?? '';

        $unitNama = $unitData['unit_nama'] ?? 'Kantor Unit PLN';
        $unitAlamat = !empty($unitData['unit_alamat']) ? $unitData['unit_alamat'] : 'Kantor PLN Unit Terkait';

        $totalMahasiswa = count($students);
        $prodiCounts = $unitData['prodi_breakdown'] ?? [];
        $totalProdi = count($prodiCounts);

        $prodiTableXml = self::buildProdiTableXml($prodiCounts, $totalMahasiswa);
        $lampiranTableXml = self::buildLampiranTableXml($students);
        $headerTableXml = self::buildHeaderTableXml($nomorSurat, $tanggalSurat, $perihal, $unitNama, $unitAlamat);
        [$lampiranTitle1, $lampiranTitle2] = self::formatLampiranTitle($unitNama);

        $tembusanRaw = $config['tembusan'] ?? "1.  HTD PLN Pusat";
        $tembusanLines = explode("\n", str_replace("\r", "", $tembusanRaw));
        $tembusanXml = '';
        foreach ($tembusanLines as $t) {
            $t = trim($t);
            if ($t !== '') {
                $tembusanXml .= '<w:p><w:pPr><w:pStyle w:val="BodyText"/><w:ind w:left="2084"/></w:pPr><w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($t) . '</w:t></w:r></w:p>';
            }
        }

        $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:ve="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:w10="urn:schemas-microsoft-com:office:word" xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:wne="http://schemas.microsoft.com/office/word/2006/wordml" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" xmlns:wpg="http://schemas.microsoft.com/office/word/2010/wordprocessingGroup" xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape" xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" mc:Ignorable="w14" xml:space="preserve">
  <w:body>
    <!-- Section 1 Header Table: Nomor, Lampiran, Perihal, Tanggal, Kepada Yth. GM [Unit] + Alamat -->
    ' . $headerTableXml . '

    <!-- Spacing -->
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:spacing w:before="140"/></w:pPr></w:p>

    <!-- Salam Pembuka -->
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="20"/>
        <w:ind w:left="1724"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Dengan hormat,</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="43"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Semoga Bapak/Ibu beserta seluruh jajaran ' . self::escapeXml($unitNama) . ' senantiasa dalam keadaan sehat dan berada dalam lindungan Tuhan Yang Maha Esa.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="100"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Merujuk pada hasil koordinasi dan diskusi penyesuaian penempatan magang antara ITPLN, HTD PLN Pusat, Subholding, dan Anak Perusahaan di lingkungan PLN Group, bersama ini kami menyampaikan data penempatan mahasiswa Program Magang ' . self::escapeXml($programText) . ' PLN Group Tahun Akademik ' . self::escapeXml($tahunAkademik) . ' yang telah difinalkan berdasarkan hasil koordinasi dan kesepakatan bersama.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="100"/>
        <w:ind w:left="1724" w:right="53"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Data penempatan tersebut mencakup sebanyak ' . $totalMahasiswa . ' (' . self::terbilang($totalMahasiswa) . ') mahasiswa yang berasal dari ' . $totalProdi . ' (' . self::terbilang($totalProdi) . ') Program Studi di ITPLN, dengan rincian sebagaimana terlampir dalam surat ini. Data tersebut merupakan hasil penyesuaian antara kebutuhan dan ketersediaan tempat magang pada masing-masing unit, Subholding, dan Anak Perusahaan PLN Group dengan bidang keilmuan mahasiswa.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="33" w:after="1"/>
      </w:pPr>
    </w:p>
    ' . $prodiTableXml . '
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
      </w:pPr>
    </w:p>

    <!-- Public Link: Titik dua rapat dan link rapi tanpa perenggangan spasi -->
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Rincian daftar peserta magang dapat dilihat pada berkas yang disediakan melalui tautan:</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="40" w:after="60"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="left"/>
      </w:pPr>
      <w:r><w:rPr><w:color w:val="0563C1"/><w:u w:val="single" w:color="0563C1"/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($linkData) . '</w:t></w:r>
    </w:p>

    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="80"/>
        <w:ind w:left="1724" w:right="55"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Demikian surat ini kami sampaikan. Atas perhatian dan kerja sama yang baik, kami mengucapkan terima kasih.</w:t></w:r>
    </w:p>

    <!-- Tanda Tangan Pejabat -->
    <w:p>
      <w:pPr>
        <w:spacing w:line="278" w:lineRule="auto" w:before="120"/>
        <w:ind w:left="6500" w:right="342"/>
        <w:jc w:val="left"/>
        <w:rPr><w:b/><w:sz w:val="22"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($jabatanTtd) . '</w:t></w:r>
    </w:p>
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:rPr><w:b/></w:rPr></w:pPr></w:p>
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:rPr><w:b/></w:rPr></w:pPr></w:p>
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:rPr><w:b/></w:rPr></w:pPr></w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:before="0"/>
        <w:ind w:left="6500" w:right="342"/>
        <w:jc w:val="left"/>
        <w:rPr><w:b/><w:sz w:val="22"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($namaTtd) . '</w:t></w:r>
    </w:p>

    <!-- Tembusan -->
    <w:p>
      <w:pPr>
        <w:spacing w:before="120"/>
        <w:ind w:left="1724" w:right="0" w:firstLine="0"/>
        <w:jc w:val="left"/>
        <w:rPr><w:b/><w:sz w:val="22"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Tembusan:</w:t></w:r>
    </w:p>
    ' . $tembusanXml . '
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:after="0"/>
        <w:sectPr>
          <w:headerReference w:type="default" r:id="rId5"/>
          <w:pgSz w:w="11900" w:h="16840"/>
          <w:pgMar w:header="473" w:footer="0" w:top="2200" w:bottom="280" w:left="992" w:right="1133"/>
        </w:sectPr>
      </w:pPr>
    </w:p>

    <!-- SECTION 2: LAMPIRAN (LANDSCAPE) -->
    <w:p>
      <w:pPr>
        <w:spacing w:line="276" w:lineRule="auto" w:before="120" w:after="40"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="24"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>' . self::escapeXml($lampiranTitle1) . '</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:line="300" w:lineRule="auto" w:before="0" w:after="160"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="26"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>' . self::escapeXml($lampiranTitle2) . '</w:t></w:r>
    </w:p>
    ' . $lampiranTableXml . '
    <w:sectPr>
      <w:headerReference w:type="default" r:id="rId7"/>
      <w:pgSz w:w="16840" w:h="11900" w:orient="landscape"/>
      <w:pgMar w:header="400" w:footer="0" w:top="2200" w:bottom="720" w:left="1200" w:right="1200"/>
    </w:sectPr>
  </w:body>
</w:document>';

        $zip->addFromString('word/document.xml', $docXml);
        $zip->close();

        return $outPath;
    }

    /**
     * Generate arsip ZIP berisi surat seluruh unit
     */
    public static function generateAllZip(PDO $pdo, int $periodeId): string
    {
        $config = self::getConfig($pdo, $periodeId);
        $units = self::getUnitsSummary($pdo, $periodeId);

        if (empty($units)) {
            throw new \RuntimeException('Tidak ada unit dengan mahasiswa berstatus diterima pada periode ini.');
        }

        $tempDir = sys_get_temp_dir() . '/remate_zip_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $zipPath = $tempDir . '/Surat_Penempatan_Seluruh_Unit_Periode_' . $periodeId . '.zip';
        $zipArchive = new ZipArchive();
        if ($zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuat arsip ZIP.');
        }

        foreach ($units as $index => $u) {
            $students = self::getStudentsForUnit($pdo, $periodeId, (int)$u['unit_id']);
            if (empty($students)) {
                continue;
            }
            $docxPath = self::generateDocxForUnit($config, $u, $students, $index);
            $fileName = basename($docxPath);
            $zipArchive->addFile($docxPath, $fileName);
        }

        $zipArchive->close();
        return $zipPath;
    }
}

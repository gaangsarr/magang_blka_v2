<?php

declare(strict_types=1);

namespace App;

use PDO;
use ZipArchive;

/**
 * Class SuratGenerator
 * Menangani logika ekstraksi data hierarki PLN Group dan pembuatan dokumen Word (.docx) penempatan mahasiswa magang:
 * 1. Dokumen Surat Konsolidasi Kantor Pusat PLN dan Unit-Unit (EVP HTD PT PLN Persero)
 * 2. Dokumen Surat per Perusahaan Subholding (ICON+, Indonesia Power, Nusantara Power, EPI)
 * 3. Dokumen Surat per Perusahaan Anak Perusahaan (PLN Batam, Haleyora Power, PLN EMI, PLN Enjiniring, dll.)
 */
class SuratGenerator
{
    /**
     * Kategori resmi di lingkungan Kantor Pusat PLN & Unit-Unit
     */
    public const CATEGORIES_PLN_PUSAT = [
        'uid' => [
            'key' => 'uid',
            'nama' => 'Unit Induk Distribusi (UID)',
            'singkatan' => 'UID',
            'pattern' => '/(UID\b|Unit Induk Distribusi|UP3\b|UP2D\b|ULP\b)/i'
        ],
        'pusat' => [
            'key' => 'pusat',
            'nama' => 'Pusat - Pusat Unit Pendukung',
            'singkatan' => 'Pusat',
            'pattern' => '/(Pusdiklat|Pusharlis|Pusertif|Puslitbang|Pusmanpro|UP2W|Pusat|Head Office)/i'
        ],
        'uit' => [
            'key' => 'uit',
            'nama' => 'Unit Induk Transmisi (UIT)',
            'singkatan' => 'UIT',
            'pattern' => '/(UIT\b|Unit Induk Transmisi|UPT\b|ULTG\b)/i'
        ],
        'uiw' => [
            'key' => 'uiw',
            'nama' => 'Unit Induk Wilayah (UIW)',
            'singkatan' => 'UIW',
            'pattern' => '/(UIW\b|Unit Induk Wilayah)/i'
        ],
        'uip' => [
            'key' => 'uip',
            'nama' => 'Unit Induk Pembangunan (UIP)',
            'singkatan' => 'UIP',
            'pattern' => '/(UIP\b|Unit Induk Pembangunan|UPP\s+JBB|UPP\s+JBT|UPP\s+SUMBAG|UPP\s+KLB|UPP\s+KLT|UPP\s+MPA|UPP\s+NUS|UPP\s+SUL)/i'
        ],
        'uip3b' => [
            'key' => 'uip3b',
            'nama' => 'Unit Induk Penyaluran dan Pusat Pengatur Beban',
            'singkatan' => 'UIP3B',
            'pattern' => '/(UIP3B|Penyaluran)/i'
        ],
        'uip2b' => [
            'key' => 'uip2b',
            'nama' => 'Unit Induk Pusat Pengatur Beban Jawa, Madura, dan Bali',
            'singkatan' => 'UIP2B JAMALI',
            'pattern' => '/(UIP2B|JAMALI|Pusat Pengatur Beban Jawa)/i'
        ],
    ];

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
     * Dapatkan Base URL aplikasi
     */
    public static function getBaseUrl(): string
    {
        $envUrl = trim((string)($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        if ($envUrl !== '') {
            return rtrim($envUrl, '/');
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443 || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        return rtrim($protocol . $host, '/');
    }

    /**
     * Load map seluruh entitas perusahaan dari database
     */
    public static function getAllEntitiesMap(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT id, tipe, parent_id, nama, singkatan, alamat FROM entitas_perusahaan ORDER BY id ASC");
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $map = [];
        foreach ($all as $e) {
            $map[(int)$e['id']] = $e;
        }
        return $map;
    }

    /**
     * Resolusi hierarki entitas untuk menentukan group ('holding_unit' | 'subholding' | 'anak_perusahaan')
     * dan kategori spesifik jika berada di holding_unit.
     */
    public static function resolveEntityHierarchy(int $entitasId, array $allEntitiesMap): array
    {
        $chain = [];
        $currId = $entitasId;
        while ($currId && isset($allEntitiesMap[$currId])) {
            $curr = $allEntitiesMap[$currId];
            $chain[] = $curr;
            $currId = !empty($curr['parent_id']) ? (int)$curr['parent_id'] : 0;
        }

        // Cek apakah ada node ber-tipe subholding di rantai hierarki
        foreach ($chain as $node) {
            if (($node['tipe'] ?? '') === 'subholding') {
                return [
                    'group' => 'subholding',
                    'company' => $node,
                    'category' => null,
                    'category_name' => null,
                    'chain' => $chain
                ];
            }
        }

        // Cek apakah ada node ber-tipe anak_perusahaan di rantai hierarki
        foreach ($chain as $node) {
            if (($node['tipe'] ?? '') === 'anak_perusahaan') {
                return [
                    'group' => 'anak_perusahaan',
                    'company' => $node,
                    'category' => null,
                    'category_name' => null,
                    'chain' => $chain
                ];
            }
        }

        // Jika tidak masuk subholding/anak_perusahaan -> Kantor Pusat PLN & Unit-Unit
        $fullText = '';
        foreach ($chain as $node) {
            $fullText .= ' ' . ($node['nama'] ?? '') . ' ' . ($node['singkatan'] ?? '');
        }

        $catKey = 'pusat';
        $catName = self::CATEGORIES_PLN_PUSAT['pusat']['nama'];

        if (preg_match(self::CATEGORIES_PLN_PUSAT['uip2b']['pattern'], $fullText)) {
            $catKey = 'uip2b';
            $catName = self::CATEGORIES_PLN_PUSAT['uip2b']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['uip3b']['pattern'], $fullText)) {
            $catKey = 'uip3b';
            $catName = self::CATEGORIES_PLN_PUSAT['uip3b']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['uip']['pattern'], $fullText)) {
            $catKey = 'uip';
            $catName = self::CATEGORIES_PLN_PUSAT['uip']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['uit']['pattern'], $fullText)) {
            $catKey = 'uit';
            $catName = self::CATEGORIES_PLN_PUSAT['uit']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['uiw']['pattern'], $fullText)) {
            $catKey = 'uiw';
            $catName = self::CATEGORIES_PLN_PUSAT['uiw']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['uid']['pattern'], $fullText)) {
            $catKey = 'uid';
            $catName = self::CATEGORIES_PLN_PUSAT['uid']['nama'];
        } elseif (preg_match(self::CATEGORIES_PLN_PUSAT['pusat']['pattern'], $fullText)) {
            $catKey = 'pusat';
            $catName = self::CATEGORIES_PLN_PUSAT['pusat']['nama'];
        }

        return [
            'group' => 'holding_unit',
            'company' => null,
            'category' => $catKey,
            'category_name' => $catName,
            'chain' => $chain
        ];
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

        $cfg['link_data_publik'] = $defaultPublicUrl;
        $cfg['public_token'] = $token;
        $cfg['program_text'] = $progText;
        $cfg['is_default'] = false;
        return $cfg;
    }

    /**
     * Cari periode dan konfigurasi berdasarkan token publik
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
     * Ambil data lengkap seluruh mahasiswa diterima pada periode dan kelompokkan berdasarkan hierarki PLN
     */
    public static function getGroupedSuratData(PDO $pdo, int $periodeId): array
    {
        $entitiesMap = self::getAllEntitiesMap($pdo);

        // Ambil data semua mahasiswa diterima pada periode
        $sql = "
            SELECT 
                p.id AS pendaftaran_id,
                m.nim,
                p.nama_snapshot AS nama,
                p.jenis_kelamin,
                p.no_hp,
                m.email,
                CONCAT_WS(', ', p.alamat, CONCAT('RT ', p.rt, '/RW ', p.rw), p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi) AS alamat_domisili,
                p.ipk,
                p.jumlah_sks,
                COALESCE(GROUP_CONCAT(DISTINCT pem.nama SEPARATOR ', '), '-') AS peminatan,
                COALESCE(j.nama_jurusan, 'Program Studi Lain') AS prodi,
                upp.entitas_id,
                e.nama AS entitas_nama,
                e.singkatan AS entitas_singkatan,
                e.tipe AS entitas_tipe,
                p.program,
                p.catatan_admin,
                p.is_dipindahkan
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            LEFT JOIN jurusan j ON m.jurusan_id = j.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            LEFT JOIN pendaftaran_peminatan pp ON p.id = pp.pendaftaran_id
            LEFT JOIN peminatan pem ON pp.peminatan_id = pem.id
            WHERE p.periode_id = :pid AND p.status = 'diterima'
            GROUP BY p.id, m.nim, p.nama_snapshot, p.jenis_kelamin, p.no_hp, m.email, p.alamat, p.rt, p.rw, p.kelurahan, p.kecamatan, p.kota_kabupaten, p.provinsi, p.ipk, p.jumlah_sks, j.nama_jurusan, upp.entitas_id, e.nama, e.singkatan, e.tipe, p.program, p.catatan_admin, p.is_dipindahkan
            ORDER BY e.nama ASC, j.nama_jurusan ASC, p.nama_snapshot ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $periodeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Struktur penampung
        $holdingCategories = [];
        foreach (self::CATEGORIES_PLN_PUSAT as $k => $c) {
            $holdingCategories[$k] = [
                'key' => $k,
                'nama' => $c['nama'],
                'singkatan' => $c['singkatan'],
                'total' => 0,
                'students' => []
            ];
        }

        $holdingUnitStudents = [];
        $subholdings = [];
        $anakPerusahaan = [];

        // Inisialisasi daftar seluruh subholding & anak perusahaan terdaftar dari master data agar tidak kosong
        $allSH = $pdo->query("SELECT id, nama, singkatan, alamat FROM entitas_perusahaan WHERE tipe = 'subholding' ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allSH as $sh) {
            $subholdings[(int)$sh['id']] = [
                'id' => (int)$sh['id'],
                'nama' => $sh['nama'],
                'singkatan' => $sh['singkatan'],
                'alamat' => $sh['alamat'] ?: 'Jakarta',
                'tipe' => 'subholding',
                'total_mahasiswa' => 0,
                'sub_units' => [],
                'students' => []
            ];
        }

        $allAP = $pdo->query("SELECT id, nama, singkatan, alamat FROM entitas_perusahaan WHERE tipe = 'anak_perusahaan' ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allAP as $ap) {
            $anakPerusahaan[(int)$ap['id']] = [
                'id' => (int)$ap['id'],
                'nama' => $ap['nama'],
                'singkatan' => $ap['singkatan'],
                'alamat' => $ap['alamat'] ?: 'Jakarta',
                'tipe' => 'anak_perusahaan',
                'total_mahasiswa' => 0,
                'sub_units' => [],
                'students' => []
            ];
        }

        foreach ($rows as $row) {
            $entitasId = (int)$row['entitas_id'];
            $res = self::resolveEntityHierarchy($entitasId, $entitiesMap);

            // Format Lokasi Divisi / Unit Pelaksana & Unit Layanan dari rantai hierarki
            $chain = $res['chain'] ?? [];
            $unitPelaksanaNama = $row['entitas_nama'];
            $unitLayananNama = '-';

            // Jika entitas adalah unit_layanan (ULP), parent-nya adalah unit_pelaksana (UP3)
            if (($row['entitas_tipe'] ?? '') === 'unit_layanan' && isset($chain[1])) {
                $unitPelaksanaNama = $chain[1]['nama'];
                $unitLayananNama = $row['entitas_nama'];
            }

            $row['lokasi_unit_pelaksana'] = $unitPelaksanaNama;
            $row['lokasi_unit_layanan'] = $unitLayananNama;
            $row['hierarchy_group'] = $res['group'];
            $row['hierarchy_category'] = $res['category'];

            if ($res['group'] === 'holding_unit') {
                $catKey = $res['category'] ?? 'pusat';
                $holdingCategories[$catKey]['total']++;
                $holdingCategories[$catKey]['students'][] = $row;
                $holdingUnitStudents[] = $row;
            } elseif ($res['group'] === 'subholding') {
                $shId = (int)$res['company']['id'];
                if (!isset($subholdings[$shId])) {
                    $subholdings[$shId] = [
                        'id' => $shId,
                        'nama' => $res['company']['nama'],
                        'singkatan' => $res['company']['singkatan'],
                        'alamat' => $res['company']['alamat'] ?: 'Jakarta',
                        'tipe' => 'subholding',
                        'total_mahasiswa' => 0,
                        'sub_units' => [],
                        'students' => []
                    ];
                }
                $subholdings[$shId]['total_mahasiswa']++;
                $subholdings[$shId]['students'][] = $row;
                $subUnitKey = $row['entitas_nama'];
                $subholdings[$shId]['sub_units'][$subUnitKey] = ($subholdings[$shId]['sub_units'][$subUnitKey] ?? 0) + 1;
            } elseif ($res['group'] === 'anak_perusahaan') {
                $apId = (int)$res['company']['id'];
                if (!isset($anakPerusahaan[$apId])) {
                    $anakPerusahaan[$apId] = [
                        'id' => $apId,
                        'nama' => $res['company']['nama'],
                        'singkatan' => $res['company']['singkatan'],
                        'alamat' => $res['company']['alamat'] ?: 'Jakarta',
                        'tipe' => 'anak_perusahaan',
                        'total_mahasiswa' => 0,
                        'sub_units' => [],
                        'students' => []
                    ];
                }
                $anakPerusahaan[$apId]['total_mahasiswa']++;
                $anakPerusahaan[$apId]['students'][] = $row;
                $subUnitKey = $row['entitas_nama'];
                $anakPerusahaan[$apId]['sub_units'][$subUnitKey] = ($anakPerusahaan[$apId]['sub_units'][$subUnitKey] ?? 0) + 1;
            }
        }

        return [
            'holding_unit' => [
                'nama' => 'Kantor Pusat PLN dan Unit-Unit',
                'total_mahasiswa' => count($holdingUnitStudents),
                'categories' => $holdingCategories,
                'students' => $holdingUnitStudents
            ],
            'subholdings' => array_values($subholdings),
            'anak_perusahaan' => array_values($anakPerusahaan),
            'total_semua' => count($rows)
        ];
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
        return $formattedNum . '/' . ltrim($template, '/');
    }

    /**
     * Render Tabel Header Surat 4-Kolom Flat (Nomor | : | Nilai | Tanggal & Kepada Yth.)
     */
    public static function buildHeaderTableXml(
        string $nomorSurat,
        string $tanggalSurat,
        string $perihal,
        array $kepadaYthLines
    ): string {
        $perihalLines = explode("\n", str_replace("\r", "", $perihal));
        $perihalXml = '';
        foreach ($perihalLines as $pIdx => $pLine) {
            $pLine = trim($pLine);
            if ($pLine !== '') {
                $perihalXml .= ($pIdx > 0 ? '<w:br/>' : '') . self::escapeXml($pLine);
            }
        }

        $kepadaXml = '';
        foreach ($kepadaYthLines as $kIdx => $line) {
            $line = trim($line);
            if ($line !== '') {
                $isBold = ($kIdx < count($kepadaYthLines) - 1);
                $kepadaXml .= '<w:p><w:pPr><w:jc w:val="left"/><w:spacing w:line="240" w:lineRule="auto"/>' . ($isBold ? '<w:rPr><w:b/><w:sz w:val="22"/></w:rPr>' : '') . '</w:pPr><w:r><w:rPr>' . ($isBold ? '<w:b/>' : '') . '<w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($line) . '</w:t></w:r></w:p>';
            }
        }

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
          <!-- Row 3: Perihal & [Kepada Yth Detail] -->
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
              ' . $kepadaXml . '
            </w:tc>
          </w:tr>
        </w:tbl>';

        return $xml;
    }

    /**
     * Render Tabel Ringkasan pada Cover Surat (Unit | Jumlah)
     */
    public static function buildCoverSummaryTableXml(array $rows, int $grandTotal, string $totalLabel = 'Total'): string
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
            <w:gridCol w:w="5400"/>
            <w:gridCol w:w="1829"/>
          </w:tblGrid>
          <w:tr>
            <w:trPr><w:trHeight w:val="320" w:hRule="atLeast"/></w:trPr>
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="5400" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Unit</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="1829" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>Jumlah</w:t></w:r>
              </w:p>
            </w:tc>
          </w:tr>';

        foreach ($rows as $r) {
            $unitText = self::escapeXml($r['unit']);
            $count = (int)$r['jumlah'];
            $xml .= '
          <w:tr>
            <w:trPr><w:trHeight w:val="300" w:hRule="atLeast"/></w:trPr>
            <w:tc>
              <w:tcPr><w:tcW w:w="5400" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:ind w:left="120"/><w:jc w:val="left"/><w:rPr><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>' . $unitText . '</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="1829" w:type="dxa"/><w:vAlign w:val="center"/></w:tcPr>
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
              <w:tcPr><w:tcW w:w="5400" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F9F9F9"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:ind w:left="120"/><w:jc w:val="left"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($totalLabel) . '</w:t></w:r>
              </w:p>
            </w:tc>
            <w:tc>
              <w:tcPr><w:tcW w:w="1829" w:type="dxa"/><w:shd w:val="clear" w:color="auto" w:fill="F9F9F9"/><w:vAlign w:val="center"/></w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="22"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . $grandTotal . '</w:t></w:r>
              </w:p>
            </w:tc>
          </w:tr>
        </w:tbl>';

        return $xml;
    }

    /**
     * Render Tabel Lampiran Mahasiswa Konsolidasi PLN Pusat & Unit (OpenXML Landscape)
     * Format Kolom: No, NIM, Nama Mahasiswa, Jenis Kelamin, No. Kontak (HP), Email, Program Studi, (Lokasi Magang) Divisi/Unit Pelaksana, (Lokasi Magang) Unit Layanan, Keterangan
     */
    public static function buildLampiranTablePlnPusatXml(array $students): string
    {
        $cols = [
            ['title' => 'No', 'w' => 500, 'align' => 'center'],
            ['title' => 'NIM', 'w' => 1200, 'align' => 'center'],
            ['title' => 'Nama Mahasiswa', 'w' => 2200, 'align' => 'left'],
            ['title' => 'Jenis Kelamin', 'w' => 1100, 'align' => 'center'],
            ['title' => 'No. Kontak (HP)', 'w' => 1400, 'align' => 'center'],
            ['title' => 'Email', 'w' => 2200, 'align' => 'left'],
            ['title' => 'Program Studi', 'w' => 1800, 'align' => 'left'],
            ['title' => "(Lokasi Magang)\nDivisi/Unit Pelaksana", 'w' => 1800, 'align' => 'left'],
            ['title' => "(Lokasi Magang)\nUnit Layanan", 'w' => 1400, 'align' => 'left'],
            ['title' => 'Keterangan', 'w' => 1200, 'align' => 'left'],
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
            <w:trPr><w:trHeight w:val="420" w:hRule="atLeast"/><w:tblHeader/></w:trPr>';

        foreach ($cols as $c) {
            $titleLines = explode("\n", $c['title']);
            $titleXml = '';
            foreach ($titleLines as $idx => $tLine) {
                $titleXml .= ($idx > 0 ? '<w:br/>' : '') . self::escapeXml($tLine);
            }
            $xml .= '
            <w:tc>
              <w:tcPr>
                <w:tcW w:w="' . $c['w'] . '" w:type="dxa"/>
                <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                <w:vAlign w:val="center"/>
              </w:tcPr>
              <w:p>
                <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="17"/></w:rPr></w:pPr>
                <w:r><w:rPr><w:b/><w:sz w:val="17"/></w:rPr><w:t>' . $titleXml . '</w:t></w:r>
              </w:p>
            </w:tc>';
        }
        $xml .= '</w:tr>';

        foreach ($students as $idx => $s) {
            $no = $idx + 1;
            $nim = self::escapeXml((string)($s['nim'] ?? '-'));
            $nama = self::escapeXml((string)($s['nama'] ?? '-'));
            $jk = ($s['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : 'Laki - Laki';
            $hp = self::escapeXml((string)($s['no_hp'] ?? '-'));
            $email = self::escapeXml((string)($s['email'] ?? '-'));
            $prodi = self::escapeXml((string)($s['prodi'] ?? '-'));
            $unitPelaksana = self::escapeXml((string)($s['lokasi_unit_pelaksana'] ?? $s['entitas_nama'] ?? '-'));
            $unitLayanan = self::escapeXml((string)($s['lokasi_unit_layanan'] ?? '-'));
            if ($unitLayanan === '-') $unitLayanan = '';
            $ket = self::escapeXml((string)($s['catatan_admin'] ?? ''));

            $rowVals = [
                ['val' => (string)$no, 'w' => $cols[0]['w'], 'align' => 'center'],
                ['val' => $nim, 'w' => $cols[1]['w'], 'align' => 'center'],
                ['val' => $nama, 'w' => $cols[2]['w'], 'align' => 'left'],
                ['val' => $jk, 'w' => $cols[3]['w'], 'align' => 'center'],
                ['val' => $hp, 'w' => $cols[4]['w'], 'align' => 'center'],
                ['val' => $email, 'w' => $cols[5]['w'], 'align' => 'left'],
                ['val' => $prodi, 'w' => $cols[6]['w'], 'align' => 'left'],
                ['val' => $unitPelaksana, 'w' => $cols[7]['w'], 'align' => 'left'],
                ['val' => $unitLayanan, 'w' => $cols[8]['w'], 'align' => 'left'],
                ['val' => $ket, 'w' => $cols[9]['w'], 'align' => 'left'],
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
     * Render Tabel Lampiran Mahasiswa Subholding & Anak Perusahaan (OpenXML Landscape)
     * Format Kolom: No, NIM, Nama Mahasiswa, Jenis Kelamin, No. Kontak (HP), Email, Program Studi, Peminatan pada Prodi
     */
    public static function buildLampiranTableCompanyXml(array $students): string
    {
        $cols = [
            ['title' => 'No', 'w' => 500, 'align' => 'center'],
            ['title' => 'Nomor Induk Mahasiswa (NIM)', 'w' => 1500, 'align' => 'center'],
            ['title' => 'Nama Mahasiswa', 'w' => 2500, 'align' => 'left'],
            ['title' => 'Jenis Kelamin', 'w' => 1100, 'align' => 'center'],
            ['title' => 'No. Kontak (HP)', 'w' => 1400, 'align' => 'center'],
            ['title' => 'Email', 'w' => 2500, 'align' => 'left'],
            ['title' => 'Program Studi', 'w' => 2000, 'align' => 'left'],
            ['title' => 'Peminatan pada Prodi', 'w' => 2940, 'align' => 'left'],
        ];

        $totalW = array_sum(array_column($cols, 'w'));

        // Kelompokkan mahasiswa per sub-unit jika terdapat beberapa sub-unit
        $grouped = [];
        foreach ($students as $s) {
            $subUnit = $s['entitas_nama'] ?? 'Kantor Pusat';
            $grouped[$subUnit][] = $s;
        }

        $xml = '';

        foreach ($grouped as $subUnitName => $subStudents) {
            // Header nama sub-unit (misal: ICON+ Cawang, UBP Suralaya, dll.)
            if (count($grouped) > 1 || $subUnitName !== 'Kantor Pusat') {
                $xml .= '
                <w:p>
                  <w:pPr>
                    <w:spacing w:line="240" w:lineRule="auto" w:before="180" w:after="60"/>
                    <w:ind w:left="0"/>
                    <w:jc w:val="left"/>
                    <w:rPr><w:b/><w:sz w:val="22"/></w:rPr>
                  </w:pPr>
                  <w:r><w:rPr><w:b/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($subUnitName) . '</w:t></w:r>
                </w:p>';
            }

            $xml .= '<w:tbl xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
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
                    <w:shd w:val="clear" w:color="auto" w:fill="F2F2F2"/>
                    <w:vAlign w:val="center"/>
                  </w:tcPr>
                  <w:p>
                    <w:pPr><w:jc w:val="center"/><w:rPr><w:b/><w:sz w:val="17"/></w:rPr></w:pPr>
                    <w:r><w:rPr><w:b/><w:sz w:val="17"/></w:rPr><w:t>' . self::escapeXml($c['title']) . '</w:t></w:r>
                  </w:p>
                </w:tc>';
            }
            $xml .= '</w:tr>';

            foreach ($subStudents as $idx => $s) {
                $no = $idx + 1;
                $nim = self::escapeXml((string)($s['nim'] ?? '-'));
                $nama = self::escapeXml((string)($s['nama'] ?? '-'));
                $jk = ($s['jenis_kelamin'] ?? '') === 'P' ? 'Perempuan' : 'Laki - Laki';
                $hp = self::escapeXml((string)($s['no_hp'] ?? '-'));
                $email = self::escapeXml((string)($s['email'] ?? '-'));
                $prodi = self::escapeXml((string)($s['prodi'] ?? '-'));
                $peminatan = self::escapeXml((string)($s['peminatan'] ?? '-'));

                $rowVals = [
                    ['val' => (string)$no, 'w' => $cols[0]['w'], 'align' => 'center'],
                    ['val' => $nim, 'w' => $cols[1]['w'], 'align' => 'center'],
                    ['val' => $nama, 'w' => $cols[2]['w'], 'align' => 'left'],
                    ['val' => $jk, 'w' => $cols[3]['w'], 'align' => 'center'],
                    ['val' => $hp, 'w' => $cols[4]['w'], 'align' => 'center'],
                    ['val' => $email, 'w' => $cols[5]['w'], 'align' => 'left'],
                    ['val' => $prodi, 'w' => $cols[6]['w'], 'align' => 'left'],
                    ['val' => $peminatan, 'w' => $cols[7]['w'], 'align' => 'left'],
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
        }

        return $xml;
    }

    /**
     * Generate Dokumen Surat Konsolidasi Kantor Pusat PLN dan Unit-Unit (.docx)
     */
    public static function generateDocxForPlnPusat(array $config, array $plnPusatData, int $sequenceNumber): string
    {
        $templatePath = dirname(__DIR__) . '/docs/Contoh Surat.docx';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('File template docx tidak ditemukan di: ' . $templatePath);
        }

        $tempDir = sys_get_temp_dir() . '/remate_surat_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $outPath = $tempDir . '/Surat_Pengantar_PLN_Pusat_dan_Unit2.docx';
        copy($templatePath, $outPath);

        $zip = new ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \RuntimeException('Gagal membuka template docx untuk penulisan.');
        }

        // Header Landscape
        $header2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.jpeg"/>
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

        $nomorSurat = self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $sequenceNumber);
        $tanggalSurat = $config['tanggal_surat'] ?? ('Jakarta, ' . self::formatIndonesianDate(date('Y-m-d')));
        $perihal = $config['perihal'] ?? 'Penyampaian Penempatan Mahasiswa Magang 5 Bulan PLN Group Tahun Ajaran 2026/2027';
        $tahunAkademik = $config['tahun_akademik'] ?? (date('Y') . '/' . (date('Y') + 1));
        $programText = $config['program_text'] ?? '5 Bulan';
        $jabatanTtd = $config['jabatan_penandatangan'] ?? 'Wakil Rektor III Bidang Kemahasiswaan';
        $namaTtd = $config['nama_penandatangan'] ?? 'Ir. Purnomo, S.T., M.T.';
        $linkData = $config['link_data_publik'] ?? '';

        $totalMahasiswa = (int)($plnPusatData['total_mahasiswa'] ?? 0);
        $categories = $plnPusatData['categories'] ?? [];
        $students = $plnPusatData['students'] ?? [];

        // Baris Kepada Yth. untuk PLN Pusat & Unit
        $kepadaYth = [
            'Executive Vice President',
            'Human Talent Development',
            'PT PLN (Persero)',
            'Jl. Trunojoyo Blok M1/135',
            'Kebayoran Baru, Jakarta Selatan'
        ];

        // Format data tabel cover (7 unit kategori)
        $coverRows = [];
        foreach ($categories as $cat) {
            $coverRows[] = [
                'unit' => $cat['nama'],
                'jumlah' => $cat['total']
            ];
        }

        $headerTableXml = self::buildHeaderTableXml($nomorSurat, $tanggalSurat, $perihal, $kepadaYth);
        $coverTableXml = self::buildCoverSummaryTableXml($coverRows, $totalMahasiswa, 'Grand Total');
        $lampiranTableXml = self::buildLampiranTablePlnPusatXml($students);

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
    <!-- Section 1 Header Table -->
    ' . $headerTableXml . '

    <!-- Spacing -->
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:spacing w:before="140"/></w:pPr></w:p>

    <!-- Salam Pembuka & Isi -->
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
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Merujuk pada hasil koordinasi dan diskusi penyesuaian penempatan magang antara ITPLN, HTD PLN Pusat, Subholding, dan Anak Perusahaan di lingkungan PLN Group, bersama ini kami menyampaikan data penempatan mahasiswa Program Magang ' . self::escapeXml($programText) . ' PLN Group Tahun Ajaran ' . self::escapeXml($tahunAkademik) . ' yang telah difinalkan sebanyak ' . $totalMahasiswa . ' (' . self::terbilang($totalMahasiswa) . ') mahasiswa.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="left"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Berikut rincian jumlah mahasiswa pada unit:</w:t></w:r>
    </w:p>
    ' . $coverTableXml . '
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="80"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Kami mohon dukungan Bapak/Ibu dalam memberikan pembimbingan serta menunjuk mentor lapangan agar pelaksanaan magang dapat berjalan dengan baik. Mahasiswa akan mematuhi ketentuan dan tata tertib yang berlaku di unit selama kegiatan berlangsung.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Rincian daftar peserta magang dapat dilihat pada berkas yang disediakan melalui tautan:</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="30" w:after="40"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="left"/>
      </w:pPr>
      <w:r><w:rPr><w:color w:val="0563C1"/><w:u w:val="single" w:color="0563C1"/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($linkData) . '</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
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
        <w:spacing w:before="100"/>
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
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>DAFTAR NAMA MAHASISWA</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:line="276" w:lineRule="auto" w:before="0" w:after="40"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="24"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>MAGANG MBKM PLN GRUP DI</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:line="300" w:lineRule="auto" w:before="0" w:after="160"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="26"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>PLN PUSAT DAN UNIT</w:t></w:r>
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
     * Generate Dokumen Surat per Perusahaan Subholding atau Anak Perusahaan (.docx)
     */
    public static function generateDocxForCompany(array $config, array $companyData, string $type, int $sequenceNumber): string
    {
        $templatePath = dirname(__DIR__) . '/docs/Contoh Surat.docx';
        if (!file_exists($templatePath)) {
            throw new \RuntimeException('File template docx tidak ditemukan di: ' . $templatePath);
        }

        $tempDir = sys_get_temp_dir() . '/remate_surat_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $safeCompanyName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $companyData['nama'] ?? 'Perusahaan');
        $outPath = $tempDir . '/Surat_Pengantar_' . $safeCompanyName . '.docx';
        copy($templatePath, $outPath);

        $zip = new ZipArchive();
        if ($zip->open($outPath) !== true) {
            throw new \RuntimeException('Gagal membuka template docx untuk penulisan.');
        }

        // Header Landscape
        $header2Rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.jpeg"/>
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

        $nomorSurat = self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $sequenceNumber);
        $tanggalSurat = $config['tanggal_surat'] ?? ('Jakarta, ' . self::formatIndonesianDate(date('Y-m-d')));
        $perihal = "Surat Pengantar Magang " . ($config['program_text'] ?? '5 Bulan') . " PLN Group Tahun Ajaran " . ($config['tahun_akademik'] ?? (date('Y') . '/' . (date('Y') + 1)));
        $jabatanTtd = $config['jabatan_penandatangan'] ?? 'Wakil Rektor III Bidang Kemahasiswaan';
        $namaTtd = $config['nama_penandatangan'] ?? 'Ir. Purnomo, S.T., M.T.';
        $linkData = $config['link_data_publik'] ?? '';

        $companyNama = $companyData['nama'] ?? 'Perusahaan PLN';
        $companyAlamat = $companyData['alamat'] ?? 'Jakarta';
        $totalMahasiswa = (int)($companyData['total_mahasiswa'] ?? 0);
        $students = $companyData['students'] ?? [];
        $subUnits = $companyData['sub_units'] ?? [];

        // Penyesuaian Kepada Yth. berdasarkan tipe entitas
        $kepadaYth = [];
        if ($type === 'subholding') {
            $kepadaYth = [
                'Direktur',
                'Manajemen Human Capital dan Administrasi',
                $companyNama,
                $companyAlamat
            ];
        } else {
            // Anak Perusahaan
            if (stripos($companyNama, 'Batam') !== false) {
                $kepadaYth = [
                    'Direktur',
                    'Keuangan, Manajemen Resiko dan Human Capital',
                    $companyNama,
                    $companyAlamat
                ];
            } else {
                $kepadaYth = [
                    'Direktur Utama',
                    $companyNama,
                    $companyAlamat
                ];
            }
        }

        // Tabel Cover: Rincian sub-unit atau nama entitas
        $coverRows = [];
        if (!empty($subUnits)) {
            foreach ($subUnits as $sUnit => $sCount) {
                $coverRows[] = [
                    'unit' => $sUnit,
                    'jumlah' => $sCount
                ];
            }
        } else {
            $coverRows[] = [
                'unit' => 'Kantor Pusat ' . ($companyData['singkatan'] ?? $companyNama),
                'jumlah' => $totalMahasiswa
            ];
        }

        $headerTableXml = self::buildHeaderTableXml($nomorSurat, $tanggalSurat, $perihal, $kepadaYth);
        $coverTableXml = self::buildCoverSummaryTableXml($coverRows, $totalMahasiswa, 'Total');
        $lampiranTableXml = self::buildLampiranTableCompanyXml($students);

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
    <!-- Section 1 Header Table -->
    ' . $headerTableXml . '

    <!-- Spacing -->
    <w:p><w:pPr><w:pStyle w:val="BodyText"/><w:spacing w:before="140"/></w:pPr></w:p>

    <!-- Salam Pembuka & Isi -->
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
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Bersama surat ini kami mengirimkan mahasiswa Institut Teknologi PLN yang tercantum dalam lampiran untuk melaksanakan Program Magang yang sudah ditetapkan bersama PLN.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="left"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Berikut rincian jumlah mahasiswa pada unit:</w:t></w:r>
    </w:p>
    ' . $coverTableXml . '
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="80"/>
        <w:ind w:left="1724" w:right="52"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Kami mohon dukungan Bapak/Ibu dalam memberikan pembimbingan serta menunjuk mentor lapangan agar pelaksanaan magang dapat berjalan dengan baik. Mahasiswa akan mematuhi ketentuan dan tata tertib yang berlaku di unit selama kegiatan berlangsung.</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="both"/>
      </w:pPr>
      <w:r><w:rPr><w:sz w:val="22"/></w:rPr><w:t>Rincian daftar peserta magang dapat dilihat pada berkas yang disediakan melalui tautan:</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="30" w:after="40"/>
        <w:ind w:left="1724" w:right="54"/>
        <w:jc w:val="left"/>
      </w:pPr>
      <w:r><w:rPr><w:color w:val="0563C1"/><w:u w:val="single" w:color="0563C1"/><w:sz w:val="22"/></w:rPr><w:t>' . self::escapeXml($linkData) . '</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:pStyle w:val="BodyText"/>
        <w:spacing w:before="60"/>
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
        <w:spacing w:before="100"/>
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
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>DAFTAR NAMA MAHASISWA</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:line="276" w:lineRule="auto" w:before="0" w:after="40"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="24"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="24"/></w:rPr><w:t>MAGANG MBKM PLN GRUP DI</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr>
        <w:spacing w:line="300" w:lineRule="auto" w:before="0" w:after="160"/>
        <w:ind w:left="0" w:right="0" w:firstLine="0"/>
        <w:jc w:val="center"/>
        <w:rPr><w:b/><w:sz w:val="26"/></w:rPr>
      </w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/></w:rPr><w:t>' . self::escapeXml(strtoupper($companyNama)) . '</w:t></w:r>
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
     * Dapatkan daftar seluruh dokumen yang dapat diterbitkan untuk suatu periode
     */
    public static function getDocxListForPeriod(PDO $pdo, int $periodeId): array
    {
        $groupedData = self::getGroupedSuratData($pdo, $periodeId);
        $config = self::getConfig($pdo, $periodeId);
        $startNum = (int)($config['nomor_surat_start'] ?? 1);
        $seq = $startNum;

        $docList = [];

        // 1. Dokumen Konsolidasi PLN Pusat & Unit
        if (!empty($groupedData['holding_unit']['students'])) {
            $docList[] = [
                'type' => 'holding_unit',
                'target_id' => 0,
                'nama_dokumen' => 'Surat Pengantar PLN Pusat dan Unit-Unit',
                'nomor_surat' => self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $seq),
                'penerima' => 'Executive Vice President HTD PT PLN (Persero)',
                'total_mahasiswa' => $groupedData['holding_unit']['total_mahasiswa'],
                'sequence' => $seq
            ];
            $seq++;
        }

        // 2. Dokumen Subholding
        foreach ($groupedData['subholdings'] as $sh) {
            if ($sh['total_mahasiswa'] > 0) {
                $docList[] = [
                    'type' => 'subholding',
                    'target_id' => $sh['id'],
                    'nama_dokumen' => 'Surat Pengantar ' . $sh['nama'],
                    'nomor_surat' => self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $seq),
                    'penerima' => 'Direktur Manajemen Human Capital dan Administrasi ' . $sh['nama'],
                    'total_mahasiswa' => $sh['total_mahasiswa'],
                    'sequence' => $seq
                ];
                $seq++;
            }
        }

        // 3. Dokumen Anak Perusahaan
        foreach ($groupedData['anak_perusahaan'] as $ap) {
            if ($ap['total_mahasiswa'] > 0) {
                $docList[] = [
                    'type' => 'anak_perusahaan',
                    'target_id' => $ap['id'],
                    'nama_dokumen' => 'Surat Pengantar ' . $ap['nama'],
                    'nomor_surat' => self::formatNomorSurat($config['nomor_surat_template'] ?? '{nomor}/Srt/1/D0/08/' . date('Y'), $seq),
                    'penerima' => 'Direktur ' . $ap['nama'],
                    'total_mahasiswa' => $ap['total_mahasiswa'],
                    'sequence' => $seq
                ];
                $seq++;
            }
        }

        return $docList;
    }

    /**
     * Generate file DOCX untuk satu target tertentu (holding_unit, subholding ID, atau anak_perusahaan ID)
     */
    public static function generateDocxByTarget(PDO $pdo, int $periodeId, string $targetType, int $targetId = 0): string
    {
        $config = self::getConfig($pdo, $periodeId);
        $grouped = self::getGroupedSuratData($pdo, $periodeId);
        $docList = self::getDocxListForPeriod($pdo, $periodeId);

        // Cari sequence number dokumen ini
        $sequence = (int)($config['nomor_surat_start'] ?? 1);
        foreach ($docList as $d) {
            if ($d['type'] === $targetType && (int)$d['target_id'] === $targetId) {
                $sequence = (int)$d['sequence'];
                break;
            }
        }

        if ($targetType === 'holding_unit') {
            if (empty($grouped['holding_unit']['students'])) {
                throw new \RuntimeException('Tidak ada data mahasiswa di PLN Pusat dan Unit.');
            }
            return self::generateDocxForPlnPusat($config, $grouped['holding_unit'], $sequence);
        }

        if ($targetType === 'subholding') {
            foreach ($grouped['subholdings'] as $sh) {
                if ((int)$sh['id'] === $targetId) {
                    if (empty($sh['students'])) {
                        throw new \RuntimeException('Tidak ada data mahasiswa di subholding ' . $sh['nama']);
                    }
                    return self::generateDocxForCompany($config, $sh, 'subholding', $sequence);
                }
            }
            throw new \RuntimeException('Subholding dengan ID ' . $targetId . ' tidak ditemukan.');
        }

        if ($targetType === 'anak_perusahaan') {
            foreach ($grouped['anak_perusahaan'] as $ap) {
                if ((int)$ap['id'] === $targetId) {
                    if (empty($ap['students'])) {
                        throw new \RuntimeException('Tidak ada data mahasiswa di anak perusahaan ' . $ap['nama']);
                    }
                    return self::generateDocxForCompany($config, $ap, 'anak_perusahaan', $sequence);
                }
            }
            throw new \RuntimeException('Anak perusahaan dengan ID ' . $targetId . ' tidak ditemukan.');
        }

        throw new \RuntimeException('Tipe target surat tidak valid.');
    }

    /**
     * Generate arsip ZIP berisi seluruh dokumen surat terkelompok
     */
    public static function generateAllZip(PDO $pdo, int $periodeId): string
    {
        $config = self::getConfig($pdo, $periodeId);
        $docList = self::getDocxListForPeriod($pdo, $periodeId);

        if (empty($docList)) {
            throw new \RuntimeException('Tidak ada mahasiswa berstatus diterima pada periode ini untuk dibuatkan surat.');
        }

        $tempDir = sys_get_temp_dir() . '/remate_zip_' . uniqid();
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $zipPath = $tempDir . '/Surat_Penempatan_PLN_Group_Periode_' . $periodeId . '.zip';
        $zipArchive = new ZipArchive();
        if ($zipArchive->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal membuat arsip ZIP.');
        }

        foreach ($docList as $doc) {
            $docxPath = self::generateDocxByTarget($pdo, $periodeId, $doc['type'], (int)$doc['target_id']);
            $cleanName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $doc['nama_dokumen']) . '.docx';
            $zipArchive->addFile($docxPath, $cleanName);
        }

        $zipArchive->close();
        return $zipPath;
    }
}

<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;

class PengajuanUnitHelper
{
    /**
     * Normalisasi nama unit untuk perbandingan fuzzy.
     * Mengubah ke huruf kecil, menghapus tanda baca, dan membuang stop words umum BUMN/PLN.
     */
    public static function normalizeNama(string $nama): string
    {
        $clean = mb_strtolower($nama, 'UTF-8');
        // Ganti karakter non-alfanumerik dengan spasi
        $clean = preg_replace('/[^a-z0-9]+/i', ' ', $clean) ?? '';
        
        $tokens = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopWords = [
            'pt', 'persero', 'perseroan', 'terbatas', 'pln', 'unit',
            'kantor', 'tbk', 'induk', 'pelaksana', 'layanan'
        ];

        // Jika setelah buang stopwords masih ada token tersisa, buang stopwords.
        // Jika token habis, biarkan token aslinya agar tidak menjadi string kosong.
        $filtered = array_values(array_filter($tokens, fn($t) => !in_array($t, $stopWords, true)));
        $finalTokens = !empty($filtered) ? $filtered : $tokens;

        return implode(' ', $finalTokens);
    }

    /**
     * Normalisasi nomor kontak / WhatsApp menjadi format Indonesia 62...
     * Valid jika panjang 10-15 digit dan berawalan 62.
     * Mengembalikan null jika tidak valid.
     */
    public static function normalizeWa(string $wa): ?string
    {
        $digits = preg_replace('/\D+/', '', $wa) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '08')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }

        if (!str_starts_with($digits, '62')) {
            return null;
        }

        $len = strlen($digits);
        if ($len < 10 || $len > 15) {
            return null;
        }

        return $digits;
    }

    /**
     * Hitung skor kemiripan (0.0 - 1.0) antara dua string yang sudah dinormalisasi.
     */
    public static function similarity(string $a, string $b): float
    {
        $a = trim($a);
        $b = trim($b);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        // Jika satu string memuat string lain secara penuh (dengan panjang minimal 3)
        if (str_contains($a, $b) || str_contains($b, $a)) {
            $minLen = min(strlen($a), strlen($b));
            $maxLen = max(strlen($a), strlen($b));
            if ($minLen >= 3) {
                $containScore = $maxLen > 0 ? ($minLen / $maxLen) : 0.0;
                return max(0.85, $containScore);
            }
        }

        similar_text($a, $b, $simPercent);
        $simScore = $simPercent / 100.0;

        $maxLen = max(strlen($a), strlen($b));
        $levDist = levenshtein($a, $b);
        $levScore = $maxLen > 0 ? max(0.0, 1.0 - ($levDist / $maxLen)) : 0.0;

        $fullScore = ($simScore * 0.6) + ($levScore * 0.4);

        // Token-level check: jika query terdiri dari kata-kata yang cocok dengan target
        $tokensA = array_filter(explode(' ', $a));
        $tokensB = array_filter(explode(' ', $b));
        $tokenScores = [];
        foreach ($tokensA as $tA) {
            $best = 0.0;
            foreach ($tokensB as $tB) {
                if ($tA === $tB) {
                    $best = 1.0;
                    break;
                }
                $minL = min(strlen($tA), strlen($tB));
                $maxL = max(strlen($tA), strlen($tB));
                if ($minL >= 3 && (str_contains($tB, $tA) || str_contains($tA, $tB))) {
                    $best = max($best, max(0.85, $minL / $maxL));
                } else {
                    similar_text($tA, $tB, $wp);
                    $wScore = $wp / 100.0;
                    $wLev = levenshtein($tA, $tB);
                    $wLevScore = $maxL > 0 ? max(0.0, 1.0 - ($wLev / $maxL)) : 0.0;
                    $best = max($best, ($wScore * 0.6) + ($wLevScore * 0.4));
                }
            }
            $tokenScores[] = $best;
        }
        $tokenAvg = !empty($tokenScores) ? (array_sum($tokenScores) / count($tokenScores)) : 0.0;

        return round(max($fullScore, $tokenAvg), 4);
    }

    /**
     * Dapatkan master entitas aktif dengan caching 5 menit.
     */
    public static function getCachedActiveEntities(PDO $pdo): array
    {
        $cacheFile = dirname(__DIR__) . '/cache/active_entities.json';
        $now = time();

        if (file_exists($cacheFile) && ($now - filemtime($cacheFile)) < 300) {
            $content = file_get_contents($cacheFile);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $stmt = $pdo->query("
            SELECT id, nama, singkatan, tipe, alamat, parent_id
            FROM entitas_perusahaan
            WHERE aktif = 1
            ORDER BY nama ASC
        ");
        $entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Precompute normalisasi untuk optimasi performa pencarian
        foreach ($entities as &$ent) {
            $ent['id'] = (int)$ent['id'];
            $ent['norm_nama'] = self::normalizeNama($ent['nama'] ?? '');
            $ent['norm_singkatan'] = !empty($ent['singkatan']) ? mb_strtolower(trim($ent['singkatan']), 'UTF-8') : '';
        }
        unset($ent);

        @file_put_contents($cacheFile, json_encode($entities));
        return $entities;
    }

    /**
     * Cari unit terdaftar atau usulan aktif yang mirip dengan input query.
     * Mengembalikan maksimal $limit kandidat dengan skor >= 0.60.
     */
    public static function findSimilar(PDO $pdo, string $query, int $limit = 5): array
    {
        $normQ = self::normalizeNama($query);
        $rawQ = mb_strtolower(trim($query), 'UTF-8');

        if (mb_strlen($normQ, 'UTF-8') < 2 && mb_strlen($rawQ, 'UTF-8') < 2) {
            return [];
        }

        $results = [];

        // 1. Periksa entitas terdaftar
        $entities = self::getCachedActiveEntities($pdo);
        foreach ($entities as $ent) {
            $score1 = self::similarity($normQ, $ent['norm_nama']);
            $score2 = 0.0;
            if ($ent['norm_singkatan'] !== '') {
                if ($rawQ === $ent['norm_singkatan'] || $normQ === $ent['norm_singkatan']) {
                    $score2 = 1.0;
                } else {
                    $score2 = self::similarity($rawQ, $ent['norm_singkatan']);
                }
            }

            $maxScore = max($score1, $score2);
            if ($maxScore >= 0.58) {
                $results[] = [
                    'sumber'           => 'entitas',
                    'id'               => $ent['id'],
                    'nama'             => $ent['nama'],
                    'singkatan'        => $ent['singkatan'],
                    'tipe'             => $ent['tipe'],
                    'alamat'           => $ent['alamat'],
                    'skor'             => $maxScore,
                    'jumlah_pendukung' => 0
                ];
            }
        }

        // 2. Periksa usulan unit baru yang aktif (status 'menunggu' atau 'diproses')
        $stmtUsulan = $pdo->query("
            SELECT 
                p.id,
                p.nama_unit_baru,
                p.nama_unit_normal,
                p.alamat_unit_baru,
                (SELECT COUNT(*) FROM pengajuan_unit_mahasiswa p2 
                 WHERE p2.usulan_induk_id = p.id AND p2.status IN ('menunggu', 'diproses')) AS jumlah_pendukung
            FROM pengajuan_unit_mahasiswa p
            WHERE p.jenis = 'usulan_unit_baru' 
              AND p.status IN ('menunggu', 'diproses')
            ORDER BY p.id DESC
        ");
        $usulanList = $stmtUsulan->fetchAll(PDO::FETCH_ASSOC);

        foreach ($usulanList as $usulan) {
            $normTarget = $usulan['nama_unit_normal'] ?: self::normalizeNama($usulan['nama_unit_baru'] ?? '');
            $score = self::similarity($normQ, $normTarget);

            if ($score >= 0.58) {
                $results[] = [
                    'sumber'           => 'usulan',
                    'id'               => (int)$usulan['id'],
                    'nama'             => $usulan['nama_unit_baru'],
                    'singkatan'        => null,
                    'tipe'             => 'Usulan Baru',
                    'alamat'           => $usulan['alamat_unit_baru'],
                    'skor'             => $score,
                    'jumlah_pendukung' => (int)$usulan['jumlah_pendukung'] + 1 // +1 pengusul awal
                ];
            }
        }

        // Urutkan berdasarkan skor tertinggi
        usort($results, fn($a, $b) => $b['skor'] <=> $a['skor']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Hitung jumlah pengajuan aktif milik mahasiswa (status 'menunggu' atau 'diproses').
     */
    public static function countActive(PDO $pdo, int $mahasiswaId): int
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM pengajuan_unit_mahasiswa 
            WHERE mahasiswa_id = :mid AND status IN ('menunggu', 'diproses')
        ");
        $stmt->execute([':mid' => $mahasiswaId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Cek apakah mahasiswa sudah memiliki pengajuan aktif untuk unit terdaftar tertentu.
     */
    public static function hasActiveRequestForUnit(PDO $pdo, int $mahasiswaId, int $entitasId): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM pengajuan_unit_mahasiswa 
            WHERE mahasiswa_id = :mid 
              AND entitas_id = :eid 
              AND status IN ('menunggu', 'diproses')
            LIMIT 1
        ");
        $stmt->execute([':mid' => $mahasiswaId, ':eid' => $entitasId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Cek apakah mahasiswa sudah mengajukan atau mendukung usulan unit baru tertentu.
     */
    public static function hasActiveRequestForProposal(PDO $pdo, int $mahasiswaId, int $usulanId): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1 
            FROM pengajuan_unit_mahasiswa 
            WHERE mahasiswa_id = :mid 
              AND (id = :uid1 OR usulan_induk_id = :uid2)
              AND status IN ('menunggu', 'diproses')
            LIMIT 1
        ");
        $stmt->execute([
            ':mid' => $mahasiswaId,
            ':uid1' => $usulanId,
            ':uid2' => $usulanId
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Dapatkan periode acuan untuk menampilkan status kuota unit:
     * Prioritas 1: Periode berstatus 'dibuka' terbaru
     * Prioritas 2: Periode berstatus 'persiapan' terbaru
     * Jika tidak ada, kembalikan null (off-season).
     */
    public static function getPeriodeAcuan(PDO $pdo): ?array
    {
        $stmt = $pdo->query("
            SELECT id, nama, status, tanggal_mulai, tanggal_selesai, angkatan_eligible
            FROM periode
            WHERE status IN ('dibuka', 'persiapan')
            ORDER BY 
                CASE status WHEN 'dibuka' THEN 1 WHEN 'persiapan' THEN 2 ELSE 3 END ASC,
                id DESC
            LIMIT 1
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Otomatis setujui pengajuan permintaan kuota mahasiswa ketika admin mengonfigurasi kuota unit.
     */
    public static function autoApproveByEntitas(PDO $pdo, int $entitasId, int $adminId): int
    {
        // 1. Ambil id pengajuan yang akan disetujui
        $stmtFind = $pdo->prepare("
            SELECT id, mahasiswa_id 
            FROM pengajuan_unit_mahasiswa 
            WHERE entitas_id = :eid AND status IN ('menunggu', 'diproses')
        ");
        $stmtFind->execute([':eid' => $entitasId]);
        $rows = $stmtFind->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return 0;
        }

        $stmtUpd = $pdo->prepare("
            UPDATE pengajuan_unit_mahasiswa 
            SET status = 'disetujui',
                alasan_admin = 'Kuota unit telah dibuka oleh admin.',
                diproses_oleh = :aid,
                diproses_at = NOW(),
                updated_at = NOW()
            WHERE entitas_id = :eid AND status IN ('menunggu', 'diproses')
        ");
        $stmtUpd->execute([':aid' => $adminId, ':eid' => $entitasId]);
        $count = $stmtUpd->rowCount();

        // Log audit
        self::logAdmin($pdo, $adminId, 'auto_approve_pengajuan_kuota', $entitasId, [
            'entitas_id'     => $entitasId,
            'affected_count' => $count,
            'pengajuan_ids'  => array_column($rows, 'id')
        ]);

        return $count;
    }

    /**
     * Catat log aktivitas admin ke tabel log_aktivitas.
     */
    public static function logAdmin(PDO $pdo, int $adminId, string $aksi, int $id, array $detail = []): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
                VALUES (:admin_id, :aksi, 'pengajuan_unit_mahasiswa', :id, :detail, :ip, NOW())
            ");
            $stmt->execute([
                ':admin_id' => $adminId,
                ':aksi'     => $aksi,
                ':id'       => $id,
                ':detail'   => json_encode($detail, JSON_UNESCAPED_UNICODE),
                ':ip'       => Auth::getClientIp()
            ]);
        } catch (Throwable) {
            // Fail silently on logging error to avoid breaking primary transaction
        }
    }
}

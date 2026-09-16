<?php

declare(strict_types=1);

namespace App;

use PDO;

class PeriodeHelper
{
    /**
     * Memeriksa apakah suatu data periode telah melewati batas waktu server (tanggal_selesai + jam_selesai).
     *
     * @param array $periode Data baris periode yang memuat 'tanggal_selesai' dan opsional 'jam_selesai'
     * @return bool True jika waktu server saat ini sudah sama atau melewati waktu penutupan
     */
    public static function isPeriodeExpired(array $periode): bool
    {
        if (empty($periode['tanggal_selesai'])) {
            return false;
        }

        $jamSelesai = !empty($periode['jam_selesai']) ? (string)$periode['jam_selesai'] : '23:59:00';
        // Pastikan format jam memiliki detik
        if (preg_match('/^\d{2}:\d{2}$/', $jamSelesai)) {
            $jamSelesai .= ':00';
        }

        $deadlineStr = trim($periode['tanggal_selesai']) . ' ' . $jamSelesai;
        $deadlineTs = strtotime($deadlineStr);

        if ($deadlineTs === false) {
            return false;
        }

        return time() >= $deadlineTs;
    }

    /**
     * Menutup seluruh periode magang yang berstatus 'dibuka' namun telah melewati waktu penutupan server,
     * sekaligus membatalkan reservasi 'ditahan' yang belum selesai dan mengembalikan kuota unit.
     *
     * @param PDO $pdo Koneksi PDO
     * @return array Daftar ID periode yang berhasil ditutup
     */
    public static function closeExpiredPeriodes(PDO $pdo): array
    {
        // 1. Cari periode aktif yang sudah melewati batas waktu server (WIB)
        $stmt = $pdo->prepare("
            SELECT id, nama, tanggal_selesai, jam_selesai 
            FROM periode 
            WHERE status = 'dibuka' 
              AND CONCAT(tanggal_selesai, ' ', COALESCE(jam_selesai, '23:59:00')) <= NOW()
        ");
        $stmt->execute();
        $expiredPeriodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredPeriodes)) {
            return [];
        }

        $closedIds = [];

        foreach ($expiredPeriodes as $p) {
            $pid = (int)$p['id'];

            Database::transaction(function (PDO $pdo) use ($pid, $p, &$closedIds) {
                // Update status periode -> ditutup
                $pdo->prepare("UPDATE periode SET status = 'ditutup' WHERE id = ?")->execute([$pid]);

                // Ambil semua reservasi 'ditahan' pada unit-unit yang terhubung ke periode ini
                $stmtRes = $pdo->prepare("
                    SELECT r.id, r.unit_pelaksana_periode_id
                    FROM reservasi r
                    JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id
                    WHERE upp.periode_id = ? AND r.status = 'ditahan'
                    FOR UPDATE
                ");
                $stmtRes->execute([$pid]);
                $holdReservations = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($holdReservations)) {
                    $resIds = array_column($holdReservations, 'id');
                    $inRes = implode(',', array_fill(0, count($resIds), '?'));

                    // Ubah status reservasi menjadi kadaluarsa
                    $pdo->prepare("UPDATE reservasi SET status = 'kadaluarsa' WHERE id IN ($inRes)")->execute($resIds);

                    // Kembalikan kuota ke masing-masing unit pelaksana
                    $pdo->prepare("
                        UPDATE unit_pelaksana_periode upp
                        JOIN (
                            SELECT unit_pelaksana_periode_id, COUNT(*) AS jumlah
                            FROM reservasi
                            WHERE id IN ($inRes)
                            GROUP BY unit_pelaksana_periode_id
                        ) r ON upp.id = r.unit_pelaksana_periode_id
                        SET upp.kuota_tersisa = LEAST(upp.kuota_total, upp.kuota_tersisa + r.jumlah)
                    ")->execute($resIds);
                }

                // Catat log aktivitas
                $stmtLog = $pdo->prepare("
                    INSERT INTO log_aktivitas (aksi, entitas_tipe, entitas_id, detail_json, ip_address) 
                    VALUES ('tutup_periode_otomatis', 'periode', ?, ?, '127.0.0.1')
                ");
                $stmtLog->execute([
                    $pid,
                    json_encode([
                        'nama' => $p['nama'],
                        'tanggal_selesai' => $p['tanggal_selesai'],
                        'jam_selesai' => $p['jam_selesai'],
                        'reservasi_dibatalkan' => count($holdReservations)
                    ])
                ]);

                $closedIds[] = $pid;
            });
        }

        return $closedIds;
    }
}

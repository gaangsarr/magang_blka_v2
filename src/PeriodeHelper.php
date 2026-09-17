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
                    SELECT r.id, r.unit_pelaksana_periode_id, IFNULL(r.jurusan_id, m.jurusan_id) AS jurusan_id, upp.tipe_kuota
                    FROM reservasi r
                    JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id
                    LEFT JOIN mahasiswa m ON r.mahasiswa_id = m.id
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

                    $uppCounts = [];
                    $upjCounts = [];
                    foreach ($holdReservations as $hr) {
                        $uppId = (int)$hr['unit_pelaksana_periode_id'];
                        $uppCounts[$uppId] = ($uppCounts[$uppId] ?? 0) + 1;
                        if (($hr['tipe_kuota'] ?? '') === 'breakdown' && !empty($hr['jurusan_id'])) {
                            $jid = (int)$hr['jurusan_id'];
                            $key = "{$uppId}_{$jid}";
                            if (!isset($upjCounts[$key])) {
                                $upjCounts[$key] = ['upp_id' => $uppId, 'jurusan_id' => $jid, 'jumlah' => 0];
                            }
                            $upjCounts[$key]['jumlah'] += 1;
                        }
                    }

                    $stmtUpp = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + :jml) WHERE id = :upp_id");
                    foreach ($uppCounts as $uppId => $jml) {
                        $stmtUpp->execute([':jml' => $jml, ':upp_id' => $uppId]);
                    }

                    if (!empty($upjCounts)) {
                        $stmtUpj = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + :jml) WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                        foreach ($upjCounts as $item) {
                            $stmtUpj->execute([':jml' => $item['jumlah'], ':upp_id' => $item['upp_id'], ':jid' => $item['jurusan_id']]);
                        }
                    }
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

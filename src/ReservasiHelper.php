<?php

declare(strict_types=1);

namespace App;

use PDO;

class ReservasiHelper
{
    /**
     * Mengambil durasi reservasi dalam menit dari .env.
     */
    public static function getReservationMinutes(): int
    {
        $minutes = (int)($_ENV['RESERVATION_MINUTES'] ?? 10);
        return max(1, $minutes);
    }

    /**
     * Membersihkan semua reservasi yang berstatus 'ditahan' dan sudah melewati waktu expired_at.
     * Mengembalikan kuota unit_pelaksana_periode dan kuota unit_periode_jurusan (jika mode breakdown).
     *
     * @param PDO $pdo
     * @return int Jumlah reservasi yang dibersihkan
     */
    public static function cleanupExpired(PDO $pdo): int
    {
        // Kunci baris reservasi yang kadaluarsa
        $stmt = $pdo->prepare("
            SELECT r.id, r.unit_pelaksana_periode_id, IFNULL(r.jurusan_id, m.jurusan_id) AS jurusan_id, upp.tipe_kuota
            FROM reservasi r
            JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id
            LEFT JOIN mahasiswa m ON r.mahasiswa_id = m.id
            WHERE r.status = 'ditahan' AND r.expired_at < NOW()
            FOR UPDATE
        ");
        $stmt->execute();
        $expiredList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredList)) {
            return 0;
        }

        $resIds = array_column($expiredList, 'id');
        $inQuery = implode(',', array_fill(0, count($resIds), '?'));

        // Ubah status menjadi 'kadaluarsa'
        $pdo->prepare("UPDATE reservasi SET status = 'kadaluarsa' WHERE id IN ($inQuery)")->execute($resIds);

        // Kembalikan kuota ke unit_pelaksana_periode
        // Agregasi jumlah per unit_pelaksana_periode_id
        $uppCounts = [];
        // Agregasi jumlah per [upp_id, jurusan_id] untuk mode breakdown
        $upjCounts = [];

        foreach ($expiredList as $exp) {
            $uppId = (int)$exp['unit_pelaksana_periode_id'];
            $uppCounts[$uppId] = ($uppCounts[$uppId] ?? 0) + 1;

            if (($exp['tipe_kuota'] ?? '') === 'breakdown' && !empty($exp['jurusan_id'])) {
                $jid = (int)$exp['jurusan_id'];
                $key = "{$uppId}_{$jid}";
                if (!isset($upjCounts[$key])) {
                    $upjCounts[$key] = [
                        'upp_id'     => $uppId,
                        'jurusan_id' => $jid,
                        'jumlah'     => 0,
                    ];
                }
                $upjCounts[$key]['jumlah'] += 1;
            }
        }

        $stmtUpp = $pdo->prepare("
            UPDATE unit_pelaksana_periode 
            SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + :jml) 
            WHERE id = :upp_id
        ");
        foreach ($uppCounts as $uppId => $jml) {
            $stmtUpp->execute([':jml' => $jml, ':upp_id' => $uppId]);
        }

        if (!empty($upjCounts)) {
            $stmtUpj = $pdo->prepare("
                UPDATE unit_periode_jurusan 
                SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + :jml) 
                WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid
            ");
            foreach ($upjCounts as $item) {
                $stmtUpj->execute([
                    ':jml'    => $item['jumlah'],
                    ':upp_id' => $item['upp_id'],
                    ':jid'    => $item['jurusan_id'],
                ]);
            }
        }

        // Catat log aktivitas
        try {
            $stmtLog = $pdo->prepare("
                INSERT INTO log_aktivitas (aksi, detail_json, ip_address) 
                VALUES ('cleanup_reservasi', ?, '127.0.0.1')
            ");
            $stmtLog->execute([json_encode(['total_dibersihkan' => count($resIds), 'ids' => $resIds])]);
        } catch (\Throwable $e) {
            // Abaikan jika log gagal agar flow utama tidak terhambat
        }

        return count($resIds);
    }

    /**
     * Membatalkan sebuah reservasi oleh mahasiswa.
     * Mengembalikan kuota UPP dan UPJ jika reservasi masih berstatus 'ditahan'.
     * Bersifat idempoten (sukses tanpa error jika sudah dibatalkan atau kadaluarsa sebelumnya).
     *
     * @param PDO $pdo
     * @param int $reservasiId
     * @param int|null $mahasiswaId
     * @return array
     */
    public static function batalkanReservasi(PDO $pdo, int $reservasiId, ?int $mahasiswaId = null): array
    {
        // 1. Cek data reservasi
        $sql = "
            SELECT r.id, r.mahasiswa_id, r.unit_pelaksana_periode_id, 
                   IFNULL(r.jurusan_id, m.jurusan_id) AS jurusan_id, 
                   r.status, r.expired_at, upp.tipe_kuota
            FROM reservasi r
            JOIN unit_pelaksana_periode upp ON r.unit_pelaksana_periode_id = upp.id
            LEFT JOIN mahasiswa m ON r.mahasiswa_id = m.id
            WHERE r.id = :id
            FOR UPDATE
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $reservasiId]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$res) {
            return [
                'ok'    => false,
                'error' => 'Data reservasi tidak ditemukan.'
            ];
        }

        if ($mahasiswaId !== null && (int)$res['mahasiswa_id'] !== $mahasiswaId) {
            return [
                'ok'    => false,
                'error' => 'Reservasi ini bukan milik Anda.'
            ];
        }

        // Jika sudah dikonfirmasi pendaftaran selesai, tidak boleh dibatalkan manual via endpoint ini
        if ($res['status'] === 'dikonfirmasi') {
            return [
                'ok'    => false,
                'error' => 'Pendaftaran untuk reservasi ini sudah dikonfirmasi dan tidak dapat dibatalkan.'
            ];
        }

        // Idempoten: Jika sudah dibatalkan atau kadaluarsa sebelumnya, kembalikan ok tanpa mengubah kuota lagi
        if ($res['status'] === 'dibatalkan' || $res['status'] === 'kadaluarsa') {
            return [
                'ok'           => true,
                'already_done' => true,
                'message'      => 'Reservasi sudah tidak aktif dan kuota telah dikembalikan sebelumnya.'
            ];
        }

        // Status 'ditahan': Ubah status menjadi 'dibatalkan' secara atomik
        $stmtUpd = $pdo->prepare("
            UPDATE reservasi 
            SET status = 'dibatalkan' 
            WHERE id = :id AND status = 'ditahan'
        ");
        $stmtUpd->execute([':id' => $reservasiId]);

        if ($stmtUpd->rowCount() > 0) {
            $uppId = (int)$res['unit_pelaksana_periode_id'];
            $jurusanId = !empty($res['jurusan_id']) ? (int)$res['jurusan_id'] : null;
            $tipeKuota = $res['tipe_kuota'] ?? 'keseluruhan';

            // 1. Kembalikan kuota total UPP (clamped to kuota_total)
            $pdo->prepare("
                UPDATE unit_pelaksana_periode 
                SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + 1) 
                WHERE id = :upp_id
            ")->execute([':upp_id' => $uppId]);

            // 2. Jika tipe_kuota = 'breakdown', kembalikan juga kuota UPJ
            if ($tipeKuota === 'breakdown' && $jurusanId !== null) {
                $pdo->prepare("
                    UPDATE unit_periode_jurusan 
                    SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + 1) 
                    WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid
                ")->execute([':upp_id' => $uppId, ':jid' => $jurusanId]);
            }
        }

        return [
            'ok'      => true,
            'message' => 'Reservasi berhasil dibatalkan dan kuota telah dikembalikan.'
        ];
    }
}

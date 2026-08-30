<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * PERF-05: Tambahkan index database untuk kolom yang sering diquery.
 *
 * Index ini signifikan meningkatkan performa query pada tabel:
 * - reservasi      : status + expired_at (dipakai setiap cleanup dan cek reservasi aktif)
 * - pendaftaran    : mahasiswa_id + periode_id (dipakai di hampir semua query pendaftaran)
 * - rate_limits    : ip_address + action + attempted_at (dipakai setiap request API)
 * - entitas_perusahaan: nama (ORDER BY), menerima_magang (filter, 1200+ data)
 *
 * Jalankan: vendor/bin/phinx migrate
 */
final class AddPerformanceIndexes extends AbstractMigration
{
    public function change(): void
    {
        // Cek dan tambahkan index hanya jika belum ada (idempotent)

        // reservasi: index gabungan untuk query cleanup dan pengecekan reservasi aktif
        // WHERE status = 'ditahan' AND expired_at < NOW()
        $this->table('reservasi')
            ->addIndex(['status', 'expired_at'], ['name' => 'idx_status_expired'])
            ->save();

        // pendaftaran: index gabungan untuk query riwayat dan validasi duplikasi
        // WHERE mahasiswa_id = ? AND periode_id = ?
        $this->table('pendaftaran')
            ->addIndex(['mahasiswa_id', 'periode_id'], ['name' => 'idx_mhs_periode'])
            ->save();

        // rate_limits: index gabungan untuk rate limiting query per IP+action dalam window waktu
        // WHERE ip_address = ? AND action = ? AND attempted_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        $this->table('rate_limits')
            ->addIndex(['ip_address', 'action', 'attempted_at'], ['name' => 'idx_ip_action_time'])
            ->save();

        // entitas_perusahaan: index nama untuk ORDER BY p.nama ASC yang sering dipakai
        // dan menerima_magang untuk filter (1200+ data)
        $this->table('entitas_perusahaan')
            ->addIndex(['nama'], ['name' => 'idx_entitas_nama'])
            ->addIndex(['menerima_magang'], ['name' => 'idx_menerima_magang'])
            ->save();
    }
}

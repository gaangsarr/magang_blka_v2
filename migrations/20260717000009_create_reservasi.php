<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel reservasi — kunci sementara kuota, expire dalam 30 menit.
 * Cron job membersihkan reservasi kadaluarsa tiap 5 menit.
 */
final class CreateReservasi extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('reservasi', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('mahasiswa_id', 'integer', ['signed' => false])
            ->addColumn('unit_pelaksana_periode_id', 'integer', ['signed' => false])
            ->addColumn('status', 'enum', [
                'values'  => ['ditahan', 'dikonfirmasi', 'kadaluarsa', 'dibatalkan'],
                'default' => 'ditahan',
                'comment' => 'ditahan → dikonfirmasi (submit) | kadaluarsa (cron) | dibatalkan (pilih unit lain)',
            ])
            ->addColumn('expired_at', 'datetime', [
                'comment' => 'Waktu kadaluarsa reservasi (NOW + 30 menit)',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            // Index pada expired_at untuk performa query cron cleanup
            ->addIndex(['expired_at'])
            ->addIndex(['mahasiswa_id', 'status'])
            ->create();

        $this->execute('
            ALTER TABLE reservasi
            ADD CONSTRAINT fk_reservasi_mahasiswa
            FOREIGN KEY (mahasiswa_id) REFERENCES mahasiswa(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE reservasi
            ADD CONSTRAINT fk_reservasi_upp
            FOREIGN KEY (unit_pelaksana_periode_id) REFERENCES unit_pelaksana_periode(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
    }
}


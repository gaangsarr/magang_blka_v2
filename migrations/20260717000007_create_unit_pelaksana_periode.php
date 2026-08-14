<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel unit_pelaksana_periode — snapshot kuota per periode.
 * Ini yang dipakai saat mahasiswa mendaftar.
 * Master hierarki (entitas_perusahaan) tidak pernah menyimpan angka kuota.
 */
final class CreateUnitPelaksanaPeriode extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('unit_pelaksana_periode', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('entitas_id', 'integer', [
                'signed'  => false,
                'comment' => 'FK ke entitas_perusahaan.id, tipe harus "unit_pelaksana"',
            ])
            ->addColumn('periode_id', 'integer', [
                'signed'  => false,
                'comment' => 'FK ke periode.id',
            ])
            ->addColumn('kuota_total', 'integer', [
                'default' => 0,
                'comment' => 'Kuota total yang ditetapkan admin untuk periode ini',
            ])
            ->addColumn('kuota_tersisa', 'integer', [
                'default' => 0,
                'comment' => 'Dikurangi saat reservasi, dikembalikan saat reservasi kadaluarsa',
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
                'comment' => 'false = unit tidak tampil di pilihan mahasiswa',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['entitas_id', 'periode_id'], ['unique' => true])
            ->addIndex(['periode_id', 'aktif'])
            ->create();

        $this->execute('
            ALTER TABLE unit_pelaksana_periode
            ADD CONSTRAINT fk_upp_entitas
            FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE unit_pelaksana_periode
            ADD CONSTRAINT fk_upp_periode
            FOREIGN KEY (periode_id) REFERENCES periode(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
    }
}


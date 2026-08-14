<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel unit_peminatan — peminatan yang cocok untuk suatu unit pelaksana.
 * Diinput admin untuk menghitung skor rekomendasi.
 */
final class CreateUnitPeminatan extends AbstractMigration
{
    public function change(): void
    {
        // Tabel junction, PK komposit
        $table = $this->table('unit_peminatan', [
            'id'          => false,
            'primary_key' => ['entitas_id', 'peminatan_id'],
        ]);
        $table
            ->addColumn('entitas_id', 'integer', [
                'signed'  => false,
                'comment' => 'FK ke entitas_perusahaan.id',
            ])
            ->addColumn('peminatan_id', 'integer', [
                'signed'  => false,
                'comment' => 'FK ke peminatan.id',
            ])
            ->create();

        $this->execute('
            ALTER TABLE unit_peminatan
            ADD CONSTRAINT fk_up_entitas
            FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE unit_peminatan
            ADD CONSTRAINT fk_up_peminatan
            FOREIGN KEY (peminatan_id) REFERENCES peminatan(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
    }
}


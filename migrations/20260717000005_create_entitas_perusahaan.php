<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel entitas_perusahaan — master hierarki PLN (self-referential).
 * Hidup lintas periode, jarang berubah.
 * Tipe node: holding → subholding → anak_perusahaan → unit_induk → unit_pelaksana
 */
final class CreateEntitasPerusahaan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('entitas_perusahaan', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('tipe', 'enum', [
                'values'  => ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana'],
                'comment' => 'Level dalam hierarki PLN',
            ])
            ->addColumn('parent_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke entitas_perusahaan.id — null untuk root (Holding)',
            ])
            ->addColumn('nama', 'string', [
                'limit' => 150,
            ])
            ->addColumn('singkatan', 'string', [
                'limit' => 30,
                'null'  => true,
                'comment' => 'mis. "UP3", "ULP", dsb',
            ])
            ->addColumn('alamat', 'text', [
                'null' => true,
            ])
            ->addColumn('latitude', 'decimal', [
                'precision' => 10,
                'scale'     => 7,
                'null'      => true,
                'comment'   => 'Koordinat lokasi kantor unit pelaksana',
            ])
            ->addColumn('longitude', 'decimal', [
                'precision' => 10,
                'scale'     => 7,
                'null'      => true,
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['tipe'])
            ->addIndex(['parent_id'])
            ->addIndex(['aktif'])
            ->create();

        // Self-referential FK: ditambah setelah tabel dibuat
        $this->execute('
            ALTER TABLE entitas_perusahaan
            ADD CONSTRAINT fk_entitas_parent
            FOREIGN KEY (parent_id) REFERENCES entitas_perusahaan(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
    }
}


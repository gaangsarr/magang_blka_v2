<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel relasi prodi dan peminatan per unit dan per periode magang.
 * - unit_jurusan: Template default prodi yang diterima entitas (Master)
 * - unit_periode_jurusan: Prodi yang diterima unit pada snapshot periode tertentu
 * - unit_periode_peminatan: Peminatan yang diterima unit pada snapshot periode tertentu
 */
final class CreateUnitProdiTables extends AbstractMigration
{
    public function up(): void
    {
        // 1. unit_jurusan (Master Template)
        if (!$this->hasTable('unit_jurusan')) {
            $table = $this->table('unit_jurusan', [
                'id'          => false,
                'primary_key' => ['entitas_id', 'jurusan_id'],
            ]);
            $table
                ->addColumn('entitas_id', 'integer', ['signed' => false, 'comment' => 'FK ke entitas_perusahaan.id'])
                ->addColumn('jurusan_id', 'integer', ['signed' => false, 'comment' => 'FK ke jurusan.id'])
                ->create();

            $this->execute('
                ALTER TABLE unit_jurusan
                ADD CONSTRAINT fk_uj_entitas
                FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');
            $this->execute('
                ALTER TABLE unit_jurusan
                ADD CONSTRAINT fk_uj_jurusan
                FOREIGN KEY (jurusan_id) REFERENCES jurusan(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');
        }

        // 2. unit_periode_jurusan (Per Periode Snapshot)
        if (!$this->hasTable('unit_periode_jurusan')) {
            $table = $this->table('unit_periode_jurusan', [
                'id'          => false,
                'primary_key' => ['unit_pelaksana_periode_id', 'jurusan_id'],
            ]);
            $table
                ->addColumn('unit_pelaksana_periode_id', 'integer', ['signed' => false, 'comment' => 'FK ke unit_pelaksana_periode.id'])
                ->addColumn('jurusan_id', 'integer', ['signed' => false, 'comment' => 'FK ke jurusan.id'])
                ->create();

            $this->execute('
                ALTER TABLE unit_periode_jurusan
                ADD CONSTRAINT fk_upj_upp
                FOREIGN KEY (unit_pelaksana_periode_id) REFERENCES unit_pelaksana_periode(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');
            $this->execute('
                ALTER TABLE unit_periode_jurusan
                ADD CONSTRAINT fk_upj_jurusan
                FOREIGN KEY (jurusan_id) REFERENCES jurusan(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');
        }

        // 3. unit_periode_peminatan (Per Periode Snapshot)
        if (!$this->hasTable('unit_periode_peminatan')) {
            $table = $this->table('unit_periode_peminatan', [
                'id'          => false,
                'primary_key' => ['unit_pelaksana_periode_id', 'peminatan_id'],
            ]);
            $table
                ->addColumn('unit_pelaksana_periode_id', 'integer', ['signed' => false, 'comment' => 'FK ke unit_pelaksana_periode.id'])
                ->addColumn('peminatan_id', 'integer', ['signed' => false, 'comment' => 'FK ke peminatan.id'])
                ->create();

            $this->execute('
                ALTER TABLE unit_periode_peminatan
                ADD CONSTRAINT fk_uppem_upp
                FOREIGN KEY (unit_pelaksana_periode_id) REFERENCES unit_pelaksana_periode(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');
            $this->execute('
                ALTER TABLE unit_periode_peminatan
                ADD CONSTRAINT fk_uppem_peminatan
                FOREIGN KEY (peminatan_id) REFERENCES peminatan(id)
                ON DELETE CASCADE ON UPDATE CASCADE
            ');

            // Salin data peminatan existing dari unit_peminatan ke unit_periode_peminatan
            $this->execute('
                INSERT IGNORE INTO unit_periode_peminatan (unit_pelaksana_periode_id, peminatan_id)
                SELECT upp.id, up.peminatan_id
                FROM unit_pelaksana_periode upp
                JOIN unit_peminatan up ON upp.entitas_id = up.entitas_id
            ');
        }
    }

    public function down(): void
    {
        if ($this->hasTable('unit_periode_peminatan')) {
            $this->table('unit_periode_peminatan')->drop()->save();
        }
        if ($this->hasTable('unit_periode_jurusan')) {
            $this->table('unit_periode_jurusan')->drop()->save();
        }
        if ($this->hasTable('unit_jurusan')) {
            $this->table('unit_jurusan')->drop()->save();
        }
    }
}

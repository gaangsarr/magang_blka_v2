<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel peminatan — master daftar minat/bidang keahlian mahasiswa.
 * Dikelola Super Admin. Tidak boleh dihapus jika sudah dipakai.
 */
final class CreatePeminatan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('peminatan', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('nama', 'string', [
                'limit' => 100,
            ])
            ->addColumn('deskripsi', 'string', [
                'limit' => 255,
                'null'  => true,
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['nama'], ['unique' => true])
            ->create();
    }
}


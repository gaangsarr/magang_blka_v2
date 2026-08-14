<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel jurusan — mapping kode 2-digit dari NIM ke nama jurusan.
 * Dikelola Super Admin agar tidak hardcode di kode.
 */
final class CreateJurusan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('jurusan', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('kode', 'char', [
                'limit'   => 2,
                'comment' => '2 digit tengah NIM mahasiswa, mis. "31"',
            ])
            ->addColumn('nama_jurusan', 'string', [
                'limit'   => 100,
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
            ])
            ->addIndex(['kode'], ['unique' => true])
            ->create();
    }
}


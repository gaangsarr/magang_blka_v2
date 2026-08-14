<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPenetapanColumnsToPendaftaran extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pendaftaran');
        
        $table->changeColumn('status', 'string', [
            'limit' => 30,
            'default' => 'diajukan',
            'comment' => 'diajukan, diverifikasi, diterima, dipindahkan, ditolak'
        ]);

        if (!$table->hasColumn('unit_pelaksana_periode_asal_id')) {
            $table->addColumn('unit_pelaksana_periode_asal_id', 'integer', [
                'null' => true,
                'signed' => false,
                'comment' => 'Unit pilihan awal mahasiswa jika dipindahkan paksa'
            ]);
        }

        if (!$table->hasColumn('catatan_admin')) {
            $table->addColumn('catatan_admin', 'text', [
                'null' => true,
                'comment' => 'Alasan/catatan dari admin saat penetapan atau pemindahan unit'
            ]);
        }

        if (!$table->hasColumn('is_dipindahkan')) {
            $table->addColumn('is_dipindahkan', 'boolean', [
                'default' => false,
                'comment' => 'Flag penanda jika mahasiswa dipindahkan paksa oleh admin'
            ]);
        }

        $table->update();
    }
}

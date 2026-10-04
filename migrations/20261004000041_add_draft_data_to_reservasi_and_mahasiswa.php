<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddDraftDataToReservasiAndMahasiswa extends AbstractMigration
{
    public function change(): void
    {
        // 1. Tambah kolom draft_data pada tabel reservasi
        $tableReservasi = $this->table('reservasi');
        if (!$tableReservasi->hasColumn('draft_data')) {
            $tableReservasi->addColumn('draft_data', 'text', [
                'null'    => true,
                'limit'   => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG,
                'comment' => 'Snapshot data formulir pendaftaran saat reservasi dibuat'
            ]);
            $tableReservasi->update();
        }

        // 2. Tambah kolom draft_data pada tabel mahasiswa
        $tableMahasiswa = $this->table('mahasiswa');
        if (!$tableMahasiswa->hasColumn('draft_data')) {
            $tableMahasiswa->addColumn('draft_data', 'text', [
                'null'    => true,
                'limit'   => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG,
                'comment' => 'Draft isian formulir pendaftaran mahasiswa untuk sinkronisasi antar perangkat'
            ]);
            $tableMahasiswa->update();
        }
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEmailTrackingAndCancelSupport extends AbstractMigration
{
    public function change(): void
    {
        // 1. Tambah tracking snapshot pengumuman di tabel pendaftaran
        $tablePendaftaran = $this->table('pendaftaran');
        $tablePendaftaran
            ->addColumn('email_pengumuman_sent_at', 'datetime', ['null' => true, 'after' => 'is_dipindahkan'])
            ->addColumn('email_pengumuman_status_snapshot', 'string', ['limit' => 30, 'null' => true, 'after' => 'email_pengumuman_sent_at'])
            ->addColumn('email_pengumuman_upp_snapshot', 'integer', ['signed' => false, 'null' => true, 'after' => 'email_pengumuman_status_snapshot'])
            ->update();

        // 2. Tambah periode_id dan tipe di email_queue untuk kemudahan filtering & pembatalan antrean
        $tableQueue = $this->table('email_queue');
        $tableQueue
            ->addColumn('periode_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'to_name'])
            ->addColumn('tipe', 'string', ['limit' => 50, 'null' => true, 'after' => 'periode_id'])
            ->addIndex(['periode_id', 'status'])
            ->update();
    }
}

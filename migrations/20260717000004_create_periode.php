<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel periode — siklus pendaftaran magang per tahun ajaran/gelombang.
 */
final class CreatePeriode extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('periode', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('nama', 'string', [
                'limit'   => 50,
                'comment' => 'mis. "Ganjil 2026/2027"',
            ])
            ->addColumn('tanggal_mulai', 'date', [])
            ->addColumn('tanggal_selesai', 'date', [])
            ->addColumn('status', 'enum', [
                'values'  => ['draft', 'dibuka', 'ditutup', 'diarsipkan'],
                'default' => 'draft',
            ])
            ->addColumn('program_1_bulan', 'boolean', [
                'default' => false,
                'comment' => 'Periode ini membuka program magang 1 bulan (sem 5→6)',
            ])
            ->addColumn('program_5_bulan', 'boolean', [
                'default' => false,
                'comment' => 'Periode ini membuka program magang 5 bulan (sem 7, KRS magang)',
            ])
            ->addColumn('dibuat_oleh', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke admin.id yang membuat periode ini',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['status'])
            ->create();

        $this->execute('
            ALTER TABLE periode
            ADD CONSTRAINT fk_periode_admin
            FOREIGN KEY (dibuat_oleh) REFERENCES admin(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
    }
}


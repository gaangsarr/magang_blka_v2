<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePendaftaranHistoriPenolakan extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pendaftaran_histori_penolakan')) {
            $table = $this->table('pendaftaran_histori_penolakan', [
                'id' => false,
                'primary_key' => ['id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci'
            ]);

            $table->addColumn('id', 'integer', [
                'identity' => true,
                'signed' => false
            ])
            ->addColumn('pendaftaran_id', 'integer', [
                'signed' => false,
                'null' => false
            ])
            ->addColumn('mahasiswa_id', 'integer', [
                'signed' => false,
                'null' => false
            ])
            ->addColumn('periode_id', 'integer', [
                'signed' => false,
                'null' => false
            ])
            ->addColumn('unit_pelaksana_periode_id', 'integer', [
                'signed' => false,
                'null' => false
            ])
            ->addColumn('catatan_admin', 'text', [
                'null' => true
            ])
            ->addColumn('ditolak_pada', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP'
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP'
            ])
            ->addIndex(['mahasiswa_id', 'periode_id'], ['name' => 'idx_histori_mhs_periode'])
            ->addIndex(['pendaftaran_id'], ['name' => 'idx_histori_pendaftaran'])
            ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pendaftaran_histori_penolakan')) {
            $this->table('pendaftaran_histori_penolakan')->drop()->save();
        }
    }
}

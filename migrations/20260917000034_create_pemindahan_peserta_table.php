<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePemindahanPesertaTable extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('pemindahan_peserta')) {
            $table = $this->table('pemindahan_peserta', [
                'id' => true,
                'signed' => false,
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci'
            ]);

            $table->addColumn('pendaftaran_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('periode_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('mahasiswa_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('unit_asal_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('unit_tujuan_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('diajukan_oleh_role', 'enum', [
                      'values' => ['admin_blka', 'admin_perusahaan'],
                      'null' => false
                  ])
                  ->addColumn('diajukan_oleh_admin_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('alasan_pemindahan', 'text', ['null' => false])
                  ->addColumn('status_approval', 'enum', [
                      'values' => ['menunggu_approval', 'disetujui', 'ditolak', 'force_blka'],
                      'default' => 'menunggu_approval',
                      'null' => false
                  ])
                  ->addColumn('approval_oleh_admin_id', 'integer', ['signed' => false, 'null' => true])
                  ->addColumn('approval_catatan', 'text', ['null' => true])
                  ->addColumn('approved_at', 'datetime', ['null' => true])
                  ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
                  ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
                  ->addIndex(['unit_tujuan_id', 'status_approval'], ['name' => 'idx_unit_tujuan_status'])
                  ->addIndex(['unit_asal_id'], ['name' => 'idx_unit_asal'])
                  ->addIndex(['pendaftaran_id'], ['name' => 'idx_pendaftaran'])
                  ->addIndex(['periode_id'], ['name' => 'idx_periode'])
                  ->create();
        }
    }

    public function down(): void
    {
        if ($this->hasTable('pemindahan_peserta')) {
            $this->table('pemindahan_peserta')->drop()->save();
        }
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel log_aktivitas — audit trail setiap tindakan admin.
 * Setiap perubahan kuota, status, dan periode tercatat permanen di sini.
 */
final class CreateLogAktivitas extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('log_aktivitas', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('admin_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke admin.id; null jika aksi sistem (mis. cron)',
            ])
            ->addColumn('aksi', 'string', [
                'limit'   => 100,
                'comment' => 'mis. "ubah_kuota", "tutup_periode", "cleanup_reservasi"',
            ])
            ->addColumn('entitas_tipe', 'string', [
                'limit'   => 50,
                'null'    => true,
                'comment' => 'Nama tabel yang terpengaruh, mis. "unit_pelaksana_periode"',
            ])
            ->addColumn('entitas_id', 'integer', [
                'null'    => true,
                'comment' => 'ID record yang terpengaruh',
            ])
            ->addColumn('detail_json', 'json', [
                'null'    => true,
                'comment' => 'Nilai sebelum/sesudah perubahan',
            ])
            ->addColumn('ip_address', 'string', [
                'limit'   => 45,
                'null'    => true,
                'comment' => 'IPv4 atau IPv6 client',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['admin_id'])
            ->addIndex(['aksi'])
            ->addIndex(['created_at'])
            ->create();

        $this->execute('
            ALTER TABLE log_aktivitas
            ADD CONSTRAINT fk_log_admin
            FOREIGN KEY (admin_id) REFERENCES admin(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');
    }
}


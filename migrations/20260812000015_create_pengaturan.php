<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel pengaturan — key-value store untuk konfigurasi sistem.
 * Digunakan untuk menyimpan setting yang bisa diubah admin via UI.
 */
final class CreatePengaturan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pengaturan', [
            'id'          => false,
            'primary_key' => 'kunci',
        ]);
        $table
            ->addColumn('kunci', 'string', [
                'limit' => 100,
            ])
            ->addColumn('nilai', 'text', [
                'null' => true,
            ])
            ->addColumn('deskripsi', 'string', [
                'limit' => 255,
                'null'  => true,
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->create();

        // Seed default settings
        $this->execute("
            INSERT INTO pengaturan (kunci, nilai, deskripsi) VALUES
            ('min_ipk_5bulan', '3.00', 'IPK minimal untuk program magang 5 bulan'),
            ('min_sks_5bulan', '110', 'Jumlah SKS minimal yang sudah ditempuh untuk program magang 5 bulan')
        ");
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration untuk menambahkan kolom public_token pada tabel konfigurasi_surat
 * Token acak yang aman untuk mengakses halaman publik rekapitulasi penempatan tanpa menebak periode_id.
 */
final class AddPublicTokenToKonfigurasiSurat extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('konfigurasi_surat');
        $table
            ->addColumn('public_token', 'string', [
                'limit' => 64,
                'null' => true,
                'after' => 'link_data_publik',
                'comment' => 'Token acak untuk otentikasi akses halaman publik penempatan'
            ])
            ->addIndex(['public_token'], ['unique' => true])
            ->update();
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel pic_narahubung — Master PIC Narahubung Mahasiswa per Unit Besar (Unit Induk/Holding/Anak Perusahaan).
 * Digunakan untuk kontak narahubung mahasiswa saat pengumuman magang.
 * Unit pelaksana (UP3) dan unit layanan (ULP) mewarisi narahubung dari unit induknya.
 */
final class CreatePicNarahubung extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pic_narahubung', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('entitas_id', 'integer', [
                'signed'  => false,
                'comment' => 'FK ke entitas_perusahaan.id (Unit Induk / Holding / Anak Perusahaan)',
            ])
            ->addColumn('area_hcbp', 'string', [
                'limit'   => 100,
                'null'    => true,
                'comment' => 'Area HCBP, mis. "HCBP Area 1", "HCBP Kantor Pusat", dsb.',
            ])
            ->addColumn('nama_pic', 'string', [
                'limit'   => 150,
                'comment' => 'Nama lengkap PIC HC Narahubung Mahasiswa',
            ])
            ->addColumn('no_wa', 'string', [
                'limit'   => 50,
                'comment' => 'Nomor HP / WhatsApp PIC Narahubung',
            ])
            ->addColumn('email', 'string', [
                'limit'   => 150,
                'null'    => true,
                'comment' => 'Alamat email PIC (opsional)',
            ])
            ->addColumn('keterangan', 'text', [
                'null'    => true,
                'comment' => 'Catatan atau informasi tambahan',
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
                'comment' => 'Status aktif narahubung',
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['entitas_id'], ['unique' => true])
            ->addIndex(['area_hcbp'])
            ->addIndex(['aktif'])
            ->create();

        // Foreign Key ke entitas_perusahaan
        $this->execute('
            ALTER TABLE pic_narahubung
            ADD CONSTRAINT fk_pic_entitas
            FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
    }
}

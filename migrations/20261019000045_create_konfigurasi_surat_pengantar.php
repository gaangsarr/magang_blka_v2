<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel konfigurasi_surat_pengantar — menyimpan konfigurasi surat pengantar mahasiswa per unit PLN per periode.
 */
final class CreateKonfigurasiSuratPengantar extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('konfigurasi_surat_pengantar', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('periode_id', 'integer', [
                'signed' => false,
                'comment' => 'FK ke periode.id'
            ])
            ->addColumn('nomor_pokok', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Nomor pokok/urut manual dari admin misal 0001'
            ])
            ->addColumn('format_sufiks', 'string', [
                'limit' => 100,
                'default' => '/Kt/2/D06',
                'comment' => 'Sufiks kode surat misal /Kt/2/D06'
            ])
            ->addColumn('bulan', 'string', [
                'limit' => 10,
                'null' => true,
                'comment' => 'Bulan format 2-digit misal 10'
            ])
            ->addColumn('tahun', 'string', [
                'limit' => 10,
                'null' => true,
                'comment' => 'Tahun 4-digit misal 2026'
            ])
            ->addColumn('tanggal_surat', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Contoh: Jakarta, 19 Oktober 2026'
            ])
            ->addColumn('tahun_akademik', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Contoh: 2026/2027'
            ])
            ->addColumn('perihal', 'text', [
                'null' => true,
                'comment' => 'Perihal surat pengantar'
            ])
            ->addColumn('jabatan_penandatangan', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Contoh: Wakil Rektor III Bidang Kemahasiswaan'
            ])
            ->addColumn('nama_penandatangan', 'string', [
                'limit' => 150,
                'null' => true,
                'comment' => 'Contoh: Ir. Purnomo, S.T., M.T.'
            ])
            ->addColumn('nip_penandatangan', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'NIP/NIDN pejabat penandatangan'
            ])
            ->addColumn('ttd_image_path', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Path file gambar tanda tangan pejabat'
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['periode_id'], ['unique' => true])
            ->create();

        $this->execute('
            ALTER TABLE konfigurasi_surat_pengantar
            ADD CONSTRAINT fk_konfigurasi_surat_pengantar_periode
            FOREIGN KEY (periode_id) REFERENCES periode(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
    }
}

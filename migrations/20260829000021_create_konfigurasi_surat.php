<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel konfigurasi_surat — menyimpan setting kustomisasi surat penempatan magang per periode.
 */
final class CreateKonfigurasiSurat extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('konfigurasi_surat', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('periode_id', 'integer', [
                'signed' => false,
                'comment' => 'FK ke periode.id'
            ])
            ->addColumn('nomor_surat_template', 'string', [
                'limit' => 150,
                'default' => '{nomor}/Srt/1/D0/08/2026',
                'comment' => 'Format nomor surat, {nomor} akan di-increment otomatis per unit'
            ])
            ->addColumn('nomor_surat_start', 'integer', [
                'default' => 1,
                'signed' => false,
                'comment' => 'Angka awal untuk {nomor}'
            ])
            ->addColumn('tanggal_surat', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Contoh: Jakarta, 29 Agustus 2026'
            ])
            ->addColumn('perihal', 'text', [
                'null' => true,
                'comment' => 'Perihal surat'
            ])
            ->addColumn('tahun_akademik', 'string', [
                'limit' => 50,
                'null' => true,
                'comment' => 'Contoh: 2026/2027'
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
            ->addColumn('tembusan', 'text', [
                'null' => true,
                'comment' => 'Daftar tembusan baris per baris'
            ])
            ->addColumn('link_data_publik', 'string', [
                'limit' => 255,
                'null' => true,
                'comment' => 'Tautan data publik penempatan'
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
            ALTER TABLE konfigurasi_surat
            ADD CONSTRAINT fk_konfigurasi_surat_periode
            FOREIGN KEY (periode_id) REFERENCES periode(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
    }
}

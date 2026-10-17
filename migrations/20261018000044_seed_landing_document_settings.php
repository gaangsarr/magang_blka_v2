<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedLandingDocumentSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO pengaturan (kunci, nilai, deskripsi) VALUES
            ('doc_pedoman_tipe', 'file', 'Sumber berkas Pedoman Magang: file atau url'),
            ('doc_pedoman_file', '/assets/docs/pedoman-magang.pdf', 'Path file internal Pedoman Magang'),
            ('doc_pedoman_url', '', 'URL tautan eksternal Pedoman Magang'),
            ('doc_pedoman_judul', 'Buku Pedoman Magang', 'Judul kartu Pedoman Magang di beranda'),
            ('doc_pedoman_meta', 'Panduan Resmi', 'Label meta kartu Pedoman Magang di beranda'),
            ('doc_pedoman_deskripsi', 'Panduan lengkap alur pendaftaran, persyaratan berkas, hak dan kewajiban mahasiswa, tata tertib, serta kriteria evaluasi program magang bersama PT PLN (Persero).', 'Deskripsi kartu Pedoman Magang di beranda'),
            ('doc_pedoman_badge', 'PDF', 'Badge format file Pedoman Magang'),
            ('doc_pedoman_btn_text', 'Buka Dokumen PDF', 'Teks tombol unduh Pedoman Magang'),

            ('doc_logbook_tipe', 'file', 'Sumber berkas Log Book: file atau url'),
            ('doc_logbook_file', '/assets/docs/logbook.docx', 'Path file internal Log Book'),
            ('doc_logbook_url', '', 'URL tautan eksternal Log Book'),
            ('doc_logbook_judul', 'Buku Catatan / Log Book', 'Judul kartu Log Book di beranda'),
            ('doc_logbook_meta', 'Format Laporan', 'Label meta kartu Log Book di beranda'),
            ('doc_logbook_deskripsi', 'Format resmi pencatatan aktivitas harian, rencana kerja mingguan, lembar bimbingan mentor lapangan, dan rekapitulasi kehadiran selama masa penempatan.', 'Deskripsi kartu Log Book di beranda'),
            ('doc_logbook_badge', 'Word', 'Badge format file Log Book'),
            ('doc_logbook_btn_text', 'Buka Dokumen', 'Teks tombol unduh Log Book')
            ON DUPLICATE KEY UPDATE deskripsi = VALUES(deskripsi);
        ");
    }

    public function down(): void
    {
        $this->execute("
            DELETE FROM pengaturan WHERE kunci IN (
                'doc_pedoman_tipe',
                'doc_pedoman_file',
                'doc_pedoman_url',
                'doc_pedoman_judul',
                'doc_pedoman_meta',
                'doc_pedoman_deskripsi',
                'doc_pedoman_badge',
                'doc_pedoman_btn_text',
                'doc_logbook_tipe',
                'doc_logbook_file',
                'doc_logbook_url',
                'doc_logbook_judul',
                'doc_logbook_meta',
                'doc_logbook_deskripsi',
                'doc_logbook_badge',
                'doc_logbook_btn_text'
            );
        ");
    }
}

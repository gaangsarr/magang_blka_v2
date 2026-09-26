<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedEmailNotificationSettings extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            INSERT INTO pengaturan (kunci, nilai, deskripsi) VALUES
            ('email_notifikasi_penolakan', '1', 'Kirim email notifikasi otomatis saat berkas/pendaftaran ditolak'),
            ('email_notifikasi_pengumuman', '0', 'Kirim email notifikasi massal saat pengumuman kelulusan/penempatan dibuka'),
            ('email_notifikasi_submit', '0', 'Kirim email bukti pendaftaran saat mahasiswa submit berkas')
            ON DUPLICATE KEY UPDATE deskripsi = VALUES(deskripsi);
        ");
    }

    public function down(): void
    {
        $this->execute("
            DELETE FROM pengaturan WHERE kunci IN (
                'email_notifikasi_penolakan',
                'email_notifikasi_pengumuman',
                'email_notifikasi_submit'
            );
        ");
    }
}

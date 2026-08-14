<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel pendaftaran_peminatan — peminatan yang dipilih mahasiswa saat mendaftar.
 * Maksimal MAX_PEMINATAN pilihan (default: 3), dijaga di level aplikasi.
 */
final class CreatePendaftaranPeminatan extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pendaftaran_peminatan', [
            'id'          => false,
            'primary_key' => ['pendaftaran_id', 'peminatan_id'],
        ]);
        $table
            ->addColumn('pendaftaran_id', 'integer', ['signed' => false])
            ->addColumn('peminatan_id', 'integer', ['signed' => false])
            ->create();

        $this->execute('
            ALTER TABLE pendaftaran_peminatan
            ADD CONSTRAINT fk_pp_pendaftaran
            FOREIGN KEY (pendaftaran_id) REFERENCES pendaftaran(id)
            ON DELETE CASCADE ON UPDATE CASCADE
        ');
        $this->execute('
            ALTER TABLE pendaftaran_peminatan
            ADD CONSTRAINT fk_pp_peminatan
            FOREIGN KEY (peminatan_id) REFERENCES peminatan(id)
            ON DELETE RESTRICT ON UPDATE CASCADE
        ');
    }
}


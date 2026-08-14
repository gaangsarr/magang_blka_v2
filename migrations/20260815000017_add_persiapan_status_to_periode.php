<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tambahkan status 'persiapan' ke ENUM kolom status pada tabel periode.
 *
 * Enum sebelumnya: 'draft', 'dibuka', 'ditutup', 'diarsipkan'
 * Enum sesudah:    'draft', 'persiapan', 'dibuka', 'ditutup', 'diarsipkan'
 *
 * Status 'persiapan' digunakan sebagai tahap di mana admin sudah bisa
 * set up unit dan kuota, tapi mahasiswa belum bisa mendaftar.
 */
final class AddPersiapanStatusToPeriode extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            ALTER TABLE periode 
            MODIFY COLUMN status ENUM('draft', 'persiapan', 'dibuka', 'ditutup', 'diarsipkan') 
            NOT NULL DEFAULT 'draft'
        ");
    }

    public function down(): void
    {
        // Revert: ubah semua yang 'persiapan' kembali ke 'draft' dulu
        $this->execute("UPDATE periode SET status = 'draft' WHERE status = 'persiapan'");
        $this->execute("
            ALTER TABLE periode 
            MODIFY COLUMN status ENUM('draft', 'dibuka', 'ditutup', 'diarsipkan') 
            NOT NULL DEFAULT 'draft'
        ");
    }
}

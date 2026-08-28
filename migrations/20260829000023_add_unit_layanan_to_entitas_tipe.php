<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Update enum kolom `tipe` pada `entitas_perusahaan` untuk mendukung 6 tingkatan hierarki PLN:
 * holding, subholding, anak_perusahaan, unit_induk, unit_pelaksana, unit_layanan.
 * Serta merapikan data yang sebelumnya tercatat tertukar (UID, UP3, ULP).
 */
final class AddUnitLayananToEntitasTipe extends AbstractMigration
{
    public function up(): void
    {
        // 1. Ubah ENUM tipe pada tabel entitas_perusahaan
        $this->execute("
            ALTER TABLE entitas_perusahaan 
            MODIFY COLUMN tipe ENUM(
                'holding', 
                'subholding', 
                'anak_perusahaan', 
                'unit_induk', 
                'unit_pelaksana', 
                'unit_layanan'
            ) NOT NULL COMMENT 'Level dalam hierarki PLN'
        ");

        // 2. Rapikan data entitas eksisting yang sebelumnya tergeser:
        // - ULP yang tercatat sebagai unit_pelaksana diubah menjadi unit_layanan
        $this->execute("
            UPDATE entitas_perusahaan 
            SET tipe = 'unit_layanan' 
            WHERE tipe = 'unit_pelaksana' 
              AND (nama LIKE '%ULP%' OR singkatan LIKE '%ULP%')
        ");

        // - UP3 yang tercatat sebagai unit_induk diubah menjadi unit_pelaksana
        $this->execute("
            UPDATE entitas_perusahaan 
            SET tipe = 'unit_pelaksana' 
            WHERE tipe = 'unit_induk' 
              AND (nama LIKE '%UP3%' OR singkatan LIKE '%UP3%' OR nama LIKE '%UPDL%' OR singkatan LIKE '%UPDL%')
        ");

        // - UID yang tercatat sebagai anak_perusahaan diubah menjadi unit_induk
        $this->execute("
            UPDATE entitas_perusahaan 
            SET tipe = 'unit_induk' 
            WHERE tipe = 'anak_perusahaan' 
              AND (nama LIKE '%Unit Induk%' OR nama LIKE '%UID%' OR singkatan LIKE '%UID%' OR nama LIKE '%UIW%' OR singkatan LIKE '%UIW%')
        ");
    }

    public function down(): void
    {
        // Kembalikan tipe unit_layanan ke unit_pelaksana jika rollback
        $this->execute("
            UPDATE entitas_perusahaan 
            SET tipe = 'unit_pelaksana' 
            WHERE tipe = 'unit_layanan'
        ");

        $this->execute("
            ALTER TABLE entitas_perusahaan 
            MODIFY COLUMN tipe ENUM(
                'holding', 
                'subholding', 
                'anak_perusahaan', 
                'unit_induk', 
                'unit_pelaksana'
            ) NOT NULL COMMENT 'Level dalam hierarki PLN'
        ");
    }
}

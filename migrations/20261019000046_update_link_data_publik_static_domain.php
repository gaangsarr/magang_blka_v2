<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migration untuk memperbarui domain tautan data publik penempatan ke domain statis resmi: https://intern.itpln.ac.id
 */
final class UpdateLinkDataPublikStaticDomain extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("
            UPDATE konfigurasi_surat 
            SET link_data_publik = CONCAT('https://intern.itpln.ac.id/penempatan.html?token=', public_token) 
            WHERE public_token IS NOT NULL AND public_token != ''
        ");
    }

    public function down(): void
    {
        // Revert not required as previous dynamic values depend on local environment
    }
}

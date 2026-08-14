<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Menambahkan kolom `menerima_magang` ke tabel `entitas_perusahaan`.
 * 
 * Kolom ini menentukan apakah sebuah kantor/entitas (holding, subholding, anak perusahaan,
 * unit induk, atau unit pelaksana) membuka kesempatan magang dan dapat dialokasikan kuota
 * pada periode magang aktif.
 */
final class AddMenerimaMagangToEntitas extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('entitas_perusahaan');
        if (!$table->hasColumn('menerima_magang')) {
            $table->addColumn('menerima_magang', 'boolean', [
                'default' => false,
                'null'    => false,
                'after'   => 'aktif',
                'comment' => '1 jika entitas ini menerima pendaftaran magang dan dapat diset kuota',
            ])
            ->addIndex(['menerima_magang'])
            ->update();
        }

        // Set unit_pelaksana yang sudah ada agar default menerima_magang = 1
        $this->execute("UPDATE entitas_perusahaan SET menerima_magang = 1 WHERE tipe = 'unit_pelaksana'");
    }

    public function down(): void
    {
        $table = $this->table('entitas_perusahaan');
        if ($table->hasColumn('menerima_magang')) {
            $table->removeColumn('menerima_magang')->update();
        }
    }
}

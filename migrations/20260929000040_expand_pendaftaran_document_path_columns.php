<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class ExpandPendaftaranDocumentPathColumns extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pendaftaran');

        if ($table->hasColumn('transkrip_path')) {
            $table->changeColumn('transkrip_path', 'text', [
                'null' => true,
                'comment' => 'Tautan berkas publik (GDrive/OneDrive) atau path file Transkrip Nilai'
            ]);
        }

        if ($table->hasColumn('cv_path')) {
            $table->changeColumn('cv_path', 'text', [
                'null' => true,
                'comment' => 'Tautan berkas publik (GDrive/OneDrive) atau path file CV'
            ]);
        }

        if ($table->hasColumn('porto_path')) {
            $table->changeColumn('porto_path', 'text', [
                'null' => true,
                'comment' => 'Tautan berkas publik (GDrive/OneDrive) atau path file Portofolio'
            ]);
        }

        $table->update();
    }
}

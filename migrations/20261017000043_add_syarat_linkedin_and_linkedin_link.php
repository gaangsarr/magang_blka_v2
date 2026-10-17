<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSyaratLinkedinAndLinkedinLink extends AbstractMigration
{
    public function change(): void
    {
        // 1. Tambah kolom syarat_linkedin pada tabel periode (default aktif / wajib = 1)
        $tablePeriode = $this->table('periode');

        if (!$tablePeriode->hasColumn('syarat_linkedin')) {
            $tablePeriode->addColumn('syarat_linkedin', 'boolean', [
                'default' => 1,
                'null'    => false,
                'after'   => 'syarat_porto',
                'comment' => 'Status syarat profil LinkedIn (1: aktif/wajib, 0: tidak)'
            ]);
            $tablePeriode->update();
        }

        // 2. Tambah kolom linkedin_link pada tabel pendaftaran
        $tablePendaftaran = $this->table('pendaftaran');

        if (!$tablePendaftaran->hasColumn('linkedin_link')) {
            $tablePendaftaran->addColumn('linkedin_link', 'text', [
                'null'    => true,
                'after'   => 'porto_uploaded_at',
                'comment' => 'Tautan profil LinkedIn mahasiswa'
            ]);
            $tablePendaftaran->update();
        }
    }
}

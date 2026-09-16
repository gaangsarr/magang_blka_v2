<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSyaratDokumenAndCvPorto extends AbstractMigration
{
    public function change(): void
    {
        // 1. Tambah kolom syarat dokumen pada tabel periode
        $tablePeriode = $this->table('periode');

        if (!$tablePeriode->hasColumn('syarat_transkrip')) {
            $tablePeriode->addColumn('syarat_transkrip', 'boolean', [
                'default' => 1,
                'null'    => false,
                'after'   => 'program_5_bulan',
                'comment' => 'Status syarat Transkrip Nilai (1: aktif/wajib, 0: tidak)'
            ]);
        }

        if (!$tablePeriode->hasColumn('syarat_cv')) {
            $tablePeriode->addColumn('syarat_cv', 'boolean', [
                'default' => 0,
                'null'    => false,
                'after'   => 'syarat_transkrip',
                'comment' => 'Status syarat Curriculum Vitae / CV (1: aktif/wajib, 0: tidak)'
            ]);
        }

        if (!$tablePeriode->hasColumn('syarat_porto')) {
            $tablePeriode->addColumn('syarat_porto', 'boolean', [
                'default' => 0,
                'null'    => false,
                'after'   => 'syarat_cv',
                'comment' => 'Status syarat Portofolio (1: aktif/wajib, 0: tidak)'
            ]);
        }

        $tablePeriode->update();

        // 2. Tambah kolom CV dan Portofolio pada tabel pendaftaran
        $tablePendaftaran = $this->table('pendaftaran');

        if (!$tablePendaftaran->hasColumn('cv_path')) {
            $tablePendaftaran->addColumn('cv_path', 'string', [
                'limit'   => 255,
                'null'    => true,
                'after'   => 'transkrip_uploaded_at',
                'comment' => 'Path file PDF CV di storage lokal'
            ]);
        }

        if (!$tablePendaftaran->hasColumn('cv_uploaded_at')) {
            $tablePendaftaran->addColumn('cv_uploaded_at', 'datetime', [
                'null'    => true,
                'after'   => 'cv_path',
                'comment' => 'Waktu upload CV'
            ]);
        }

        if (!$tablePendaftaran->hasColumn('porto_path')) {
            $tablePendaftaran->addColumn('porto_path', 'string', [
                'limit'   => 255,
                'null'    => true,
                'after'   => 'cv_uploaded_at',
                'comment' => 'Path file PDF Portofolio di storage lokal'
            ]);
        }

        if (!$tablePendaftaran->hasColumn('porto_uploaded_at')) {
            $tablePendaftaran->addColumn('porto_uploaded_at', 'datetime', [
                'null'    => true,
                'after'   => 'porto_path',
                'comment' => 'Waktu upload Portofolio'
            ]);
        }

        $tablePendaftaran->update();
    }
}

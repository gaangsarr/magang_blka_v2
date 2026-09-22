<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddProgram3And4Bulan extends AbstractMigration
{
    public function up(): void
    {
        // 1. Tambah kolom program_3_bulan dan program_4_bulan ke tabel periode
        $tablePeriode = $this->table('periode');
        if (!$tablePeriode->hasColumn('program_3_bulan')) {
            $tablePeriode->addColumn('program_3_bulan', 'boolean', [
                'default' => 0,
                'null'    => true,
                'after'   => 'program_1_bulan',
                'comment' => 'Periode ini membuka program magang 3 bulan',
            ]);
        }
        if (!$tablePeriode->hasColumn('program_4_bulan')) {
            $tablePeriode->addColumn('program_4_bulan', 'boolean', [
                'default' => 0,
                'null'    => true,
                'after'   => 'program_3_bulan',
                'comment' => 'Periode ini membuka program magang 4 bulan',
            ]);
        }
        $tablePeriode->update();

        // 2. Modifikasi enum kolom program pada tabel pendaftaran
        $this->execute("ALTER TABLE `pendaftaran` MODIFY COLUMN `program` ENUM('1_bulan','3_bulan','4_bulan','5_bulan') DEFAULT NULL COMMENT 'Program magang yang dipilih mahasiswa'");
    }

    public function down(): void
    {
        $tablePeriode = $this->table('periode');
        if ($tablePeriode->hasColumn('program_4_bulan')) {
            $tablePeriode->removeColumn('program_4_bulan');
        }
        if ($tablePeriode->hasColumn('program_3_bulan')) {
            $tablePeriode->removeColumn('program_3_bulan');
        }
        $tablePeriode->update();

        $this->execute("ALTER TABLE `pendaftaran` MODIFY COLUMN `program` ENUM('1_bulan','5_bulan') DEFAULT NULL COMMENT 'Program magang yang dipilih mahasiswa'");
    }
}

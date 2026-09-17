<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePeminatanJurusanAndQuotaBreakdown extends AbstractMigration
{
    public function up(): void
    {
        // 1. Buat tabel peminatan_jurusan (Many-to-Many antara peminatan dan jurusan)
        if (!$this->hasTable('peminatan_jurusan')) {
            $table = $this->table('peminatan_jurusan', [
                'id' => false,
                'primary_key' => ['peminatan_id', 'jurusan_id'],
                'engine' => 'InnoDB',
                'collation' => 'utf8mb4_unicode_ci'
            ]);
            $table->addColumn('peminatan_id', 'integer', ['signed' => false, 'null' => false])
                  ->addColumn('jurusan_id', 'integer', ['signed' => false, 'null' => false])
                  ->addForeignKey('peminatan_id', 'peminatan', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                  ->addForeignKey('jurusan_id', 'jurusan', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                  ->create();
        }

        // 2. Tambahkan kolom tipe_kuota pada unit_pelaksana_periode
        $uppTable = $this->table('unit_pelaksana_periode');
        if (!$uppTable->hasColumn('tipe_kuota')) {
            $uppTable->addColumn('tipe_kuota', 'enum', [
                'values' => ['keseluruhan', 'breakdown'],
                'default' => 'keseluruhan',
                'null' => false,
                'after' => 'periode_id'
            ])->update();
        }

        // 3. Tambahkan kolom kuota_total dan kuota_tersisa pada unit_periode_jurusan
        $upjTable = $this->table('unit_periode_jurusan');
        if (!$upjTable->hasColumn('kuota_total')) {
            $upjTable->addColumn('kuota_total', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'jurusan_id'
            ])->update();
        }
        if (!$upjTable->hasColumn('kuota_tersisa')) {
            $upjTable->addColumn('kuota_tersisa', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'kuota_total'
            ])->update();
        }

        // 4. Tambahkan kolom jurusan_id pada reservasi (untuk audit & rollback kuota breakdown)
        $resTable = $this->table('reservasi');
        if (!$resTable->hasColumn('jurusan_id')) {
            $resTable->addColumn('jurusan_id', 'integer', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'unit_pelaksana_periode_id'
            ])->update();
        }

        // 5. Seeder Relasi Awal untuk 7 Master Peminatan Eksisting
        // Mapping peminatan_id => array jurusan_id
        // Berdasarkan data master jurusan ITPLN:
        // 1: S1 Teknik Elektro, 2: S1 Teknik Mesin, 3: S1 Teknik Tenaga Listrik, 4: S1 Teknik Sistem Energi,
        // 5: S1 Teknik Sipil, 6: S1 Teknik Geografi, 7: S1 Teknik Lingkungan, 8: S1 Teknik Informatika,
        // 9: S1 Sistem Informasi, 10: S1 Data Sains, 11: S1 Bisnis Energi, 12: S1 Teknik Industri,
        // 13: D3 Teknologi Listrik, 14: D3 Teknik Mesin.
        $initialMappings = [
            1 => [1, 3, 13],       // Teknik Elektro & Ketenagalistrikan -> Elektro, Tenaga Listrik, D3 Listrik
            2 => [8, 9, 10],       // Teknologi Informasi & Sistem -> Informatika, Sistem Informasi, Data Sains
            3 => [11],             // Keuangan & Akuntansi -> Bisnis Energi
            4 => [11, 12],         // Manajemen & Administrasi -> Bisnis Energi, Teknik Industri
            5 => [2, 14],          // Teknik Mesin & Pemeliharaan -> Mesin, D3 Mesin
            6 => [4],              // Energi Terbarukan -> Sistem Energi
            7 => [5, 7],           // K3 & Lingkungan Hidup -> Sipil, Lingkungan
        ];

        foreach ($initialMappings as $peminatanId => $jurusanIds) {
            foreach ($jurusanIds as $jurusanId) {
                $this->execute("
                    INSERT IGNORE INTO peminatan_jurusan (peminatan_id, jurusan_id) 
                    VALUES ($peminatanId, $jurusanId)
                ");
            }
        }
    }

    public function down(): void
    {
        if ($this->hasTable('peminatan_jurusan')) {
            $this->table('peminatan_jurusan')->drop()->save();
        }

        $uppTable = $this->table('unit_pelaksana_periode');
        if ($uppTable->hasColumn('tipe_kuota')) {
            $uppTable->removeColumn('tipe_kuota')->update();
        }

        $upjTable = $this->table('unit_periode_jurusan');
        if ($upjTable->hasColumn('kuota_total')) {
            $upjTable->removeColumn('kuota_total')->update();
        }
        if ($upjTable->hasColumn('kuota_tersisa')) {
            $upjTable->removeColumn('kuota_tersisa')->update();
        }

        $resTable = $this->table('reservasi');
        if ($resTable->hasColumn('jurusan_id')) {
            $resTable->removeColumn('jurusan_id')->update();
        }
    }
}

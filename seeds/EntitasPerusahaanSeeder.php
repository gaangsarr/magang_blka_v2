<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seeder Lengkap Master Data Entitas Perusahaan PLN (1.283 Entitas)
 * Mencakup seluruh 6 tingkatan:
 * 1. Holding (6 entitas)
 * 2. Subholding (4 entitas)
 * 3. Anak Perusahaan (6 entitas)
 * 4. Unit Induk (41 entitas)
 * 5. Unit Pelaksana (343 entitas)
 * 6. Unit Layanan (883 entitas)
 */
class EntitasPerusahaanSeeder extends AbstractSeed
{
    public function run(): void
    {
        $sqlFile = __DIR__ . '/entitas_perusahaan.sql';
        if (file_exists($sqlFile)) {
            echo "  -> Mengimpor master data entitas PLN dari entitas_perusahaan.sql (1.283 entitas)...\n";
            $this->execute("SET FOREIGN_KEY_CHECKS = 0;");
            $sql = file_get_contents($sqlFile);
            $this->execute($sql);
            $this->execute("SET FOREIGN_KEY_CHECKS = 1;");
            echo "  ✓ Berhasil mengimpor 1.283 entitas perusahaan PLN.\n";
        } else {
            echo "  ⚠ File entitas_perusahaan.sql tidak ditemukan.\n";
        }
    }
}

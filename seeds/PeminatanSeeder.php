<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed master data peminatan magang ITPLN x PLN Persero.
 */
class PeminatanSeeder extends AbstractSeed
{
    public function run(): void
    {
        $items = [
            [
                'nama'      => 'Teknik Elektro & Ketenagalistrikan',
                'deskripsi' => 'Distribusi, transmisi, pembangkitan listrik',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'Teknologi Informasi & Sistem',
                'deskripsi' => 'Software, jaringan, infrastruktur IT',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'Keuangan & Akuntansi',
                'deskripsi' => 'Keuangan perusahaan, laporan keuangan',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'Manajemen & Administrasi',
                'deskripsi' => 'Tata kelola, SDM, administrasi umum',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'Teknik Mesin & Pemeliharaan',
                'deskripsi' => 'Perawatan mesin, turbin, instalasi mekanikal',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'Energi Terbarukan',
                'deskripsi' => 'Solar, PLTS, green energy',
                'aktif'     => 1,
            ],
            [
                'nama'      => 'K3 & Lingkungan Hidup',
                'deskripsi' => 'Keselamatan kerja, lingkungan',
                'aktif'     => 1,
            ],
        ];

        $table = $this->table('peminatan');

        foreach ($items as $row) {
            $existing = $this->query("SELECT id FROM peminatan WHERE nama = '" . addslashes($row['nama']) . "' LIMIT 1")->fetch();
            if (!$existing) {
                $table->insert($row)->saveData();
            }
        }

        echo "  ✓ Seeded " . count($items) . " peminatan magang\n";
    }
}

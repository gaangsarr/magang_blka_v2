<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed hierarki PLN dan peminatan contoh.
 * Berisi struktur nyata yang bisa langsung dipakai untuk demo/testing.
 *
 * Hierarki:
 *   PLN Holding
 *     └─ PLN Icon Plus (Subholding)
 *     └─ PLN Nusantara Power (Subholding)
 *   PT PLN (Persero) Unit Induk Distribusi Jawa Barat (Anak Perusahaan)
 *     └─ UP3 Bekasi (Unit Induk)
 *         └─ ULP Bekasi Kota (Unit Pelaksana)  ← yang bisa dipilih mahasiswa
 *         └─ ULP Bekasi Utara (Unit Pelaksana)
 *     └─ UP3 Bogor (Unit Induk)
 *         └─ ULP Bogor Kota (Unit Pelaksana)
 */
class DemoDataSeeder extends AbstractSeed
{
    public function getDependencies(): array
    {
        return ['JurusanSeeder', 'AdminSeeder', 'PeminatanSeeder'];
    }

    public function run(): void
    {
        $this->seedHierarkiPLN();
        echo "  ✓ Demo data selesai.\n";
    }

    private function seedPeminatan(): void
    {
        $items = [
            ['nama' => 'Teknik Elektro & Ketenagalistrikan', 'deskripsi' => 'Distribusi, transmisi, pembangkitan listrik', 'aktif' => 1],
            ['nama' => 'Teknologi Informasi & Sistem', 'deskripsi' => 'Software, jaringan, infrastruktur IT', 'aktif' => 1],
            ['nama' => 'Keuangan & Akuntansi', 'deskripsi' => 'Keuangan perusahaan, laporan keuangan', 'aktif' => 1],
            ['nama' => 'Manajemen & Administrasi', 'deskripsi' => 'Tata kelola, SDM, administrasi umum', 'aktif' => 1],
            ['nama' => 'Teknik Mesin & Pemeliharaan', 'deskripsi' => 'Perawatan mesin, turbin, instalasi mekanikal', 'aktif' => 1],
            ['nama' => 'Energi Terbarukan', 'deskripsi' => 'Solar, PLTS, green energy', 'aktif' => 1],
            ['nama' => 'K3 & Lingkungan Hidup', 'deskripsi' => 'Keselamatan kerja, lingkungan', 'aktif' => 1],
        ];

        $table = $this->table('peminatan');
        foreach ($items as $row) {
            $existing = $this->query("SELECT id FROM peminatan WHERE nama = '" . addslashes($row['nama']) . "' LIMIT 1")->fetch();
            if (!$existing) {
                $table->insert($row)->saveData();
            }
        }
        echo "  ✓ Seeded " . count($items) . " peminatan\n";
    }

    private function seedHierarkiPLN(): void
    {
        // Cek sudah ada atau belum
        $existing = $this->query("SELECT id FROM entitas_perusahaan WHERE nama = 'PT PLN (Persero)' LIMIT 1")->fetch();
        if ($existing) {
            echo "  ⚠ Hierarki PLN sudah ada, skip.\n";
            return;
        }

        $ep = $this->table('entitas_perusahaan');

        // 1. Holding
        $ep->insert([
            'tipe'       => 'holding',
            'parent_id'  => null,
            'nama'       => 'PT PLN (Persero)',
            'singkatan'  => 'PLN',
            'alamat'     => 'Jl. Trunojoyo Blok M-I No. 135, Kebayoran Baru, Jakarta Selatan',
            'latitude'   => -6.2433,
            'longitude'  => 106.8019,
            'aktif'      => 1,
        ])->saveData();
        $holdingId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 2. Anak Perusahaan / Unit Induk (langsung di bawah holding untuk demo)
        $ep->insert([
            'tipe'      => 'anak_perusahaan',
            'parent_id' => $holdingId,
            'nama'      => 'PLN Unit Induk Distribusi Jawa Barat',
            'singkatan' => 'UID Jabar',
            'alamat'    => 'Jl. Asia Afrika No.63, Bandung',
            'latitude'  => -6.9217,
            'longitude' => 107.6073,
            'aktif'     => 1,
        ])->saveData();
        $uidJabarId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 3. Unit Induk (UP3)
        $ep->insert([
            'tipe'      => 'unit_induk',
            'parent_id' => $uidJabarId,
            'nama'      => 'PLN UP3 Bekasi',
            'singkatan' => 'UP3 Bekasi',
            'alamat'    => 'Jl. Veteran No. 9, Bekasi',
            'latitude'  => -6.2349,
            'longitude' => 106.9896,
            'aktif'     => 1,
        ])->saveData();
        $up3BekId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        $ep->insert([
            'tipe'      => 'unit_induk',
            'parent_id' => $uidJabarId,
            'nama'      => 'PLN UP3 Bogor',
            'singkatan' => 'UP3 Bogor',
            'alamat'    => 'Jl. Otto Iskandar Dinata No. 14, Bogor',
            'latitude'  => -6.5944,
            'longitude' => 106.7892,
            'aktif'     => 1,
        ])->saveData();
        $up3BogId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        $ep->insert([
            'tipe'      => 'unit_induk',
            'parent_id' => $uidJabarId,
            'nama'      => 'PLN UP3 Depok',
            'singkatan' => 'UP3 Depok',
            'alamat'    => 'Jl. Arif Rahman Hakim No. 31, Depok',
            'latitude'  => -6.4025,
            'longitude' => 106.7942,
            'aktif'     => 1,
        ])->saveData();
        $up3DepId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 4. Unit Pelaksana (ULP) — yang bisa dipilih mahasiswa
        $unitPelaksana = [
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Bekasi Kota', 'singkatan' => 'ULP Bekasi Kota',
                'alamat' => 'Jl. Hasanudin No. 1, Bekasi',
                'latitude' => -6.2389, 'longitude' => 107.0001,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Bekasi Utara', 'singkatan' => 'ULP Bekasi Utara',
                'alamat' => 'Jl. Raya Kelapa Gading, Bekasi Utara',
                'latitude' => -6.1701, 'longitude' => 106.9965,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Cikarang', 'singkatan' => 'ULP Cikarang',
                'alamat' => 'Jl. Industri Selatan, Cikarang',
                'latitude' => -6.3550, 'longitude' => 107.1519,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BogId,
                'nama' => 'PLN ULP Bogor Kota', 'singkatan' => 'ULP Bogor Kota',
                'alamat' => 'Jl. Suryakencana No. 87, Bogor',
                'latitude' => -6.5966, 'longitude' => 106.7999,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BogId,
                'nama' => 'PLN ULP Cibinong', 'singkatan' => 'ULP Cibinong',
                'alamat' => 'Jl. Raya Bogor KM 44, Cibinong',
                'latitude' => -6.4878, 'longitude' => 106.8545,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3DepId,
                'nama' => 'PLN ULP Depok', 'singkatan' => 'ULP Depok',
                'alamat' => 'Jl. Margonda Raya No. 200, Depok',
                'latitude' => -6.3862, 'longitude' => 106.8219,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3DepId,
                'nama' => 'PLN ULP Cinere', 'singkatan' => 'ULP Cinere',
                'alamat' => 'Jl. Cinere Raya No. 5, Depok',
                'latitude' => -6.3618, 'longitude' => 106.7819,
            ],
        ];

        foreach ($unitPelaksana as $row) {
            $ep->insert(array_merge($row, ['aktif' => 1]))->saveData();
        }

        echo "  ✓ Seeded hierarki PLN: 1 holding, 1 anak perusahaan, 3 unit induk, " . count($unitPelaksana) . " unit pelaksana\n";

        // 5. Seed unit_peminatan — mapping realistis per unit pelaksana
        $this->seedUnitPeminatan();
    }

    /**
     * Assign peminatan ke setiap unit pelaksana dengan pola realistis.
     * Peminatan di-lookup by nama dari tabel peminatan (sudah di-seed oleh PeminatanSeeder).
     */
    private function seedUnitPeminatan(): void
    {
        // Cek sudah ada atau belum
        $existing = $this->query("SELECT COUNT(*) as cnt FROM unit_peminatan")->fetch();
        if ($existing && (int)$existing['cnt'] > 0) {
            echo "  ⚠ Unit peminatan sudah ada, skip.\n";
            return;
        }

        // Mapping: nama unit pelaksana → list nama peminatan
        $mapping = [
            'PLN ULP Bekasi Kota'  => ['Teknik Elektro & Ketenagalistrikan', 'Teknologi Informasi & Sistem'],
            'PLN ULP Bekasi Utara' => ['Teknik Elektro & Ketenagalistrikan', 'Teknik Mesin & Pemeliharaan'],
            'PLN ULP Cikarang'     => ['Teknik Elektro & Ketenagalistrikan', 'Energi Terbarukan', 'K3 & Lingkungan Hidup'],
            'PLN ULP Bogor Kota'   => ['Keuangan & Akuntansi', 'Manajemen & Administrasi'],
            'PLN ULP Cibinong'     => ['Teknologi Informasi & Sistem', 'Manajemen & Administrasi'],
            'PLN ULP Depok'        => ['Teknik Elektro & Ketenagalistrikan', 'Teknologi Informasi & Sistem', 'Keuangan & Akuntansi'],
            'PLN ULP Cinere'       => ['Manajemen & Administrasi', 'K3 & Lingkungan Hidup'],
        ];

        $up = $this->table('unit_peminatan');
        $inserted = 0;

        foreach ($mapping as $namaUnit => $listPeminatan) {
            // Lookup entitas_id
            $unitRow = $this->query(
                "SELECT id FROM entitas_perusahaan WHERE nama = '" . addslashes($namaUnit) . "' LIMIT 1"
            )->fetch();

            if (!$unitRow) {
                echo "  ⚠ Unit '$namaUnit' tidak ditemukan, skip peminatan.\n";
                continue;
            }

            $entitasId = (int)$unitRow['id'];

            foreach ($listPeminatan as $namaPem) {
                $pemRow = $this->query(
                    "SELECT id FROM peminatan WHERE nama = '" . addslashes($namaPem) . "' LIMIT 1"
                )->fetch();

                if (!$pemRow) {
                    echo "  ⚠ Peminatan '$namaPem' tidak ditemukan, skip.\n";
                    continue;
                }

                $up->insert([
                    'entitas_id'    => $entitasId,
                    'peminatan_id'  => (int)$pemRow['id'],
                ])->saveData();
                $inserted++;
            }
        }

        echo "  ✓ Seeded $inserted relasi unit_peminatan\n";
    }
}

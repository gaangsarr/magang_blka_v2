<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed hierarki PLN dan peminatan contoh.
 * Berisi struktur nyata yang bisa langsung dipakai untuk demo/testing.
 *
 * Hierarki:
 *   PLN Holding (Menerima Magang)
 *     └─ PLN Nusantara Power (Subholding)
 *     └─ PLN Unit Induk Distribusi Jawa Barat (Anak Perusahaan)
 *         └─ UP3 Bekasi (Unit Induk)
 *             └─ ULP Bekasi Kota (Unit Pelaksana - Menerima Magang)
 *             └─ ULP Bekasi Utara (Unit Pelaksana - Menerima Magang)
 *             └─ ULP Cikarang (Unit Pelaksana - Menerima Magang)
 *         └─ UP3 Bogor (Unit Induk)
 *             └─ ULP Bogor Kota (Unit Pelaksana - Menerima Magang)
 *             └─ ULP Cibinong (Unit Pelaksana - Menerima Magang)
 *         └─ UP3 Depok (Unit Induk)
 *             └─ ULP Depok (Unit Pelaksana - Menerima Magang)
 *             └─ ULP Cinere (Unit Pelaksana - Menerima Magang)
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

    private function seedHierarkiPLN(): void
    {
        // Cek sudah ada atau belum
        $existing = $this->query("SELECT id FROM entitas_perusahaan WHERE nama = 'PT PLN (Persero)' LIMIT 1")->fetch();
        if ($existing) {
            echo "  ⚠ Hierarki PLN sudah ada, skip.\n";
            return;
        }

        $ep = $this->table('entitas_perusahaan');

        // 1. Holding (Kantor Pusat — Menerima Magang)
        $ep->insert([
            'tipe'             => 'holding',
            'parent_id'        => null,
            'nama'             => 'PT PLN (Persero)',
            'singkatan'        => 'PLN Pusat',
            'alamat'           => 'Jl. Trunojoyo Blok M-I No. 135, Kebayoran Baru, Jakarta Selatan',
            'latitude'         => -6.2433,
            'longitude'        => 106.8019,
            'aktif'            => 1,
            'menerima_magang'  => 1,
        ])->saveData();
        $holdingId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 2. Subholding
        $ep->insert([
            'tipe'             => 'subholding',
            'parent_id'        => $holdingId,
            'nama'             => 'PT PLN Nusantara Power',
            'singkatan'        => 'PLN NP',
            'alamat'           => 'Jl. Ketintang Baru No. 11, Surabaya',
            'latitude'         => -7.3184,
            'longitude'        => 112.7291,
            'aktif'            => 1,
            'menerima_magang'  => 0,
        ])->saveData();

        // 3. Anak Perusahaan
        $ep->insert([
            'tipe'             => 'anak_perusahaan',
            'parent_id'        => $holdingId,
            'nama'             => 'PLN Unit Induk Distribusi Jawa Barat',
            'singkatan'        => 'UID Jabar',
            'alamat'           => 'Jl. Asia Afrika No.63, Bandung',
            'latitude'         => -6.9217,
            'longitude'        => 107.6073,
            'aktif'            => 1,
            'menerima_magang'  => 0,
        ])->saveData();
        $uidJabarId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 4. Unit Induk (UP3)
        $ep->insert([
            'tipe'             => 'unit_induk',
            'parent_id'        => $uidJabarId,
            'nama'             => 'PLN UP3 Bekasi',
            'singkatan'        => 'UP3 Bekasi',
            'alamat'           => 'Jl. Veteran No. 9, Bekasi',
            'latitude'         => -6.2349,
            'longitude'        => 106.9896,
            'aktif'            => 1,
            'menerima_magang'  => 0,
        ])->saveData();
        $up3BekId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        $ep->insert([
            'tipe'             => 'unit_induk',
            'parent_id'        => $uidJabarId,
            'nama'             => 'PLN UP3 Bogor',
            'singkatan'        => 'UP3 Bogor',
            'alamat'           => 'Jl. Otto Iskandar Dinata No. 14, Bogor',
            'latitude'         => -6.5944,
            'longitude'        => 106.7892,
            'aktif'            => 1,
            'menerima_magang'  => 0,
        ])->saveData();
        $up3BogId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        $ep->insert([
            'tipe'             => 'unit_induk',
            'parent_id'        => $uidJabarId,
            'nama'             => 'PLN UP3 Depok',
            'singkatan'        => 'UP3 Depok',
            'alamat'           => 'Jl. Arif Rahman Hakim No. 31, Depok',
            'latitude'         => -6.4025,
            'longitude'        => 106.7942,
            'aktif'            => 1,
            'menerima_magang'  => 0,
        ])->saveData();
        $up3DepId = $this->query('SELECT LAST_INSERT_ID() as id')->fetch()['id'];

        // 5. Unit Pelaksana (ULP) — menerima magang
        $unitPelaksana = [
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Bekasi Kota', 'singkatan' => 'ULP Bekasi Kota',
                'alamat' => 'Jl. Hasanudin No. 1, Bekasi',
                'latitude' => -6.2389, 'longitude' => 107.0001,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Bekasi Utara', 'singkatan' => 'ULP Bekasi Utara',
                'alamat' => 'Jl. Raya Kelapa Gading, Bekasi Utara',
                'latitude' => -6.1701, 'longitude' => 106.9965,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BekId,
                'nama' => 'PLN ULP Cikarang', 'singkatan' => 'ULP Cikarang',
                'alamat' => 'Jl. Industri Selatan, Cikarang',
                'latitude' => -6.3550, 'longitude' => 107.1519,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BogId,
                'nama' => 'PLN ULP Bogor Kota', 'singkatan' => 'ULP Bogor Kota',
                'alamat' => 'Jl. Suryakencana No. 87, Bogor',
                'latitude' => -6.5966, 'longitude' => 106.7999,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3BogId,
                'nama' => 'PLN ULP Cibinong', 'singkatan' => 'ULP Cibinong',
                'alamat' => 'Jl. Raya Bogor KM 44, Cibinong',
                'latitude' => -6.4878, 'longitude' => 106.8545,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3DepId,
                'nama' => 'PLN ULP Depok', 'singkatan' => 'ULP Depok',
                'alamat' => 'Jl. Margonda Raya No. 200, Depok',
                'latitude' => -6.3862, 'longitude' => 106.8219,
                'menerima_magang' => 1,
            ],
            [
                'tipe' => 'unit_pelaksana', 'parent_id' => $up3DepId,
                'nama' => 'PLN ULP Cinere', 'singkatan' => 'ULP Cinere',
                'alamat' => 'Jl. Cinere Raya No. 5, Depok',
                'latitude' => -6.3618, 'longitude' => 106.7819,
                'menerima_magang' => 1,
            ],
        ];

        foreach ($unitPelaksana as $row) {
            $ep->insert(array_merge($row, ['aktif' => 1]))->saveData();
        }

        echo "  ✓ Seeded hierarki PLN: 1 holding, 1 subholding, 1 anak perusahaan, 3 unit induk, " . count($unitPelaksana) . " unit pelaksana\n";

        // 6. Seed unit_peminatan
        $this->seedUnitPeminatan();
    }

    /**
     * Assign peminatan ke setiap entitas yang menerima magang.
     */
    private function seedUnitPeminatan(): void
    {
        // Mapping: nama entitas → list nama peminatan
        $mapping = [
            'PT PLN (Persero)'     => ['Teknik Elektro & Ketenagalistrikan', 'Teknologi Informasi & Sistem', 'Keuangan & Akuntansi', 'Manajemen & Administrasi'],
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
            $unitRow = $this->query(
                "SELECT id FROM entitas_perusahaan WHERE nama = '" . addslashes($namaUnit) . "' LIMIT 1"
            )->fetch();

            if (!$unitRow) {
                continue;
            }

            $entitasId = (int)$unitRow['id'];

            foreach ($listPeminatan as $namaPem) {
                $pemRow = $this->query(
                    "SELECT id FROM peminatan WHERE nama = '" . addslashes($namaPem) . "' LIMIT 1"
                )->fetch();

                if (!$pemRow) {
                    continue;
                }

                $peminatanId = (int)$pemRow['id'];

                // Check if already exists
                $exist = $this->query(
                    "SELECT id FROM unit_peminatan WHERE entitas_id = $entitasId AND peminatan_id = $peminatanId LIMIT 1"
                )->fetch();

                if (!$exist) {
                    $up->insert([
                        'entitas_id'   => $entitasId,
                        'peminatan_id' => $peminatanId,
                    ])->saveData();
                    $inserted++;
                }
            }
        }

        echo "  ✓ Seeded $inserted relasi unit_peminatan\n";
    }
}

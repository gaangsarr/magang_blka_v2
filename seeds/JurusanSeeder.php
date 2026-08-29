<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed master data kode dan nama jurusan ITPLN (14 Program Studi).
 * Digunakan untuk identifikasi jurusan otomatis berdasarkan 2 digit tengah NIM mahasiswa.
 */
class JurusanSeeder extends AbstractSeed
{
    public function run(): void
    {
        $sqlFile = __DIR__ . '/jurusan.sql';
        if (file_exists($sqlFile)) {
            echo "  -> Mengimpor master data jurusan dari jurusan.sql...\n";
            $this->execute("SET FOREIGN_KEY_CHECKS = 0;");
            $this->execute(file_get_contents($sqlFile));
            $this->execute("SET FOREIGN_KEY_CHECKS = 1;");
            echo "  ✓ Berhasil mengimpor 14 jurusan ITPLN.\n";
            return;
        }

        $data = [
            ['id' => 1,  'kode' => '11', 'nama_jurusan' => 'Teknik Elektro',        'aktif' => 1],
            ['id' => 2,  'kode' => '12', 'nama_jurusan' => 'Teknik Mesin',          'aktif' => 1],
            ['id' => 3,  'kode' => '14', 'nama_jurusan' => 'Teknik Tenaga Listrik',  'aktif' => 1],
            ['id' => 4,  'kode' => '15', 'nama_jurusan' => 'Teknik Sistem Energi',   'aktif' => 1],
            ['id' => 5,  'kode' => '21', 'nama_jurusan' => 'Teknik Sipil',          'aktif' => 1],
            ['id' => 6,  'kode' => '22', 'nama_jurusan' => 'Teknik Geografi',       'aktif' => 1],
            ['id' => 7,  'kode' => '23', 'nama_jurusan' => 'Teknik Lingkungan',     'aktif' => 1],
            ['id' => 8,  'kode' => '31', 'nama_jurusan' => 'Teknik Informatika',    'aktif' => 1],
            ['id' => 9,  'kode' => '32', 'nama_jurusan' => 'Sistem Informasi',      'aktif' => 1],
            ['id' => 10, 'kode' => '33', 'nama_jurusan' => 'Data Sains',            'aktif' => 1],
            ['id' => 11, 'kode' => '41', 'nama_jurusan' => 'Bisnis Energi',         'aktif' => 1],
            ['id' => 12, 'kode' => '42', 'nama_jurusan' => 'Teknik Industri',       'aktif' => 1],
            ['id' => 13, 'kode' => '71', 'nama_jurusan' => 'Teknologi Listrik',     'aktif' => 1],
            ['id' => 14, 'kode' => '72', 'nama_jurusan' => 'Teknik Mesin',          'aktif' => 1],
        ];

        $table = $this->table('jurusan');

        foreach ($data as $row) {
            $existing = $this->query("SELECT id FROM jurusan WHERE kode = '{$row['kode']}' LIMIT 1")->fetch();
            if (!$existing) {
                $table->insert($row)->saveData();
            }
        }

        echo "  ✓ Seeded " . count($data) . " jurusan ITPLN\n";
    }
}

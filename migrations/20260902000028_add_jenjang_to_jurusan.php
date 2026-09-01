<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddJenjangToJurusan extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('jurusan');
        if (!$table->hasColumn('jenjang')) {
            $table->addColumn('jenjang', 'string', [
                'limit'   => 10,
                'default' => 'S1',
                'null'    => false,
                'after'   => 'kode',
                'comment' => 'Jenjang pendidikan (D3, D4, S1, S2, S3)',
            ])->update();
        }

        // Set semua default S1 kecuali kode 71 (Teknologi Listrik) dan 72 (Teknik Mesin) adalah D3
        $this->execute("UPDATE jurusan SET jenjang = 'S1' WHERE kode NOT IN ('71', '72')");
        $this->execute("UPDATE jurusan SET jenjang = 'D3' WHERE kode IN ('71', '72')");
    }

    public function down(): void
    {
        $table = $this->table('jurusan');
        if ($table->hasColumn('jenjang')) {
            $table->removeColumn('jenjang')->update();
        }
    }
}

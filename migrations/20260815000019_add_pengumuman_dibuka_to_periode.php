<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Menambahkan kolom `pengumuman_dibuka` pada tabel `periode`.
 * Default: 0 (Pengumuman belum dipublikasikan, mahasiswa hanya melihat status pending / dalam proses).
 */
final class AddPengumumanDibukaToPeriode extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('periode');
        $table->addColumn('pengumuman_dibuka', 'boolean', [
            'default' => false,
            'after'   => 'status',
            'comment' => '1 = Pengumuman hasil penetapan sudah dipublikasikan & dapat dilihat mahasiswa',
        ])
        ->update();
    }
}

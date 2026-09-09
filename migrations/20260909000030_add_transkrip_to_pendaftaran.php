<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTranskripToPendaftaran extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('pendaftaran');

        if (!$table->hasColumn('transkrip_path')) {
            $table->addColumn('transkrip_path', 'string', [
                'limit' => 255,
                'null' => true,
                'after' => 'jumlah_sks',
                'comment' => 'Path file PDF transkrip nilai di storage lokal'
            ]);
        }

        if (!$table->hasColumn('transkrip_uploaded_at')) {
            $table->addColumn('transkrip_uploaded_at', 'datetime', [
                'null' => true,
                'after' => 'transkrip_path',
                'comment' => 'Waktu upload transkrip nilai'
            ]);
        }

        $table->update();
    }
}

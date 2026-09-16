<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddJamSelesaiToPeriode extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('periode');
        $table
            ->addColumn('jam_selesai', 'time', [
                'default' => '23:59:00',
                'null'    => false,
                'after'   => 'tanggal_selesai',
                'comment' => 'Jam penutupan otomatis periode pada tanggal selesai (WIB)',
            ])
            ->update();
    }
}

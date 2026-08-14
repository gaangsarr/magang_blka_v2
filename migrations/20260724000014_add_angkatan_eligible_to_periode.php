<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAngkatanEligibleToPeriode extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('periode');
        $table
            ->addColumn('angkatan_eligible', 'string', [
                'limit'   => 255,
                'null'    => true,
                'comment' => 'Daftar angkatan yang diizinkan mendaftar (comma separated, misal: 2023,2024)',
            ])
            ->update();
    }
}

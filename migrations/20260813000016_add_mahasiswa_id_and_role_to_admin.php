<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMahasiswaIdAndRoleToAdmin extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('admin');

        if (!$table->hasColumn('mahasiswa_id')) {
            $table->addColumn('mahasiswa_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke mahasiswa.id untuk admin yang dibuat dari akun mahasiswa terdaftar',
            ]);
        }

        // Change password_hash to allow NULL for SSO-only admins
        $table->changeColumn('password_hash', 'string', [
            'limit'   => 255,
            'null'    => true,
            'comment' => 'bcrypt hash dari password (null untuk SSO admin)',
        ]);

        $table->update();

        // Ensure all existing admin rows have super_admin role if only 1 exists
        $this->execute("UPDATE admin SET role = 'super_admin' WHERE role = 'admin_blka' OR role = 'admin'");
    }
}

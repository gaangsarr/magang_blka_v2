<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel admin — akun staf BLKA, login via email+password (bcrypt).
 * Tidak pakai Firebase Auth.
 */
final class CreateAdmin extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('admin', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('email', 'string', [
                'limit' => 150,
            ])
            ->addColumn('nama', 'string', [
                'limit' => 150,
            ])
            ->addColumn('password_hash', 'string', [
                'limit'   => 255,
                'comment' => 'bcrypt hash dari password',
            ])
            ->addColumn('role', 'enum', [
                'values'  => ['admin_blka', 'super_admin'],
                'default' => 'admin_blka',
            ])
            ->addColumn('aktif', 'boolean', [
                'default' => true,
            ])
            ->addColumn('created_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->addColumn('updated_at', 'datetime', [
                'default' => 'CURRENT_TIMESTAMP',
                'update'  => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['email'], ['unique' => true])
            ->create();
    }
}


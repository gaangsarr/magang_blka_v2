<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAdminProdiSupport extends AbstractMigration
{
    public function up(): void
    {
        $adminTable = $this->table('admin');

        if (!$adminTable->hasColumn('jurusan_id')) {
            $adminTable->addColumn('jurusan_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'after'   => 'entitas_id',
                'comment' => 'FK ke jurusan.id untuk role admin_prodi',
            ]);
        }

        // Perluas enum role untuk mendukung admin_prodi
        $adminTable->changeColumn('role', 'enum', [
            'values'  => ['super_admin', 'admin_blka', 'admin_perusahaan', 'admin_prodi'],
            'default' => 'admin_blka',
        ]);

        $adminTable->update();

        // Tambahkan FK jurusan_id ke jurusan jika belum ada
        $checkFk = $this->fetchRow("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'admin' 
              AND CONSTRAINT_NAME = 'fk_admin_jurusan'
        ");

        if (!$checkFk) {
            $this->execute('
                ALTER TABLE admin
                ADD CONSTRAINT fk_admin_jurusan
                FOREIGN KEY (jurusan_id) REFERENCES jurusan(id)
                ON DELETE SET NULL ON UPDATE CASCADE
            ');
        }
    }

    public function down(): void
    {
        $checkFk = $this->fetchRow("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'admin' 
              AND CONSTRAINT_NAME = 'fk_admin_jurusan'
        ");

        if ($checkFk) {
            $this->execute('ALTER TABLE admin DROP FOREIGN KEY fk_admin_jurusan');
        }

        $adminTable = $this->table('admin');
        if ($adminTable->hasColumn('jurusan_id')) {
            $adminTable->removeColumn('jurusan_id');
        }

        $adminTable->changeColumn('role', 'enum', [
            'values'  => ['super_admin', 'admin_blka', 'admin_perusahaan'],
            'default' => 'admin_blka',
        ]);

        $adminTable->update();
    }
}

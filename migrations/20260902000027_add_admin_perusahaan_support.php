<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAdminPerusahaanSupport extends AbstractMigration
{
    public function up(): void
    {
        // 1. Update tabel admin
        $adminTable = $this->table('admin');

        if (!$adminTable->hasColumn('entitas_id')) {
            $adminTable->addColumn('entitas_id', 'integer', [
                'null'    => true,
                'signed'  => false,
                'comment' => 'FK ke entitas_perusahaan.id untuk role admin_perusahaan',
            ]);
        }

        if (!$adminTable->hasColumn('username')) {
            $adminTable->addColumn('username', 'string', [
                'limit'   => 100,
                'null'    => true,
                'comment' => 'Username login unik untuk admin perusahaan atau admin khusus',
            ]);
        }

        if (!$adminTable->hasColumn('force_password_change')) {
            $adminTable->addColumn('force_password_change', 'boolean', [
                'default' => false,
                'comment' => '1 = Wajib ganti password pada login pertama kali / setelah reset',
            ]);
        }

        // Ubah email agar nullable untuk akun perusahaan berbasis username
        $adminTable->changeColumn('email', 'string', [
            'limit' => 150,
            'null'  => true,
        ]);

        // Perluas enum role untuk mendukung admin_perusahaan
        $adminTable->changeColumn('role', 'enum', [
            'values'  => ['super_admin', 'admin_blka', 'admin_perusahaan'],
            'default' => 'admin_blka',
        ]);

        $adminTable->update();

        // Tambahkan unique index untuk username jika belum ada
        if (!$adminTable->hasIndex(['username'])) {
            $adminTable->addIndex(['username'], ['unique' => true])->update();
        }

        // Tambahkan FK entitas_id ke entitas_perusahaan
        $this->execute('
            ALTER TABLE admin
            ADD CONSTRAINT fk_admin_entitas
            FOREIGN KEY (entitas_id) REFERENCES entitas_perusahaan(id)
            ON DELETE SET NULL ON UPDATE CASCADE
        ');

        // 2. Update tabel entitas_perusahaan untuk profil PIC
        $entitasTable = $this->table('entitas_perusahaan');

        if (!$entitasTable->hasColumn('pic_nama')) {
            $entitasTable->addColumn('pic_nama', 'string', ['limit' => 150, 'null' => true]);
        }
        if (!$entitasTable->hasColumn('pic_jabatan')) {
            $entitasTable->addColumn('pic_jabatan', 'string', ['limit' => 100, 'null' => true]);
        }
        if (!$entitasTable->hasColumn('pic_kontak')) {
            $entitasTable->addColumn('pic_kontak', 'string', ['limit' => 50, 'null' => true, 'comment' => 'No WhatsApp / HP PIC']);
        }
        if (!$entitasTable->hasColumn('pic_email')) {
            $entitasTable->addColumn('pic_email', 'string', ['limit' => 150, 'null' => true]);
        }

        $entitasTable->update();
    }

    public function down(): void
    {
        // Drop FK
        $this->execute('ALTER TABLE admin DROP FOREIGN KEY fk_admin_entitas');

        $adminTable = $this->table('admin');
        if ($adminTable->hasColumn('entitas_id')) {
            $adminTable->removeColumn('entitas_id');
        }
        if ($adminTable->hasColumn('username')) {
            $adminTable->removeColumn('username');
        }
        if ($adminTable->hasColumn('force_password_change')) {
            $adminTable->removeColumn('force_password_change');
        }
        $adminTable->update();

        $entitasTable = $this->table('entitas_perusahaan');
        if ($entitasTable->hasColumn('pic_nama')) {
            $entitasTable->removeColumn('pic_nama');
        }
        if ($entitasTable->hasColumn('pic_jabatan')) {
            $entitasTable->removeColumn('pic_jabatan');
        }
        if ($entitasTable->hasColumn('pic_kontak')) {
            $entitasTable->removeColumn('pic_kontak');
        }
        if ($entitasTable->hasColumn('pic_email')) {
            $entitasTable->removeColumn('pic_email');
        }
        $entitasTable->update();
    }
}

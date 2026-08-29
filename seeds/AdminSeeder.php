<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed akun Admin & Super Admin BLKA.
 *
 * Password default Super Admin: Admin@BLKA2026 (Wajib diganti di production)
 * Generate hash baru: php -r "echo password_hash('password_baru', PASSWORD_BCRYPT, ['cost'=>12]);"
 */
class AdminSeeder extends AbstractSeed
{
    public function run(): void
    {
        $admins = [
            [
                'email'         => 'admin@blka.itpln.ac.id',
                'nama'          => 'Super Admin BLKA',
                'password_hash' => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12]),
                'role'          => 'super_admin',
                'aktif'         => 1,
            ],
            [
                'email'         => 'anjasgangsar12@gmail.com',
                'nama'          => 'Super Admin Dev',
                'password_hash' => password_hash('gangsardev', PASSWORD_BCRYPT, ['cost' => 12]),
                'role'          => 'super_admin',
                'aktif'         => 1,
            ],
        ];

        $table = $this->table('admin');

        foreach ($admins as $admin) {
            $email = $admin['email'];
            $existing = $this->query("SELECT id FROM admin WHERE email = '$email' LIMIT 1")->fetch();

            if (!$existing) {
                $table->insert($admin)->saveData();
                echo "  ✓ Admin dibuat: {$admin['nama']} ({$email})\n";
            } else {
                echo "  ⚠ Admin '{$email}' sudah ada, skip.\n";
            }
        }
    }
}

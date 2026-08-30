<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * Seed akun Admin & Super Admin BLKA.
 *
 * ⚠️  PRODUCTION WARNING:
 *   - Password default 'admin123' WAJIB diganti sebelum deploy ke production!
 *   - Buat hash baru: php -r "echo password_hash('PasswordKuat!2026', PASSWORD_BCRYPT, ['cost'=>12]);"
 *   - JANGAN re-run seeder di production — buat script admin terpisah yang baca dari ENV.
 *
 * Password default Super Admin: admin123 (Wajib diganti di production!)
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
            // ✅ Dev email (anjasgangsar12@gmail.com) sudah dihapus — tidak boleh ada di production.
        ];

        $adapter = $this->getAdapter();
        $table   = $this->table('admin');

        foreach ($admins as $admin) {
            $email = $admin['email'];

            // ✅ BLOCKER-04: Gunakan quoteValue() bukan string concatenation untuk mencegah SQL Injection
            $existing = $adapter->fetchRow(
                'SELECT id FROM admin WHERE email = ' . $adapter->quoteValue($email) . ' LIMIT 1'
            );

            if (!$existing) {
                $table->insert($admin)->saveData();
                echo "  ✓ Admin dibuat: {$admin['nama']} ({$email})\n";
            } else {
                echo "  ⚠ Admin '{$email}' sudah ada, skip.\n";
            }
        }
    }
}

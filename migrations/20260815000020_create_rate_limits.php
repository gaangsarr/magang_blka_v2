<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tabel rate_limits untuk throttling berbasis IP.
 */
final class CreateRateLimits extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('rate_limits', ['id' => true, 'primary_key' => 'id']);
        $table
            ->addColumn('ip_address', 'string', ['limit' => 45, 'comment' => 'IPv4 atau IPv6 client'])
            ->addColumn('action', 'string', ['limit' => 50, 'comment' => 'Nama aksi yang dibatasi'])
            ->addColumn('attempted_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['ip_address', 'action', 'attempted_at'])
            ->create();
    }
}

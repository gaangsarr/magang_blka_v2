<?php

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',

        'development' => [
            'adapter'   => 'mysql',
            'host'      => $_ENV['DB_HOST'],
            'name'      => $_ENV['DB_NAME'],
            'user'      => !empty($_ENV['DB_MIGRATION_USER']) ? $_ENV['DB_MIGRATION_USER'] : ($_ENV['DB_USER'] ?? 'root'),
            'pass'      => !empty($_ENV['DB_MIGRATION_USER']) ? ($_ENV['DB_MIGRATION_PASS'] ?? '') : ($_ENV['DB_PASS'] ?? ''),
            'port'      => $_ENV['DB_PORT'] ?? 3306,
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],

        'production' => [
            'adapter'   => 'mysql',
            'host'      => $_ENV['DB_HOST'],
            'name'      => $_ENV['DB_NAME'],
            'user'      => !empty($_ENV['DB_MIGRATION_USER']) ? $_ENV['DB_MIGRATION_USER'] : ($_ENV['DB_USER'] ?? 'root'),
            'pass'      => !empty($_ENV['DB_MIGRATION_USER']) ? ($_ENV['DB_MIGRATION_PASS'] ?? '') : ($_ENV['DB_PASS'] ?? ''),
            'port'      => $_ENV['DB_PORT'] ?? 3306,
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ],
    ],
    'version_order' => 'creation',
];

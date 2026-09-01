<?php

namespace App;

use PDO;
use PDOException;

/**
 * PDO Singleton untuk koneksi database.
 * Gunakan Database::getInstance() di mana pun dibutuhkan.
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host    = $_ENV['DB_HOST']   ?? '127.0.0.1';
            $port    = $_ENV['DB_PORT']   ?? 3306;
            $name    = $_ENV['DB_NAME']   ?? '';
            $user    = $_ENV['DB_USER']   ?? '';
            $pass    = $_ENV['DB_PASS']   ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_FOUND_ROWS   => true,
                ]);

                // Pastikan timezone PHP dan MySQL selaras di Asia/Jakarta (WIB)
                $tz = $_ENV['APP_TIMEZONE'] ?? 'Asia/Jakarta';
                date_default_timezone_set($tz);
                self::$instance->exec("SET time_zone = '+07:00'");
            } catch (PDOException $e) {
                // Lempar exception agar caller bisa menangkap dan return JSON yang proper
                // Jangan die() langsung — itu akan menghasilkan body kosong jika ob_end_clean() sudah dipanggil
                $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
                $msg   = $debug ? $e->getMessage() : 'Database connection failed.';
                throw new \RuntimeException($msg, 500, $e);
            }
        }

        return self::$instance;
    }

    /**
     * Helper: jalankan query dalam transaksi.
     * Otomatis rollback jika ada exception.
     *
     * @param callable $callback function(PDO $pdo): mixed
     * @return mixed hasil return dari callback
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::getInstance();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

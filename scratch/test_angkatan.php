<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

try {
    $pdo = Database::getInstance();

    // 1. Check periode schema
    $stmt = $pdo->query("DESCRIBE periode");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Periode columns: " . implode(', ', $columns) . "\n";

    if (in_array('angkatan_eligible', $columns, true)) {
        echo "SUCCESS: Column angkatan_eligible exists!\n";
    } else {
        echo "ERROR: Column missing!\n";
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

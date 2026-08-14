<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

try {
    $pdo = Database::getInstance();
    $stmt = $pdo->query("SELECT id, nama, angkatan_eligible FROM periode LIMIT 5");
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Periode List: " . json_encode($list) . "\n";
    echo "PERIODE EDIT API TEST SUCCESSFUL!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$pdo = Database::getInstance();
$list = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
print_r($list);

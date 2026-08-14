<?php
require 'vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$pdo = App\Database::getInstance();
$stmt = $pdo->query('SELECT * FROM peminatan');
print_r($stmt->fetchAll());

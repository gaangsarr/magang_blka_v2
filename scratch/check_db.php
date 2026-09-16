<?php
require_once __DIR__ . '/../vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = \App\Database::getInstance();
echo "--- Periode Data ---\n";
$stmt = $pdo->query("SELECT id, nama, tanggal_mulai, tanggal_selesai, jam_selesai, status FROM periode ORDER BY id DESC");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

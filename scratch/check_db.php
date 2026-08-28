<?php
require_once __DIR__ . '/../vendor/autoload.php';
\Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$pdo = \App\Database::getInstance();
echo "--- Entitas Counts by Tipe ---\n";
$stmt = $pdo->query("SELECT tipe, count(*) as c FROM entitas_perusahaan GROUP BY tipe");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- Sample Data per Tipe ---\n";
$stmt2 = $pdo->query("SELECT id, tipe, parent_id, nama, singkatan, menerima_magang FROM entitas_perusahaan ORDER BY id ASC LIMIT 25");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

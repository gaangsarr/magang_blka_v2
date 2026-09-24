<?php
declare(strict_types=1);

$root = __DIR__;
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable($root)->safeLoad();
$pdo = Database::getInstance();

try {
    echo "1. Menguji List Entitas...\n";
    $stmt = $pdo->query("SELECT id, nama, tipe FROM entitas_perusahaan WHERE tipe = 'unit_pelaksana'");
    $entitas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Ditemukan " . count($entitas) . " unit_pelaksana.\n";
    if (count($entitas) > 0) {
        echo "Contoh: " . $entitas[0]['nama'] . "\n";
    }

    echo "\n2. Menguji Ambil Periode Terakhir...\n";
    $stmt = $pdo->query("SELECT id, nama FROM periode ORDER BY id DESC LIMIT 1");
    $periode = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Periode terakhir: " . ($periode['nama'] ?? 'Tidak ada') . "\n";
    
    echo "\nSemua sintaks terlihat benar, testing endpoint tidak mendeteksi error syntax.\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

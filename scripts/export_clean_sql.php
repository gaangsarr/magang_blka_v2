<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$pdo = Database::getInstance();

$outputFile = __DIR__ . '/../magang_clean_production.sql';

echo "=== MEMULAI GENERASI DATABASE BERSIH PRODUCTION ===\n";

$buffer = [];
$buffer[] = "-- ========================================================";
$buffer[] = "-- INTERN ITPLN - SISTEM MAGANG TERPADU (v2)";
$buffer[] = "-- PRODUCTION CLEAN DATABASE DUMP";
$buffer[] = "-- Generated: " . date('Y-m-d H:i:s');
$buffer[] = "-- ========================================================\n";
$buffer[] = "SET FOREIGN_KEY_CHECKS = 0;";
$buffer[] = "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';";
$buffer[] = "SET AUTOCOMMIT = 0;";
$buffer[] = "START TRANSACTION;";
$buffer[] = "SET time_zone = '+07:00';";
$buffer[] = "SET NAMES utf8mb4;\n";

// Daftar tabel yang TIDAK DIISI DATA (hanya struktur skema CREATE TABLE kosong)
$emptyTables = [
    'email_queue',
    'konfigurasi_surat',
    'log_aktivitas',
    'mahasiswa',
    'pemindahan_peserta',
    'pendaftaran',
    'pendaftaran_histori_penolakan',
    'pendaftaran_peminatan',
    'periode',
    'rate_limits',
    'reservasi',
    'unit_jurusan',
    'unit_pelaksana_periode',
    'unit_peminatan',
    'unit_periode_jurusan',
    'unit_periode_peminatan',
];

// Dapatkan seluruh tabel
$allTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

$fp = fopen($outputFile, 'w');
if (!$fp) {
    die("Gagal membuka file output: $outputFile\n");
}

fwrite($fp, implode("\n", $buffer) . "\n\n");

foreach ($allTables as $table) {
    echo "Memproses tabel: $table ... ";

    // 1. Tulis DROP & CREATE TABLE
    $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
    $createTableSql = $row[1];

    // Reset AUTO_INCREMENT ke 1 jika ada
    $createTableSql = preg_replace('/AUTO_INCREMENT=\d+\s*/', 'AUTO_INCREMENT=1 ', $createTableSql);

    $tableDef = "--\n-- Struktur tabel `$table`\n--\n";
    $tableDef .= "DROP TABLE IF EXISTS `$table`;\n";
    $tableDef .= $createTableSql . ";\n\n";
    fwrite($fp, $tableDef);

    // 2. Cek apakah tabel ini dikosongkan
    if (in_array($table, $emptyTables, true)) {
        echo "OK (Skema kosong)\n";
        continue;
    }

    // 3. Tangani tabel admin secara khusus
    if ($table === 'admin') {
        // Hanya simpan 4 akun BLKA resmi:
        // admin@blka.itpln.ac.id, gangsar2431170@itpln.ac.id, walid@itpln.ac.id, dewiarianti@itpln.ac.id
        $stmt = $pdo->prepare("
            SELECT * FROM `admin` 
            WHERE email IN (
                'admin@blka.itpln.ac.id',
                'gangsar2431170@itpln.ac.id',
                'walid@itpln.ac.id',
                'dewiarianti@itpln.ac.id'
            )
            ORDER BY id ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($rows)) {
            fwrite($fp, "--\n-- Data master `$table` (Hanya 4 Akun BLKA Resmi)\n--\n");
            dumpRows($fp, $table, $rows);
        }
        echo "OK (Disimpan " . count($rows) . " akun admin BLKA)\n";
        continue;
    }

    // 4. Tabel Master lainnya (dump semua data)
    $stmt = $pdo->query("SELECT * FROM `$table`");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $count = count($rows);

    if ($count > 0) {
        fwrite($fp, "--\n-- Data master `$table` ($count baris)\n--\n");
        dumpRows($fp, $table, $rows);
    }
    echo "OK ($count baris)\n";
}

fwrite($fp, "\nCOMMIT;\nSET FOREIGN_KEY_CHECKS = 1;\n");
fclose($fp);

$sizeMb = round(filesize($outputFile) / 1024 / 1024, 2);
echo "\n=== SUKSES! File SQL bersih berhasil dibuat: magang_clean_production.sql ($sizeMb MB) ===\n";

function dumpRows($fp, string $table, array $rows): void {
    if (empty($rows)) return;

    $columns = array_keys($rows[0]);
    $quotedCols = array_map(fn($c) => "`$c`", $columns);
    $colList = implode(', ', $quotedCols);

    // Chunking 100 rows per INSERT agar tidak kena max_allowed_packet
    $chunks = array_chunk($rows, 100);
    foreach ($chunks as $chunk) {
        $valLines = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                if ($val === null) {
                    $vals[] = 'NULL';
                } elseif (is_numeric($val) && !is_string($val)) {
                    $vals[] = $val;
                } else {
                    $escaped = str_replace(["\\", "\0", "\n", "\r", "'", "\"", "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\\'", "\\\"", "\\Z"], (string)$val);
                    $vals[] = "'$escaped'";
                }
            }
            $valLines[] = "(" . implode(', ', $vals) . ")";
        }
        $sql = "INSERT INTO `$table` ($colList) VALUES\n" . implode(",\n", $valLines) . ";\n";
        fwrite($fp, $sql);
    }
    fwrite($fp, "\n");
}

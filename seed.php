<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

Dotenv::createImmutable(__DIR__)->safeLoad();

try {
    $pdo = Database::getInstance();
    
    echo "Memulai seeder dummy data...\n";

    $pdo->beginTransaction();

    // 0. Jurusan (Jika belum ada)
    $stmt = $pdo->query("SELECT COUNT(*) FROM jurusan");
    if ($stmt->fetchColumn() == 0) {
        $sqlFile = __DIR__ . '/seeds/jurusan.sql';
        if (file_exists($sqlFile)) {
            $pdo->exec(file_get_contents($sqlFile));
            echo "Master jurusan (14 data) berhasil diimpor.\n";
        }
    }

    // 1. Peminatan (Jika belum ada)
    $stmt = $pdo->query("SELECT COUNT(*) FROM peminatan");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("
            INSERT INTO peminatan (nama, singkatan) VALUES 
            ('Web Development', 'WEB'),
            ('Data Science', 'DS'),
            ('Jaringan Komputer', 'NET'),
            ('UI/UX Design', 'UIUX')
        ");
        echo "Peminatan ditambahkan.\n";
    }

    // 2. Entitas Perusahaan
    $stmt = $pdo->query("SELECT COUNT(*) FROM entitas_perusahaan");
    if ($stmt->fetchColumn() == 0) {
        $sqlFile = __DIR__ . '/seeds/entitas_perusahaan.sql';
        if (file_exists($sqlFile)) {
            $pdo->exec(file_get_contents($sqlFile));
            echo "Master entitas perusahaan (1.283 data) berhasil diimpor.\n";
        }
    }

    // 3. Periode Aktif
    $stmt = $pdo->query("SELECT id FROM periode WHERE status = 'dibuka' LIMIT 1");
    $periodeId = $stmt->fetchColumn();
    
    if (!$periodeId) {
        $pdo->exec("
            INSERT INTO periode (nama, tanggal_mulai, tanggal_selesai, status, program_1_bulan, program_5_bulan) 
            VALUES ('Semester Ganjil 2026/2027', '2026-08-01', '2026-12-31', 'dibuka', 1, 1)
        ");
        $periodeId = $pdo->lastInsertId();
        echo "Periode aktif ditambahkan (ID: $periodeId).\n";
    } else {
        echo "Periode aktif sudah ada (ID: $periodeId).\n";
    }

    // 4. Mapping Unit Pelaksana Periode (Kuota)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM unit_pelaksana_periode WHERE periode_id = ?");
    $stmt->execute([$periodeId]);
    
    if ($stmt->fetchColumn() == 0) {
        // Ambil semua entitas
        $entitas = $pdo->query("SELECT id FROM entitas_perusahaan")->fetchAll(PDO::FETCH_COLUMN);
        
        $stmtInsertUnit = $pdo->prepare("
            INSERT INTO unit_pelaksana_periode (periode_id, entitas_id, kuota_total, kuota_tersisa, aktif) 
            VALUES (?, ?, ?, ?, 1)
        ");
        
        foreach ($entitas as $idx => $eid) {
            $kuota = ($idx + 1) * 2; // Kuota 2, 4, 6
            $stmtInsertUnit->execute([$periodeId, $eid, $kuota, $kuota]);
        }
        echo "Unit pelaksana untuk periode ini ditambahkan.\n";
    }

    // 5. Mapping Unit Peminatan
    $stmt = $pdo->query("SELECT COUNT(*) FROM unit_peminatan");
    if ($stmt->fetchColumn() == 0) {
        $entitas = $pdo->query("SELECT id FROM entitas_perusahaan")->fetchAll(PDO::FETCH_COLUMN);
        $peminatan = $pdo->query("SELECT id FROM peminatan")->fetchAll(PDO::FETCH_COLUMN);
        
        $stmtUnitPem = $pdo->prepare("INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)");
        
        foreach ($entitas as $eid) {
            // Berikan 2 peminatan acak untuk tiap unit
            $stmtUnitPem->execute([$eid, $peminatan[0]]);
            $stmtUnitPem->execute([$eid, $peminatan[1]]);
        }
        echo "Relasi unit-peminatan ditambahkan.\n";
    }

    $pdo->commit();
    echo "Seeding selesai!\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

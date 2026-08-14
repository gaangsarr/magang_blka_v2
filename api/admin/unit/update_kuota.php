<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (empty($body['entitas_id']) || !isset($body['kuota_total'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID Entitas dan Kuota Total wajib diisi.']);
    exit;
}

$entitasId = (int)$body['entitas_id'];
$kuotaBaru = (int)$body['kuota_total'];
$aktif = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;
$requestedPeriodeId = isset($body['periode_id']) ? (int)$body['periode_id'] : 0;
$adminId = Auth::getAdminId();

try {
    $pdo = Database::getInstance();
    
    $periodeId = null;
    if ($requestedPeriodeId > 0) {
        $stmtCheck = $pdo->prepare("SELECT id FROM periode WHERE id = ?");
        $stmtCheck->execute([$requestedPeriodeId]);
        $periodeId = $stmtCheck->fetchColumn();
    }

    if (!$periodeId) {
        // Cari periode aktif (dibuka atau persiapan)
        $stmtPeriode = $pdo->query("SELECT id FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periodeId = $stmtPeriode->fetchColumn();
    }

    if (!$periodeId) {
        // Fallback periode terbaru
        $stmtPeriode = $pdo->query("SELECT id FROM periode ORDER BY id DESC LIMIT 1");
        $periodeId = $stmtPeriode->fetchColumn();
    }
    
    if (!$periodeId) {
        http_response_code(400);
        echo json_encode(['error' => 'Tidak ada periode yang ditemukan. Silakan buat periode terlebih dahulu.']);
        exit;
    }

    $periodeId = (int)$periodeId;
    
    Database::transaction(function (PDO $pdo) use ($entitasId, $periodeId, $kuotaBaru, $aktif, $adminId) {
        // Cek apakah sudah ada di unit_pelaksana_periode
        $stmtCek = $pdo->prepare("SELECT id, kuota_total, kuota_tersisa FROM unit_pelaksana_periode WHERE entitas_id = ? AND periode_id = ? FOR UPDATE");
        $stmtCek->execute([$entitasId, $periodeId]);
        $existing = $stmtCek->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update
            $selisih = $kuotaBaru - $existing['kuota_total'];
            $kuotaTersisaBaru = $existing['kuota_tersisa'] + $selisih;
            
            if ($kuotaTersisaBaru < 0) {
                throw new \Exception("Kuota tersisa akan menjadi negatif. Tidak bisa mengurangi kuota melebihi yang sudah terpakai.");
            }
            
            $stmtUpdate = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_total = ?, kuota_tersisa = ?, aktif = ? WHERE id = ?");
            $stmtUpdate->execute([$kuotaBaru, $kuotaTersisaBaru, $aktif, $existing['id']]);
            $uppId = $existing['id'];
        } else {
            // Insert
            $stmtInsert = $pdo->prepare("INSERT INTO unit_pelaksana_periode (entitas_id, periode_id, kuota_total, kuota_tersisa, aktif) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->execute([$entitasId, $periodeId, $kuotaBaru, $kuotaBaru, $aktif]);
            $uppId = $pdo->lastInsertId();
        }
        
        // Log Aktivitas
        $stmtLog = $pdo->prepare("INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address) VALUES (?, 'ubah_kuota_unit', 'unit_pelaksana_periode', ?, ?, '127.0.0.1')");
        $stmtLog->execute([$adminId, $uppId, json_encode(['kuota_baru' => $kuotaBaru, 'aktif' => $aktif, 'periode_id' => $periodeId])]);
    });
    
    echo json_encode([
        'ok' => true,
        'message' => 'Kuota berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    if ($e->getMessage() === "Kuota tersisa akan menjadi negatif. Tidak bisa mengurangi kuota melebihi yang sudah terpakai.") {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
    }
}

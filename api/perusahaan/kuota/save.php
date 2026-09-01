<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requirePerusahaanApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$periodeId = (int)($input['periode_id'] ?? 0);
$menerimaMagang = !empty($input['menerima_magang']);
$kuotaTotal = max(0, (int)($input['kuota_total'] ?? 0));
$jurusanIds = array_filter(array_map('intval', (array)($input['jurusan_ids'] ?? [])));
$peminatanIds = array_filter(array_map('intval', (array)($input['peminatan_ids'] ?? [])));

if ($periodeId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Periode magang wajib dipilih.']);
    exit;
}

$pdo = Database::getInstance();
$entitasId = Auth::getPerusahaanEntitasId();
$admin = Auth::getAdmin();

if (!$entitasId) {
    http_response_code(400);
    echo json_encode(['error' => 'Entitas unit tidak terhubung ke akun ini.']);
    exit;
}

try {
    // 1. Cek status periode
    $stmtP = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ? LIMIT 1");
    $stmtP->execute([$periodeId]);
    $periode = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$periode) {
        http_response_code(404);
        echo json_encode(['error' => 'Periode magang tidak ditemukan.']);
        exit;
    }

    $isSuperAdmin = ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin');

    // Jika bukan Super Admin, hanya boleh edit di status persiapan atau draft
    if (!$isSuperAdmin && !in_array($periode['status'], ['persiapan', 'draft'], true)) {
        http_response_code(403);
        echo json_encode(['error' => "Pengaturan kuota periode '{$periode['nama']}' sudah dikunci karena status periode saat ini adalah '" . strtoupper($periode['status']) . "'. Hubungi Super Admin BLKA jika butuh penyesuaian."]);
        exit;
    }

    if ($menerimaMagang && $kuotaTotal <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Jika menerima magang, jumlah kuota total minimal harus 1 mahasiswa.']);
        exit;
    }

    if ($menerimaMagang && empty($jurusanIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Silakan pilih minimal 1 Program Studi yang diterima di unit Anda.']);
        exit;
    }

    if ($menerimaMagang && empty($peminatanIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Silakan pilih minimal 1 Bidang Peminatan / Penempatan yang dibuka di unit Anda.']);
        exit;
    }

    $pdo->beginTransaction();

    // 2. Update master entitas_perusahaan
    $stmtUpdE = $pdo->prepare("UPDATE entitas_perusahaan SET menerima_magang = :m, updated_at = NOW() WHERE id = :eid");
    $stmtUpdE->execute([':m' => $menerimaMagang ? 1 : 0, ':eid' => $entitasId]);

    // 3. Upsert tabel unit_pelaksana_periode
    $stmtUppCheck = $pdo->prepare("SELECT id, kuota_total, kuota_tersisa FROM unit_pelaksana_periode WHERE entitas_id = :eid AND periode_id = :pid LIMIT 1");
    $stmtUppCheck->execute([':eid' => $entitasId, ':pid' => $periodeId]);
    $existingUpp = $stmtUppCheck->fetch(PDO::FETCH_ASSOC);

    $uppId = 0;
    if ($existingUpp) {
        $uppId = (int)$existingUpp['id'];
        $oldTotal = (int)$existingUpp['kuota_total'];
        $oldTersisa = (int)$existingUpp['kuota_tersisa'];
        
        // Hitung selisih kuota jika kuota total bertambah/berkurang
        $selisih = $kuotaTotal - $oldTotal;
        $newTersisa = max(0, $oldTersisa + $selisih);

        $stmtUppUpd = $pdo->prepare("
            UPDATE unit_pelaksana_periode 
            SET kuota_total = :kt, kuota_tersisa = :ks, aktif = :aktif, updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUppUpd->execute([
            ':kt'    => $kuotaTotal,
            ':ks'    => $newTersisa,
            ':aktif' => $menerimaMagang ? 1 : 0,
            ':id'    => $uppId,
        ]);
    } else {
        $stmtUppIns = $pdo->prepare("
            INSERT INTO unit_pelaksana_periode (entitas_id, periode_id, kuota_total, kuota_tersisa, aktif, created_at, updated_at)
            VALUES (:eid, :pid, :kt, :ks, :aktif, NOW(), NOW())
        ");
        $stmtUppIns->execute([
            ':eid'   => $entitasId,
            ':pid'   => $periodeId,
            ':kt'    => $kuotaTotal,
            ':ks'    => $kuotaTotal,
            ':aktif' => $menerimaMagang ? 1 : 0,
        ]);
        $uppId = (int)$pdo->lastInsertId();
    }

    // 4. Sinkronisasi Program Studi (unit_periode_jurusan)
    $stmtDelJ = $pdo->prepare("DELETE FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = ?");
    $stmtDelJ->execute([$uppId]);

    if ($menerimaMagang && !empty($jurusanIds)) {
        $stmtInsJ = $pdo->prepare("INSERT INTO unit_periode_jurusan (unit_pelaksana_periode_id, jurusan_id) VALUES (:upp_id, :jid)");
        foreach ($jurusanIds as $jid) {
            $stmtInsJ->execute([':upp_id' => $uppId, ':jid' => $jid]);
        }
    }

    // 5. Sinkronisasi Peminatan (unit_periode_peminatan)
    $stmtDelPem = $pdo->prepare("DELETE FROM unit_periode_peminatan WHERE unit_pelaksana_periode_id = ?");
    $stmtDelPem->execute([$uppId]);

    if ($menerimaMagang && !empty($peminatanIds)) {
        $stmtInsPem = $pdo->prepare("INSERT INTO unit_periode_peminatan (unit_pelaksana_periode_id, peminatan_id) VALUES (:upp_id, :pid)");
        foreach ($peminatanIds as $pid) {
            $stmtInsPem->execute([':upp_id' => $uppId, ':pid' => $pid]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'ok'      => true,
        'message' => 'Pengaturan kuota, program studi, dan peminatan periode berhasil disimpan.',
        'upp_id'  => $uppId,
    ]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal menyimpan pengaturan kuota.')]);
}

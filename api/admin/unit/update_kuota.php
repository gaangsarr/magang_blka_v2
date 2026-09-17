<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireAdminApi(); // QUALITY-01: standardisasi auth guard
Auth::requireCsrfApi();  // BLOCKER-05: CSRF protection

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
$tipeKuota = in_array($body['tipe_kuota'] ?? '', ['keseluruhan', 'breakdown'], true) ? $body['tipe_kuota'] : 'keseluruhan';
$aktif = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;
$requestedPeriodeId = isset($body['periode_id']) ? (int)$body['periode_id'] : 0;
$adminId = Auth::getAdminId();

$hasProdiPayload = isset($body['prodi_ids']) && is_array($body['prodi_ids']);
$prodiIds = $hasProdiPayload
    ? array_values(array_unique(array_filter(array_map('intval', $body['prodi_ids']))))
    : [];

$prodiAllocations = (isset($body['prodi_allocations']) && is_array($body['prodi_allocations'])) ? $body['prodi_allocations'] : [];
$allocMap = [];
foreach ($prodiAllocations as $k => $v) {
    $allocMap[(int)$k] = (int)$v;
}

$hasPeminatanPayload = isset($body['peminatan_ids']) && is_array($body['peminatan_ids']);
$peminatanIds = $hasPeminatanPayload
    ? array_values(array_unique(array_filter(array_map('intval', $body['peminatan_ids']))))
    : [];

if ($aktif === 1 && $tipeKuota === 'breakdown') {
    if (empty($prodiIds)) {
        http_response_code(400);
        echo json_encode(['error' => 'Pada mode Kuota Terbagi per Prodi (Breakdown), silakan pilih minimal 1 Program Studi.']);
        exit;
    }

    $sumBreakdown = 0;
    foreach ($prodiIds as $jid) {
        $q = $allocMap[$jid] ?? 0;
        if ($q <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Pada mode breakdown, setiap program studi yang dipilih wajib memiliki kuota minimal 1.']);
            exit;
        }
        $sumBreakdown += $q;
    }

    if ($sumBreakdown !== $kuotaBaru) {
        http_response_code(400);
        echo json_encode(['error' => "Jumlah alokasi per prodi ($sumBreakdown) harus sama persis dengan Total Kuota ($kuotaBaru)."]);
        exit;
    }
}

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
    
    Database::transaction(function (PDO $pdo) use (
        $entitasId, $periodeId, $kuotaBaru, $tipeKuota, $aktif, $adminId, 
        $hasProdiPayload, $prodiIds, $allocMap, $hasPeminatanPayload, $peminatanIds
    ) {
        // Cek apakah sudah ada di unit_pelaksana_periode
        $stmtCek = $pdo->prepare("SELECT id, kuota_total, kuota_tersisa FROM unit_pelaksana_periode WHERE entitas_id = ? AND periode_id = ? FOR UPDATE");
        $stmtCek->execute([$entitasId, $periodeId]);
        $existing = $stmtCek->fetch(PDO::FETCH_ASSOC);
        
        $usedPerJurusan = [];
        $totalUsed = 0;

        if ($existing) {
            $uppId = (int)$existing['id'];

            // Cek pendaftaran dan reservasi aktif
            $stmtUsedP = $pdo->prepare("
                SELECT m.jurusan_id, COUNT(*) as jml
                FROM pendaftaran p
                JOIN mahasiswa m ON p.mahasiswa_id = m.id
                WHERE p.unit_pelaksana_periode_id = ?
                GROUP BY m.jurusan_id
            ");
            $stmtUsedP->execute([$uppId]);
            foreach ($stmtUsedP->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usedPerJurusan[(int)$row['jurusan_id']] = (int)$row['jml'];
                $totalUsed += (int)$row['jml'];
            }

            $stmtUsedR = $pdo->prepare("
                SELECT IFNULL(r.jurusan_id, m.jurusan_id) as jurusan_id, COUNT(*) as jml
                FROM reservasi r
                JOIN mahasiswa m ON r.mahasiswa_id = m.id
                WHERE r.unit_pelaksana_periode_id = ? AND r.status = 'ditahan' AND r.expired_at > NOW()
                GROUP BY jurusan_id
            ");
            $stmtUsedR->execute([$uppId]);
            foreach ($stmtUsedR->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $jid = (int)$row['jurusan_id'];
                $usedPerJurusan[$jid] = ($usedPerJurusan[$jid] ?? 0) + (int)$row['jml'];
                $totalUsed += (int)$row['jml'];
            }

            if ($kuotaBaru < $totalUsed) {
                throw new \Exception("Total kuota tidak boleh kurang dari $totalUsed karena saat ini sudah ada $totalUsed mahasiswa yang terdaftar/mereservasi.");
            }

            if ($tipeKuota === 'breakdown') {
                foreach ($prodiIds as $jid) {
                    $used = $usedPerJurusan[$jid] ?? 0;
                    $prodiAlloc = $allocMap[$jid] ?? 0;
                    if ($prodiAlloc < $used) {
                        $stmtJn = $pdo->prepare("SELECT nama_jurusan FROM jurusan WHERE id = ?");
                        $stmtJn->execute([$jid]);
                        $jName = $stmtJn->fetchColumn() ?: "ID $jid";
                        throw new \Exception("Kuota untuk $jName tidak boleh kurang dari $used karena sudah ada $used mahasiswa yang terdaftar/mereservasi.");
                    }
                }
            }

            // Update
            $selisih = $kuotaBaru - (int)$existing['kuota_total'];
            $kuotaTersisaBaru = max(0, (int)$existing['kuota_tersisa'] + $selisih);
            
            $stmtUpdate = $pdo->prepare("UPDATE unit_pelaksana_periode SET tipe_kuota = ?, kuota_total = ?, kuota_tersisa = ?, aktif = ?, updated_at = NOW() WHERE id = ?");
            $stmtUpdate->execute([$tipeKuota, $kuotaBaru, $kuotaTersisaBaru, $aktif, $uppId]);
            $isNew = false;
        } else {
            // Insert
            $stmtInsert = $pdo->prepare("INSERT INTO unit_pelaksana_periode (entitas_id, periode_id, tipe_kuota, kuota_total, kuota_tersisa, aktif, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmtInsert->execute([$entitasId, $periodeId, $tipeKuota, $kuotaBaru, $kuotaBaru, $aktif]);
            $uppId = (int)$pdo->lastInsertId();
            $isNew = true;
        }

        // 1. Sync Prodi (unit_periode_jurusan & master unit_jurusan)
        if ($hasProdiPayload) {
            $stmtDel = $pdo->prepare("DELETE FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = ?");
            $stmtDel->execute([$uppId]);

            if (!empty($prodiIds)) {
                $stmtIns = $pdo->prepare("INSERT INTO unit_periode_jurusan (unit_pelaksana_periode_id, jurusan_id, kuota_total, kuota_tersisa) VALUES (?, ?, ?, ?)");
                foreach ($prodiIds as $jid) {
                    $kt = ($tipeKuota === 'breakdown') ? ($allocMap[$jid] ?? 0) : null;
                    $used = $usedPerJurusan[$jid] ?? 0;
                    $ks = ($tipeKuota === 'breakdown') ? max(0, ($allocMap[$jid] ?? 0) - $used) : null;
                    $stmtIns->execute([$uppId, $jid, $kt, $ks]);
                }
            }

            // Sync Master unit_jurusan
            $stmtDelMasterJur = $pdo->prepare("DELETE FROM unit_jurusan WHERE entitas_id = ?");
            $stmtDelMasterJur->execute([$entitasId]);

            if (!empty($prodiIds)) {
                $stmtInsMasterJur = $pdo->prepare("INSERT INTO unit_jurusan (entitas_id, jurusan_id) VALUES (?, ?)");
                foreach ($prodiIds as $jid) {
                    $stmtInsMasterJur->execute([$entitasId, $jid]);
                }
            }
        } elseif ($isNew) {
            // Jika row baru dan payload prodi tidak dikirim, copy default dari master unit_jurusan
            $stmtCopyJur = $pdo->prepare("
                INSERT IGNORE INTO unit_periode_jurusan (unit_pelaksana_periode_id, jurusan_id)
                SELECT ?, jurusan_id FROM unit_jurusan WHERE entitas_id = ?
            ");
            $stmtCopyJur->execute([$uppId, $entitasId]);
        }

        // 2. Sync Peminatan (unit_periode_peminatan & master unit_peminatan)
        if ($hasPeminatanPayload) {
            $stmtDel = $pdo->prepare("DELETE FROM unit_periode_peminatan WHERE unit_pelaksana_periode_id = ?");
            $stmtDel->execute([$uppId]);

            if (!empty($peminatanIds)) {
                $stmtIns = $pdo->prepare("INSERT INTO unit_periode_peminatan (unit_pelaksana_periode_id, peminatan_id) VALUES (?, ?)");
                foreach ($peminatanIds as $pid) {
                    $stmtIns->execute([$uppId, $pid]);
                }
            }

            // Sync Master unit_peminatan
            $stmtDelMasterPem = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDelMasterPem->execute([$entitasId]);

            if (!empty($peminatanIds)) {
                $stmtInsMasterPem = $pdo->prepare("INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)");
                foreach ($peminatanIds as $pid) {
                    $stmtInsMasterPem->execute([$entitasId, $pid]);
                }
            }
        } elseif ($isNew) {
            // Jika row baru dan payload peminatan tidak dikirim, copy default dari master unit_peminatan
            $stmtCopyPem = $pdo->prepare("
                INSERT IGNORE INTO unit_periode_peminatan (unit_pelaksana_periode_id, peminatan_id)
                SELECT ?, peminatan_id FROM unit_peminatan WHERE entitas_id = ?
            ");
            $stmtCopyPem->execute([$uppId, $entitasId]);
        }
        
        // Log Aktivitas
        $stmtLog = $pdo->prepare("INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address) VALUES (?, 'ubah_kuota_unit', 'unit_pelaksana_periode', ?, ?, '127.0.0.1')");
        $stmtLog->execute([
            $adminId, 
            $uppId, 
            json_encode([
                'kuota_baru' => $kuotaBaru, 
                'tipe_kuota' => $tipeKuota,
                'prodi_allocations' => $allocMap,
                'aktif' => $aktif, 
                'periode_id' => $periodeId,
                'prodi_ids' => $prodiIds,
                'peminatan_ids' => $peminatanIds
            ])
        ]);
    });
    
    echo json_encode([
        'ok' => true,
        'message' => 'Kuota berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    if (str_contains($e->getMessage(), "Total kuota tidak boleh kurang") || str_contains($e->getMessage(), "Kuota untuk")) {
        http_response_code(400);
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui kuota unit.')]);
    }
}


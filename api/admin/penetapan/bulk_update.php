<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

$pendaftaranIds = $input['pendaftaran_ids'] ?? [];
$statusTarget = $input['status'] ?? null;
$newUppId = $input['new_unit_pelaksana_periode_id'] ?? null;
$catatanAdmin = trim($input['catatan_admin'] ?? '');

if (empty($pendaftaranIds) || !is_array($pendaftaranIds) || !$statusTarget) {
    http_response_code(400);
    echo json_encode(['error' => 'Pilih setidaknya 1 mahasiswa dan tentukan status penetapan.']);
    exit;
}

$validStatuses = ['diajukan', 'diverifikasi', 'diterima', 'dipindahkan', 'ditolak'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid.']);
    exit;
}

try {
    $processedCount = 0;

    Database::transaction(function (PDO $pdo) use ($pendaftaranIds, $statusTarget, $newUppId, $catatanAdmin, &$processedCount) {
        foreach ($pendaftaranIds as $pid) {
            $pendaftaranId = (int)$pid;

            // 1. Lock record pendaftaran
            $stmt = $pdo->prepare("
                SELECT id, mahasiswa_id, periode_id, unit_pelaksana_periode_id, unit_pelaksana_periode_asal_id, status, is_dipindahkan 
                FROM pendaftaran 
                WHERE id = :id 
                FOR UPDATE
            ");
            $stmt->execute([':id' => $pendaftaranId]);
            $pdft = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pdft) continue;

            $currentUppId = (int)$pdft['unit_pelaksana_periode_id'];
            $currentAsalId = $pdft['unit_pelaksana_periode_asal_id'] ? (int)$pdft['unit_pelaksana_periode_asal_id'] : null;

            // 2. Jika dipindahkan paksa
            if ($statusTarget === 'dipindahkan') {
                if (!$newUppId) {
                    throw new \RuntimeException("Unit pelaksana tujuan wajib dipilih untuk pemindahan paksa.");
                }

                $targetUppId = (int)$newUppId;

                if ($targetUppId !== $currentUppId) {
                    // Cek kuota
                    $stmtCheck = $pdo->prepare("SELECT id, kuota_tersisa FROM unit_pelaksana_periode WHERE id = :id FOR UPDATE");
                    $stmtCheck->execute([':id' => $targetUppId]);
                    $newUpp = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    if (!$newUpp || (int)$newUpp['kuota_tersisa'] <= 0) {
                        throw new \RuntimeException("Kuota unit pelaksana tujuan sudah tidak mencukupi untuk pemindahan massal.");
                    }

                    $asalToSave = $currentAsalId ?? $currentUppId;

                    // Kembalikan 1 kuota ke unit lama jika tidak ditolak sebelumnya
                    if ($pdft['status'] !== 'ditolak') {
                        $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
                        $stmtFree->execute([':id' => $currentUppId]);
                    }

                    // Potong 1 kuota dari unit baru
                    $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa - 1 WHERE id = :id AND kuota_tersisa > 0");
                    $stmtDeduct->execute([':id' => $targetUppId]);

                    // Update pendaftaran
                    $stmtUpd = $pdo->prepare("
                        UPDATE pendaftaran 
                        SET 
                            status = 'dipindahkan',
                            unit_pelaksana_periode_id = :new_upp,
                            unit_pelaksana_periode_asal_id = :asal_upp,
                            is_dipindahkan = 1,
                            catatan_admin = :catatan,
                            updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmtUpd->execute([
                        ':new_upp'  => $targetUppId,
                        ':asal_upp' => $asalToSave,
                        ':catatan'  => $catatanAdmin ?: 'Dipindahkan ke unit pelaksana lain secara massal oleh Admin REMATE.',
                        ':id'        => $pendaftaranId
                    ]);

                    $processedCount++;
                    continue;
                }
            }

            // 3. Jika disetujui massal (kembalikan ke unit pilihan awal jika sebelumnya dipindahkan)
            if ($statusTarget === 'diterima' && $pdft['is_dipindahkan'] == 1 && $currentAsalId) {
                if ($currentUppId !== $currentAsalId) {
                    $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
                    $stmtFree->execute([':id' => $currentUppId]);

                    $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa - 1 WHERE id = :id AND kuota_tersisa > 0");
                    $stmtDeduct->execute([':id' => $currentAsalId]);
                }

                $stmtUpd = $pdo->prepare("
                    UPDATE pendaftaran 
                    SET 
                        status = 'diterima',
                        unit_pelaksana_periode_id = :asal_upp,
                        is_dipindahkan = 0,
                        catatan_admin = :catatan,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmtUpd->execute([
                    ':asal_upp' => $currentAsalId,
                    ':catatan'  => $catatanAdmin,
                    ':id'        => $pendaftaranId
                ]);

                $processedCount++;
                continue;
            }

            // 4. Jika sebelumnya ditolak dan dipulihkan
            if ($pdft['status'] === 'ditolak' && in_array($statusTarget, ['diterima', 'diajukan', 'diverifikasi'])) {
                $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa - 1 WHERE id = :id AND kuota_tersisa > 0");
                $stmtDeduct->execute([':id' => $currentUppId]);
            }

            // 5. Jika status ditolak
            if ($statusTarget === 'ditolak' && $pdft['status'] !== 'ditolak') {
                $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
                $stmtFree->execute([':id' => $currentUppId]);
            }

            // 6. Update status biasa
            $stmtUpd = $pdo->prepare("
                UPDATE pendaftaran 
                SET 
                    status = :status,
                    catatan_admin = :catatan,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpd->execute([
                ':status'  => $statusTarget,
                ':catatan' => $catatanAdmin,
                ':id'      => $pendaftaranId
            ]);

            $processedCount++;
        }
    });

    echo json_encode([
        'ok' => true,
        'count' => $processedCount,
        'message' => "Berhasil memperbarui penetapan {$processedCount} mahasiswa."
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

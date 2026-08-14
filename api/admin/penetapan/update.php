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

$pendaftaranId = $input['pendaftaran_id'] ?? null;
$statusTarget = $input['status'] ?? null; // 'diterima', 'dipindahkan', 'ditolak', 'diajukan'
$newUppId = $input['new_unit_pelaksana_periode_id'] ?? null;
$catatanAdmin = trim($input['catatan_admin'] ?? '');

if (!$pendaftaranId || !$statusTarget) {
    http_response_code(400);
    echo json_encode(['error' => 'ID Pendaftaran dan status wajib diisi.']);
    exit;
}

$validStatuses = ['diajukan', 'diverifikasi', 'diterima', 'dipindahkan', 'ditolak'];
if (!in_array($statusTarget, $validStatuses, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tidak valid.']);
    exit;
}

try {
    Database::transaction(function (PDO $pdo) use ($pendaftaranId, $statusTarget, $newUppId, $catatanAdmin) {
        // 1. Lock record pendaftaran
        $stmt = $pdo->prepare("
            SELECT id, mahasiswa_id, periode_id, unit_pelaksana_periode_id, unit_pelaksana_periode_asal_id, status, is_dipindahkan 
            FROM pendaftaran 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $pdft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pdft) {
            throw new \RuntimeException("Data pendaftaran #{$pendaftaranId} tidak ditemukan.");
        }

        $currentUppId = (int)$pdft['unit_pelaksana_periode_id'];
        $currentAsalId = $pdft['unit_pelaksana_periode_asal_id'] ? (int)$pdft['unit_pelaksana_periode_asal_id'] : null;

        // 2. Apabila dipindahkan paksa ke unit baru
        if ($statusTarget === 'dipindahkan') {
            if (!$newUppId) {
                throw new \RuntimeException("Unit pelaksana tujuan wajib dipilih untuk pemindahan paksa.");
            }

            $newUppId = (int)$newUppId;

            if ($newUppId !== $currentUppId) {
                // Cek ketersediaan kuota unit baru
                $stmtCheck = $pdo->prepare("SELECT id, kuota_tersisa FROM unit_pelaksana_periode WHERE id = :id FOR UPDATE");
                $stmtCheck->execute([':id' => $newUppId]);
                $newUpp = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                if (!$newUpp) {
                    throw new \RuntimeException("Unit pelaksana tujuan tidak ditemukan.");
                }

                if ((int)$newUpp['kuota_tersisa'] <= 0) {
                    throw new \RuntimeException("Kuota unit pelaksana tujuan sudah penuh.");
                }

                // Simpan unit asal jika belum ada
                $asalToSave = $currentAsalId ?? $currentUppId;

                // Kembalikan 1 kuota ke unit lama jika sebelumnya aktif (bukan ditolak)
                if ($pdft['status'] !== 'ditolak') {
                    $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
                    $stmtFree->execute([':id' => $currentUppId]);
                }

                // Potong 1 kuota dari unit baru
                $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa - 1 WHERE id = :id AND kuota_tersisa > 0");
                $stmtDeduct->execute([':id' => $newUppId]);

                // Update record pendaftaran
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
                    ':new_upp'  => $newUppId,
                    ':asal_upp' => $asalToSave,
                    ':catatan'  => $catatanAdmin ?: 'Dipindahkan ke unit pelaksana lain oleh Administrator BLKA.',
                    ':id'        => $pendaftaranId
                ]);

                return;
            }
        }

        // 3. Jika dikembalikan ke unit pilihan awal (status target = 'diterima' & sebelumnya dipindahkan)
        if ($statusTarget === 'diterima' && $pdft['is_dipindahkan'] == 1 && $currentAsalId) {
            if ($currentUppId !== $currentAsalId) {
                // Free 1 kuota dari unit pemindahan saat ini
                $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
                $stmtFree->execute([':id' => $currentUppId]);

                // Deduct 1 kuota dari unit asal
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

            return;
        }

        // 4. Jika sebelumnya ditolak dan sekarang disetujui / dipulihkan
        if ($pdft['status'] === 'ditolak' && in_array($statusTarget, ['diterima', 'diajukan', 'diverifikasi'])) {
            $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa - 1 WHERE id = :id AND kuota_tersisa > 0");
            $stmtDeduct->execute([':id' => $currentUppId]);
        }

        // 5. Jika status ditolak (bebankan kembali kuota jika sebelumnya bukan ditolak)
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
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Status penetapan berhasil diperbarui.'
    ]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}

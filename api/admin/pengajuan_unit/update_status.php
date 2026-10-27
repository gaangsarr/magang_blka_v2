<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PengajuanUnitHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard ketat: Khusus Super Admin dan Admin BLKA
Auth::requireBlkaApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format data tidak valid.']);
    exit;
}

$mode = trim((string)($input['mode'] ?? 'single')); // 'single' | 'bulk_entitas'
$status = trim((string)($input['status'] ?? ''));
$alasan = trim((string)($input['alasan_admin'] ?? ''));
$alasan = mb_substr($alasan, 0, 1000, 'UTF-8');

if (!in_array($status, ['menunggu', 'diproses', 'ditolak'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Status tindak lanjut tidak valid.']);
    exit;
}

if ($status === 'ditolak' && $alasan === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Alasan/catatan wajib diisi saat menolak pengajuan.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $adminId = Auth::getAdminId();

    $pdo->beginTransaction();

    if ($mode === 'single') {
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'ID pengajuan tidak valid.']);
            exit;
        }

        // Cek pengajuan
        $stmtCheck = $pdo->prepare("SELECT id, jenis, status, nama_unit_baru, entitas_id FROM pengajuan_unit_mahasiswa WHERE id = :id");
        $stmtCheck->execute([':id' => $id]);
        $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Pengajuan tidak ditemukan.']);
            exit;
        }

        $diprosesOleh = ($status === 'menunggu') ? null : $adminId;
        $diprosesAt   = ($status === 'menunggu') ? null : date('Y-m-d H:i:s');
        $alasanVal    = ($status === 'menunggu') ? null : ($alasan !== '' ? $alasan : null);

        // Update target pengajuan
        $stmtUpd = $pdo->prepare("
            UPDATE pengajuan_unit_mahasiswa 
            SET status = :st,
                alasan_admin = :alasan,
                diproses_oleh = :aid,
                diproses_at = :dat,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':st'     => $status,
            ':alasan' => $alasanVal,
            ':aid'    => $diprosesOleh,
            ':dat'    => $diprosesAt,
            ':id'     => $id
        ]);

        // Jika ini adalah usulan unit baru, update juga seluruh dukungan_usulan yang terhubung
        if ($row['jenis'] === 'usulan_unit_baru') {
            $stmtUpdDuk = $pdo->prepare("
                UPDATE pengajuan_unit_mahasiswa 
                SET status = :st,
                    alasan_admin = :alasan,
                    diproses_oleh = :aid,
                    diproses_at = :dat,
                    updated_at = NOW()
                WHERE usulan_induk_id = :uid AND status != 'dibatalkan'
            ");
            $stmtUpdDuk->execute([
                ':st'     => $status,
                ':alasan' => $alasanVal,
                ':aid'    => $diprosesOleh,
                ':dat'    => $diprosesAt,
                ':uid'    => $id
            ]);
        }

        PengajuanUnitHelper::logAdmin($pdo, $adminId, "ubah_status_{$status}", $id, [
            'status_baru'  => $status,
            'alasan'       => $alasan,
            'jenis'        => $row['jenis']
        ]);

        $pdo->commit();

        echo json_encode([
            'ok'      => true,
            'message' => "Status pengajuan berhasil diubah menjadi '{$status}'."
        ]);
        exit;
    }

    if ($mode === 'bulk_entitas') {
        $entitasId = (int)($input['entitas_id'] ?? 0);
        if ($entitasId <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'ID entitas unit tidak valid.']);
            exit;
        }

        $diprosesOleh = ($status === 'menunggu') ? null : $adminId;
        $diprosesAt   = ($status === 'menunggu') ? null : date('Y-m-d H:i:s');
        $alasanVal    = ($status === 'menunggu') ? null : ($alasan !== '' ? $alasan : null);

        $stmtUpd = $pdo->prepare("
            UPDATE pengajuan_unit_mahasiswa 
            SET status = :st,
                alasan_admin = :alasan,
                diproses_oleh = :aid,
                diproses_at = :dat,
                updated_at = NOW()
            WHERE entitas_id = :eid 
              AND jenis = 'request_kuota'
              AND status IN ('menunggu', 'diproses', 'ditolak')
        ");
        $stmtUpd->execute([
            ':st'     => $status,
            ':alasan' => $alasanVal,
            ':aid'    => $diprosesOleh,
            ':dat'    => $diprosesAt,
            ':eid'    => $entitasId
        ]);
        $affected = $stmtUpd->rowCount();

        PengajuanUnitHelper::logAdmin($pdo, $adminId, "bulk_status_{$status}", $entitasId, [
            'entitas_id'     => $entitasId,
            'status_baru'    => $status,
            'alasan'         => $alasan,
            'affected_count' => $affected
        ]);

        $pdo->commit();

        echo json_encode([
            'ok'             => true,
            'affected_count' => $affected,
            'message'        => "{$affected} pengajuan kuota berhasil diperbarui menjadi '{$status}'."
        ]);
        exit;
    }

    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => 'Mode tidak valid.']);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui status pengajuan.')]);
}

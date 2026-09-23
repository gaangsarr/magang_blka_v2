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

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $periodeId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;

    if ($periodeId <= 0) {
        $stmtP = $pdo->query("SELECT id FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periodeId = (int)$stmtP->fetchColumn();
    }

    if ($periodeId <= 0) {
        echo json_encode(['ok' => true, 'units' => []]);
        exit;
    }

    // Ambil seluruh unit aktif pada periode ini yang menerima magang
    $stmt = $pdo->prepare("
        SELECT 
            upp.id AS upp_id,
            upp.entitas_id,
            upp.kuota_total,
            upp.kuota_tersisa,
            upp.tipe_kuota,
            ep.nama AS nama_unit,
            ep.singkatan,
            ep.alamat,
            ep.latitude,
            ep.longitude,
            parent.nama AS nama_parent
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan ep ON upp.entitas_id = ep.id
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        WHERE upp.periode_id = :pid
          AND upp.aktif = 1
          AND ep.menerima_magang = 1
        ORDER BY ep.nama ASC
    ");
    $stmt->execute([':pid' => $periodeId]);
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil prodi yang dibuka untuk masing-masing unit
    $uppIds = array_column($units, 'upp_id');
    $prodiMap = [];
    if (!empty($uppIds)) {
        $inClause = implode(',', array_fill(0, count($uppIds), '?'));
        $stmtUpj = $pdo->prepare("
            SELECT upj.unit_pelaksana_periode_id, upj.jurusan_id, upj.kuota_total, upj.kuota_tersisa, j.nama_jurusan
            FROM unit_periode_jurusan upj
            JOIN jurusan j ON upj.jurusan_id = j.id
            WHERE upj.unit_pelaksana_periode_id IN ({$inClause})
        ");
        $stmtUpj->execute($uppIds);
        while ($row = $stmtUpj->fetch(PDO::FETCH_ASSOC)) {
            $uId = (int)$row['unit_pelaksana_periode_id'];
            if (!isset($prodiMap[$uId])) {
                $prodiMap[$uId] = [];
            }
            $prodiMap[$uId][] = [
                'jurusan_id'    => (int)$row['jurusan_id'],
                'nama_jurusan'  => $row['nama_jurusan'],
                'kuota_total'   => $row['kuota_total'] !== null ? (int)$row['kuota_total'] : null,
                'kuota_tersisa' => $row['kuota_tersisa'] !== null ? (int)$row['kuota_tersisa'] : null,
            ];
        }
    }

    $result = array_map(function($u) use ($prodiMap, $entitasId) {
        $uId = (int)$u['upp_id'];
        $u['prodi_list'] = $prodiMap[$uId] ?? [];
        $u['prodi_ids'] = array_column($u['prodi_list'], 'jurusan_id');
        $u['is_self'] = ((int)$u['entitas_id'] === $entitasId);
        return $u;
    }, $units);

    echo json_encode([
        'ok' => true,
        'periode_id' => $periodeId,
        'units' => $result
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat daftar unit tujuan.')]);
}

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
    $admin = Auth::getAdmin();

    if (!$entitasId) {
        if ($admin && ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin')) {
            $requestedEid = isset($_GET['entitas_id']) ? (int)$_GET['entitas_id'] : 0;
            if ($requestedEid > 0) {
                $entitasId = $requestedEid;
            } else {
                $stmtFirst = $pdo->query("SELECT id FROM entitas_perusahaan WHERE tipe IN ('unit_pelaksana', 'unit_layanan') ORDER BY id ASC LIMIT 1");
                $entitasId = (int)$stmtFirst->fetchColumn();
            }
        }
    }

    if (!$entitasId) {
        http_response_code(400);
        echo json_encode(['error' => 'Entitas unit tidak terhubung ke akun ini.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            ep.id, ep.nama, ep.singkatan, ep.tipe, ep.alamat, ep.latitude, ep.longitude,
            ep.menerima_magang, ep.pic_nama, ep.pic_jabatan, ep.pic_kontak, ep.pic_email,
            parent.nama AS parent_nama
        FROM entitas_perusahaan ep
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        WHERE ep.id = ?
        LIMIT 1
    ");
    $stmt->execute([$entitasId]);
    $entitas = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$entitas) {
        http_response_code(404);
        echo json_encode(['error' => 'Data entitas perusahaan tidak ditemukan.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'data' => [
            'entitas' => [
                'id'              => (int)$entitas['id'],
                'nama'            => $entitas['nama'],
                'singkatan'       => $entitas['singkatan'],
                'tipe'            => $entitas['tipe'],
                'alamat'          => $entitas['alamat'],
                'latitude'        => $entitas['latitude'],
                'longitude'       => $entitas['longitude'],
                'menerima_magang' => (bool)$entitas['menerima_magang'],
                'parent_nama'     => $entitas['parent_nama'] ?? 'PLN Group',
                'pic_nama'        => $entitas['pic_nama'] ?? '',
                'pic_jabatan'     => $entitas['pic_jabatan'] ?? '',
                'pic_kontak'      => $entitas['pic_kontak'] ?? '',
                'pic_email'       => $entitas['pic_email'] ?? '',
            ],
            'account' => [
                'id'       => (int)$admin['id'],
                'username' => $admin['username'] ?? '',
                'email'    => $admin['email'] ?? '',
                'nama'     => $admin['nama'],
            ]
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil profil perusahaan.')]);
}

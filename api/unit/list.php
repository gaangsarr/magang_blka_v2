<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$lat = isset($body['lat']) ? (float)$body['lat'] : null;
$lng = isset($body['lng']) ? (float)$body['lng'] : null;
$periodeId = isset($body['periode_id']) ? (int)$body['periode_id'] : null;
$peminatanIds = isset($body['peminatan_ids']) && is_array($body['peminatan_ids']) ? array_map('intval', $body['peminatan_ids']) : [];

if ($lat === null || $lng === null || !$periodeId) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter lat, lng, dan periode_id wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Siapkan klausa IN untuk peminatan
    $inClause = '0';
    if (!empty($peminatanIds)) {
        $inClause = implode(',', $peminatanIds);
    }
    
    // Haversine formula: 6371 km radius bumi
    $sql = "
        SELECT 
            upp.id AS upp_id,
            e.id AS entitas_id,
            e.tipe,
            e.nama AS nama_unit,
            e.singkatan,
            e.alamat,
            e.latitude,
            e.longitude,
            parent.nama AS nama_parent,
            upp.kuota_tersisa,
            upp.kuota_total,
            (
                6371 * acos(
                    cos(radians(:lat1)) * cos(radians(e.latitude)) * cos(radians(e.longitude) - radians(:lng)) + 
                    sin(radians(:lat2)) * sin(radians(e.latitude))
                )
            ) AS jarak,
            (
                SELECT COUNT(*) FROM unit_peminatan up 
                WHERE up.entitas_id = e.id 
                AND up.peminatan_id IN ($inClause)
            ) AS kecocokan
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
        WHERE upp.periode_id = :periode_id AND upp.aktif = 1
        ORDER BY kecocokan DESC, jarak ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':lat1' => $lat,
        ':lat2' => $lat,
        ':lng' => $lng,
        ':periode_id' => $periodeId
    ]);
    
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pastikan nilai float terformat baik
    foreach ($units as &$u) {
        $u['jarak'] = $u['jarak'] !== null ? round((float)$u['jarak'], 2) : null;
        $u['kuota_tersisa'] = (int)$u['kuota_tersisa'];
        $u['kuota_total'] = (int)$u['kuota_total'];
        $u['kecocokan'] = (int)$u['kecocokan'];
    }
    unset($u);
    
    echo json_encode([
        'ok' => true,
        'data' => $units
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
}

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
    
    // Auto-cleanup reservasi kadaluarsa agar kuota yang ditampilkan selalu fresh & akurat
    $pdo->exec("
        UPDATE unit_pelaksana_periode upp
        JOIN (
            SELECT unit_pelaksana_periode_id, COUNT(*) AS jumlah
            FROM reservasi
            WHERE status = 'ditahan' AND expired_at < NOW()
            GROUP BY unit_pelaksana_periode_id
        ) r ON upp.id = r.unit_pelaksana_periode_id
        SET upp.kuota_tersisa = LEAST(upp.kuota_total, upp.kuota_tersisa + r.jumlah);
        
        UPDATE reservasi SET status = 'kadaluarsa' WHERE status = 'ditahan' AND expired_at < NOW();
    ");
    
    // Validasi periode aktif & angkatan eligible
    $stmtP = $pdo->prepare("SELECT angkatan_eligible, nama, status FROM periode WHERE id = :pid");
    $stmtP->execute([':pid' => $periodeId]);
    $periodeData = $stmtP->fetch(PDO::FETCH_ASSOC);
    if (!$periodeData || $periodeData['status'] !== 'dibuka') {
        http_response_code(400);
        echo json_encode(['error' => 'Periode magang ini tidak sedang dibuka.']);
        exit;
    }
    $mhsData = Auth::getMahasiswa();
    $mhsId = Auth::getMahasiswaId();
    $mhsJurusanId = (int)($mhsData['jurusan_id'] ?? 0);
    if (!$mhsJurusanId && $mhsId) {
        $stmtM = $pdo->prepare("SELECT jurusan_id FROM mahasiswa WHERE id = ?");
        $stmtM->execute([$mhsId]);
        $mhsJurusanId = (int)$stmtM->fetchColumn();
    }

    if (!empty($periodeData['angkatan_eligible'])) {
        $mhsAngkatan = (int)($mhsData['angkatan'] ?? 0);
        $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;
        $eligibleList = array_map('trim', explode(',', $periodeData['angkatan_eligible']));
        if (!in_array((string)$fullAngkatan, $eligibleList, true) && !in_array((string)$mhsAngkatan, $eligibleList, true)) {
            http_response_code(403);
            echo json_encode(['error' => "Pendaftaran periode {$periodeData['nama']} dikhususkan untuk Angkatan " . implode(', ', $eligibleList) . "."]);
            exit;
        }
    }

    // Siapkan klausa IN untuk peminatan
    $inClause = '0';
    if (!empty($peminatanIds)) {
        $inClause = implode(',', $peminatanIds);
    }

    // 1. Hitung Bounding Box (radius awal 300 km untuk filter indeks kasar)
    // 1 derajat lat ~ 111 km. 1 derajat lng ~ 111 * cos(lat) km.
    $radiusKm = 300.0;
    $latDelta = $radiusKm / 111.0;
    $cosLat = cos(deg2rad($lat));
    $lngDelta = $radiusKm / (111.0 * max(0.1, abs($cosLat)));

    $latMin = $lat - $latDelta;
    $latMax = $lat + $latDelta;
    $lngMin = $lng - $lngDelta;
    $lngMax = $lng + $lngDelta;

    // Helper function query dengan filter prodi
    $runQuery = function(bool $useBoundingBox) use ($pdo, $lat, $lng, $periodeId, $inClause, $mhsJurusanId, $latMin, $latMax, $lngMin, $lngMax) {
        $bboxWhere = $useBoundingBox ? "AND (e.latitude BETWEEN :latMin AND :latMax) AND (e.longitude BETWEEN :lngMin AND :lngMax)" : "";
        
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
                        least(1.0, greatest(-1.0,
                            cos(radians(:lat1)) * cos(radians(IFNULL(e.latitude, 0))) * 
                            cos(radians(IFNULL(e.longitude, 0)) - radians(:lng)) + 
                            sin(radians(:lat2)) * sin(radians(IFNULL(e.latitude, 0)))
                        ))
                    )
                ) AS jarak,
                (
                    CASE 
                        WHEN EXISTS (SELECT 1 FROM unit_periode_peminatan uppem WHERE uppem.unit_pelaksana_periode_id = upp.id) THEN
                            (SELECT COUNT(*) FROM unit_periode_peminatan uppem2 WHERE uppem2.unit_pelaksana_periode_id = upp.id AND uppem2.peminatan_id IN ($inClause))
                        ELSE
                            (SELECT COUNT(*) FROM unit_peminatan up WHERE up.entitas_id = e.id AND up.peminatan_id IN ($inClause))
                    END
                ) AS kecocokan
            FROM unit_pelaksana_periode upp
            JOIN entitas_perusahaan e ON upp.entitas_id = e.id
            LEFT JOIN entitas_perusahaan parent ON e.parent_id = parent.id
            WHERE upp.periode_id = :periode_id 
              AND upp.aktif = 1 
              AND e.aktif = 1
              AND (
                  -- Unit menerima prodi spesifik mahasiswa
                  EXISTS (
                      SELECT 1 FROM unit_periode_jurusan upj
                      WHERE upj.unit_pelaksana_periode_id = upp.id
                        AND upj.jurusan_id = :mhs_jurusan_id
                  )
                  -- ATAU unit tidak membatasi prodi (menerima semua prodi / All Majors)
                  OR NOT EXISTS (
                      SELECT 1 FROM unit_periode_jurusan upj2
                      WHERE upj2.unit_pelaksana_periode_id = upp.id
                  )
              )
              $bboxWhere
            ORDER BY kecocokan DESC, jarak ASC
            LIMIT 100
        ";

        $params = [
            ':lat1' => $lat,
            ':lat2' => $lat,
            ':lng' => $lng,
            ':periode_id' => $periodeId,
            ':mhs_jurusan_id' => $mhsJurusanId
        ];

        if ($useBoundingBox) {
            $params[':latMin'] = $latMin;
            $params[':latMax'] = $latMax;
            $params[':lngMin'] = $lngMin;
            $params[':lngMax'] = $lngMax;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // Jalankan query dengan bounding box dulu
    $units = $runQuery(true);

    // Jika di dalam radius 300km hasilnya < 5 unit, fallback ke pencarian nasional (tanpa bounding box)
    if (count($units) < 5) {
        $units = $runQuery(false);
    }
    
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
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat rekomendasi unit.')]);
}


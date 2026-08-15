<?php
declare(strict_types=1);

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$endpoint = $_GET['endpoint'] ?? '';
$param = $_GET['param'] ?? '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

$allowedEndpoints = ['provinces', 'regencies', 'districts', 'villages'];

if (!in_array($endpoint, $allowedEndpoints, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Endpoint tidak valid.']);
    exit;
}

// Setup Cache Directory
$cacheDir = dirname(__DIR__) . '/cache/wilayah';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$cacheKey = $endpoint . '_' . ($param ?: 'all') . '_lim' . $limit . '.json';
$cacheFile = $cacheDir . '/' . $cacheKey;
$cacheLifetime = 30 * 86400; // 30 hari

// Cek jika cache valid
if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheLifetime)) {
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedData !== false && strlen($cachedData) > 2) {
        header('X-Cache: HIT');
        echo $cachedData;
        exit;
    }
}

// Build target URL
if ($endpoint === 'provinces') {
    $targetUrl = "https://wilayah.web.id/api/provinces?limit=" . $limit;
} else {
    if (empty($param) || !preg_match('/^[0-9]+$/', $param)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parameter ID tidak valid.']);
        exit;
    }
    $targetUrl = "https://wilayah.web.id/api/{$endpoint}/{$param}?limit=" . $limit;
}

// Fetch from remote API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $targetUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ITPLN-MagangApp/1.0');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // bypass SSL cert check for local dev environments

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    // Jika remote API gagal, coba gunakan cache lama jika ada
    if (is_file($cacheFile)) {
        $staleData = @file_get_contents($cacheFile);
        if ($staleData !== false) {
            header('X-Cache: STALE');
            echo $staleData;
            exit;
        }
    }
    http_response_code(502);
    echo json_encode(['error' => 'Gagal mengambil data dari server wilayah.']);
    exit;
}

// Simpan ke cache jika data valid
@file_put_contents($cacheFile, $response, LOCK_EX);

header('X-Cache: MISS');
echo $response;


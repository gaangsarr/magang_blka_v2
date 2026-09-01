<?php
/**
 * router.php — PHP Built-in Server Router
 *
 * Cara pakai:
 *   php -S 127.0.0.1:8000 router.php
 *
 * Routing rules:
 *   /api/*  → eksekusi file PHP di /api/ (return false = PHP jalankan sendiri)
 *   /*      → serve file dari /public/ dengan MIME type yang tepat
 *             jika tidak ada → fallback ke /public/index.html
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// QUALITY-02: CSP Header hanya untuk PHP built-in server (php -S).
// Saat production di Apache, CSP dihandle oleh .htaccess.
// Kedua header ini TIDAK aktif bersamaan karena router.php diabaikan oleh Apache.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://*.firebaseapp.com https://apis.google.com https://www.gstatic.com; style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.googleapis.com; img-src 'self' data: https://*.tile.openstreetmap.org https://unpkg.com; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://unpkg.com https://cdn.jsdelivr.net https://*.googleapis.com https://*.firebaseio.com https://nominatim.openstreetmap.org https://photon.komoot.io https://wilayah.web.id; frame-src 'self' https://*.firebaseapp.com;");


// ── 1. Route /api/* ───────────────────────────────────────────────────────────
// return false → PHP built-in server eksekusi file PHP dari document root (v1/)
// File api/ memang ada di root project, jadi ini bekerja dengan benar.
if (str_starts_with($uri, '/api/')) {
    $file = __DIR__ . $uri;

    if (is_file($file)) {
        return false; // biarkan PHP eksekusi
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'API endpoint tidak ditemukan.']);
    return true;
}

// ── 2. Serve file statis dari /public/ ───────────────────────────────────────
// PENTING: return false tidak bisa dipakai di sini karena built-in server
// mencari file dari root (v1/), bukan dari v1/public/.
// Solusi: baca file manual dengan readfile() + header MIME yang tepat.
$publicPath = __DIR__ . '/public' . $uri;

if (is_dir($publicPath)) {
    $dirIndex = rtrim($publicPath, '/') . '/index.html';
    if (is_file($dirIndex)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($dirIndex);
        return true;
    }
}

if (is_file($publicPath)) {
    $ext = strtolower(pathinfo($publicPath, PATHINFO_EXTENSION));

    $mimes = [
        'html'  => 'text/html; charset=utf-8',
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'mjs'   => 'application/javascript; charset=utf-8',
        'json'  => 'application/json; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'pdf'   => 'application/pdf',
        'txt'   => 'text/plain; charset=utf-8',
        'xml'   => 'application/xml; charset=utf-8',
    ];

    $mime = $mimes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($publicPath);
    return true;
}

// ── 3. Fallback → /public/index.html ─────────────────────────────────────────
$indexFile = __DIR__ . '/public/index.html';

if (is_file($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    return true;
}

http_response_code(404);
echo '404 Not Found';
return true;

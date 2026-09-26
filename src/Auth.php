<?php

namespace App;

use PDO;

/**
 * Auth Helper
 *
 * Mengelola session mahasiswa dan admin secara terpisah.
 * Semua session di-store di database (tabel sessions) — lebih aman dari file PHP default.
 *
 * Penggunaan:
 *   Auth::startSession();               // panggil di awal setiap request
 *   Auth::requireMahasiswa();           // redirect ke login.html jika tidak login
 *   Auth::requireAdmin();               // redirect ke /admin/login.html jika bukan admin
 *   $mahasiswa = Auth::getMahasiswa();  // array row tabel mahasiswa, atau null
 *   Auth::setMahasiswaSession($id);     // setelah verify Firebase token
 *   Auth::logout();                     // hapus session
 */
class Auth
{
    private const SESSION_LIFETIME = 86400; // 24 jam (detik)
    private const COOKIE_NAME      = 'magang_sess';

    // ============================================================
    // SESSION BOOTSTRAP
    // ============================================================

    /**
     * Inisialisasi session.
     * Panggil di awal setiap request API atau halaman yang butuh auth.
     *
     * @param bool $startPHP  Jika true, panggil session_start() untuk session PHP native.
     *                        Untuk API JSON-only, set false dan gunakan cookie manual.
     */
    public static function startSession(bool $startPHP = false): void
    {
        if ($startPHP) {
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => self::SESSION_LIFETIME,
                    'path'     => '/',
                    'secure'   => ($_ENV['APP_ENV'] ?? 'local') !== 'local',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                session_start();
            }

            // QUALITY-03: Invalidasi session yang sudah expired
            // Hanya invalidasi jika created_at sudah ada (safe untuk session lama)
            if (isset($_SESSION['created_at']) && (time() - $_SESSION['created_at']) > self::SESSION_LIFETIME) {
                self::logout();
                return;
            }
            // Pastikan created_at selalu ada untuk session baru
            if (!isset($_SESSION['created_at']) && isset($_SESSION['role'])) {
                $_SESSION['created_at'] = time();
            }
        }
    }

    // ============================================================
    // SET SESSION (setelah login berhasil)
    // ============================================================

    /**
     * Simpan mahasiswa_id ke session PHP + set cookie.
     * Dipanggil dari api/auth/verify.php setelah upsert berhasil.
     */
    public static function setMahasiswaSession(int $mahasiswaId): void
    {
        self::startSession(startPHP: true);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true); // QUALITY-07: Cegah Session Fixation Attack
        }
        unset(
            $_SESSION['admin_id'],
            $_SESSION['admin_nama'],
            $_SESSION['admin_role'],
            $_SESSION['entitas_id'],
            $_SESSION['force_password_change']
        );
        $_SESSION['mahasiswa_id'] = $mahasiswaId;
        $_SESSION['role']         = 'mahasiswa';
        $_SESSION['created_at']   = time();
        // PERF-02: Hapus cache angkatan lama jika ada (akan di-refresh dari DB)
        unset($_SESSION['mhs_angkatan']);
    }

    /**
     * Simpan admin_id ke session PHP (terpisah dari session mahasiswa).
     */
    public static function setAdminSession(
        int $adminId, 
        string $nama, 
        string $adminRole = 'admin_blka', 
        ?int $entitasId = null, 
        bool $forcePasswordChange = false
    ): void
    {
        self::startSession(startPHP: true);
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true); // QUALITY-07: Cegah Session Fixation Attack
        }
        unset($_SESSION['mahasiswa_id'], $_SESSION['mhs_angkatan']);
        $_SESSION['admin_id']              = $adminId;
        $_SESSION['admin_nama']            = $nama;
        $_SESSION['admin_role']            = $adminRole;
        $_SESSION['entitas_id']            = $entitasId;
        $_SESSION['force_password_change'] = $forcePasswordChange;
        $_SESSION['role']                  = 'admin';
        $_SESSION['created_at']            = time();
    }

    // ============================================================
    // AUTH GUARDS
    // ============================================================

    /**
     * Cek apakah request datang dari mahasiswa yang sedang login.
     * Jika tidak → kirim JSON 401 dan exit.
     * Cocok untuk endpoint API.
     */
    public static function requireMahasiswaApi(): void
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInMahasiswa()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Silakan login terlebih dahulu.']);
            exit;
        }
    }

    /**
     * Cek apakah request datang dari admin yang sedang login.
     * Jika tidak → kirim JSON 401 dan exit.
     */
    public static function requireAdminApi(): void
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInAdmin()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Admin access required.']);
            exit;
        }
    }

    /**
     * Cek apakah request datang dari Admin Perusahaan (atau Super Admin yang sedang mengelola).
     */
    public static function requirePerusahaanApi(): void
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInAdmin()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Silakan login terlebih dahulu.']);
            exit;
        }

        $admin = self::getAdmin();
        if (!$admin || ($admin['role'] !== 'admin_perusahaan' && $admin['role'] !== 'super_admin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Akses ditolak. Endpoint ini khusus Admin Perusahaan.']);
            exit;
        }

        if ($admin['role'] === 'admin_perusahaan' && empty($admin['entitas_id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Akun admin perusahaan belum terhubung ke entitas unit manapun.']);
            exit;
        }
    }

    /**
     * Cek apakah request datang dari Super Admin.
     * Jika tidak → kirim JSON 403 dan exit.
     */
    public static function requireSuperAdminApi(): void
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInAdmin()) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Admin access required.']);
            exit;
        }

        $admin = self::getAdmin();
        if (!$admin || ($admin['role'] !== 'super_admin' && $admin['role'] !== 'superadmin')) {
            http_response_code(403);
            echo json_encode(['error' => 'Akses ditolak. Fitur ini hanya untuk Super Admin.']);
            exit;
        }
    }

    // ============================================================
    // CURRENT USER GETTERS
    // ============================================================

    /**
     * Ambil data mahasiswa yang sedang login dari database.
     * Return null jika tidak login.
     */
    public static function getMahasiswa(): ?array
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInMahasiswa()) {
            return null;
        }

        $id  = (int) $_SESSION['mahasiswa_id'];
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare(
            'SELECT m.*, j.nama_jurusan AS jurusan_nama
             FROM mahasiswa m
             LEFT JOIN jurusan j ON m.jurusan_id = j.id
             WHERE m.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // PERF-02: Cache angkatan ke session agar request berikutnya tidak perlu query DB
        if ($row && isset($row['angkatan'])) {
            $_SESSION['mhs_angkatan'] = (int)$row['angkatan'];
        }

        return $row ?: null;
    }

    /**
     * Ambil data admin yang sedang login dari database.
     * Return null jika tidak login.
     */
    public static function getAdmin(): ?array
    {
        self::startSession(startPHP: true);

        if (!self::isLoggedInAdmin()) {
            return null;
        }

        $id  = (int) $_SESSION['admin_id'];
        $pdo = Database::getInstance();

        $stmt = $pdo->prepare('SELECT id, nama, email, username, role, mahasiswa_id, entitas_id, force_password_change, aktif FROM admin WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Shorthand: ambil entitas_id admin perusahaan dari session/DB.
     */
    public static function getPerusahaanEntitasId(): ?int
    {
        self::startSession(startPHP: true);
        if (isset($_SESSION['entitas_id']) && (int)$_SESSION['entitas_id'] > 0) {
            return (int)$_SESSION['entitas_id'];
        }
        $admin = self::getAdmin();
        return !empty($admin['entitas_id']) ? (int)$admin['entitas_id'] : null;
    }

    /**
     * Shorthand: cek apakah admin perusahaan wajib ganti password.
     */
    public static function isForcePasswordChange(): bool
    {
        self::startSession(startPHP: true);
        if (isset($_SESSION['force_password_change'])) {
            return (bool)$_SESSION['force_password_change'];
        }
        $admin = self::getAdmin();
        return !empty($admin['force_password_change']);
    }

    /**
     * Shorthand: ambil mahasiswa_id dari session.
     * Return 0 jika tidak login.
     */
    public static function getMahasiswaId(): int
    {
        self::startSession(startPHP: true);
        return (int) ($_SESSION['mahasiswa_id'] ?? 0);
    }

    /**
     * Shorthand: ambil admin_id dari session.
     */
    public static function getAdminId(): int
    {
        self::startSession(startPHP: true);
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    /**
     * Shorthand: ambil nama admin dari session atau database.
     */
    public static function getAdminNama(): string
    {
        self::startSession(startPHP: true);
        if (!empty($_SESSION['admin_nama'])) {
            return (string) $_SESSION['admin_nama'];
        }
        $admin = self::getAdmin();
        return $admin['nama'] ?? 'Administrator';
    }

    /**
     * Shorthand: ambil role admin dari session atau database.
     */
    public static function getAdminRole(): string
    {
        self::startSession(startPHP: true);
        if (!empty($_SESSION['admin_role'])) {
            return (string) $_SESSION['admin_role'];
        }
        $admin = self::getAdmin();
        return $admin['role'] ?? 'admin_blka';
    }

    // ============================================================
    // SESSION STATUS CHECKS
    // ============================================================

    public static function isLoggedInMahasiswa(): bool
    {
        self::startSession(startPHP: true);
        return isset($_SESSION['mahasiswa_id'], $_SESSION['role'])
            && $_SESSION['role'] === 'mahasiswa'
            && (int) $_SESSION['mahasiswa_id'] > 0;
    }

    public static function isLoggedInAdmin(): bool
    {
        self::startSession(startPHP: true);
        return isset($_SESSION['admin_id'], $_SESSION['role'])
            && $_SESSION['role'] === 'admin'
            && (int) $_SESSION['admin_id'] > 0;
    }

    // ============================================================
    // LOGOUT
    // ============================================================

    /**
     * Hapus session (mahasiswa maupun admin).
     */
    public static function logout(): void
    {
        self::startSession(startPHP: true);
        $_SESSION = [];
        session_destroy();

        // Hapus cookie di browser
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
    }

    // ============================================================
    // CSRF PROTECTION
    // ============================================================

    /**
     * Generate or retrieve current CSRF token.
     */
    public static function generateCsrfToken(): string
    {
        self::startSession(startPHP: true);
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token from header X-CSRF-Token or POST body.
     * Returns true if valid, false otherwise.
     */
    public static function validateCsrfToken(): bool
    {
        self::startSession(startPHP: true);
        $clientToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        
        if (empty($clientToken) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $clientToken);
    }

    /**
     * Enforce CSRF token check. If invalid, throw 403 and exit.
     */
    public static function requireCsrfApi(): void
    {
        if (!self::validateCsrfToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token.']);
            exit;
        }
    }

    // ============================================================
    // RATE LIMITING & IP TRACKING
    // ============================================================

    /**
     * Dapatkan IP address client yang valid.
     */
    public static function getClientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',  // Load balancer / Reverse proxy
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Membatasi jumlah request berbasis IP Address (disimpan di tabel rate_limits).
     * Lebih aman daripada session-based karena tidak bisa di-bypass dengan menghapus cookie.
     *
     * @param string $action            Nama aksi (mis: 'login_admin', 'verify_mahasiswa')
     * @param int    $maxRequests       Batas maksimal request dalam jendela waktu
     * @param int    $timeWindowSeconds Jendela waktu (dalam detik)
     */
    public static function rateLimitByIp(string $action, int $maxRequests = 10, int $timeWindowSeconds = 60): void
    {
        $ip = self::getClientIp();
        $pdo = Database::getInstance();

        // 1. Cek jumlah percobaan dalam jendela waktu
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM rate_limits 
            WHERE ip_address = :ip 
              AND action = :action 
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
        ");
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':window', $timeWindowSeconds, PDO::PARAM_INT);
        $stmt->execute();

        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= $maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $timeWindowSeconds);
            echo json_encode([
                'error' => "Terlalu banyak permintaan untuk aksi '{$action}'. Silakan tunggu beberapa saat."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Catat percobaan ini
        $stmtIns = $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempted_at) VALUES (:ip, :action, NOW())");
        $stmtIns->execute([':ip' => $ip, ':action' => $action]);

        // 3. Bersihkan log lama secara probabilistik (1% chance per request)
        if (random_int(1, 100) === 1) {
            $pdo->query("DELETE FROM rate_limits WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
        }
    }

    /**
     * Membatasi jumlah request berbasis identitas pengguna (email/username).
     *
     * Melengkapi rateLimitByIp() untuk skenario di mana banyak user sah
     * berbagi IP yang sama (WiFi kampus, kantor PLN di belakang NAT/proxy).
     *
     * Strategi hybrid yang direkomendasikan:
     *   1. rateLimitByIp()       → limit longgar (mis. 100/menit) — cegah DDoS dari 1 IP
     *   2. rateLimitByIdentity() → limit ketat  (mis. 5/menit)   — cegah brute-force 1 akun
     *
     * Data disimpan di tabel `rate_limits` yang sama, dengan prefix "id:"
     * di kolom ip_address agar tidak tabrakan dengan data IP.
     *
     * @param string $identity           Identifier unik user (email, username, dsb.)
     * @param string $action             Nama aksi (mis: 'verify_mahasiswa', 'login_admin')
     * @param int    $maxRequests        Batas maksimal request dalam jendela waktu
     * @param int    $timeWindowSeconds  Jendela waktu (dalam detik)
     */
    public static function rateLimitByIdentity(string $identity, string $action, int $maxRequests = 5, int $timeWindowSeconds = 60): void
    {
        if ($identity === '') {
            return; // Tidak bisa rate-limit tanpa identifier
        }

        $pdo = Database::getInstance();

        // Gunakan prefix "id:" + hash agar panjang kolom aman dan tidak bocorkan email/username
        $key = 'id:' . hash('xxh3', strtolower(trim($identity)));

        // 1. Cek jumlah percobaan dalam jendela waktu
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM rate_limits 
            WHERE ip_address = :key 
              AND action = :action 
              AND attempted_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
        ");
        $stmt->bindValue(':key', $key);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':window', $timeWindowSeconds, PDO::PARAM_INT);
        $stmt->execute();

        $attempts = (int) $stmt->fetchColumn();

        if ($attempts >= $maxRequests) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $timeWindowSeconds);
            echo json_encode([
                'error' => 'Terlalu banyak percobaan untuk akun ini. Silakan tunggu beberapa saat.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2. Catat percobaan ini
        $stmtIns = $pdo->prepare("INSERT INTO rate_limits (ip_address, action, attempted_at) VALUES (:key, :action, NOW())");
        $stmtIns->execute([':key' => $key, ':action' => $action]);
    }

    /**
     * Sanitasi error message agar tidak membocorkan detail query/tabel di production.
     */
    public static function safeErrorMessage(\Throwable $e, string $genericMsg = 'Terjadi kesalahan sistem.'): string
    {
        $debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
        if ($debug) {
            return $genericMsg . ' [DEBUG: ' . $e->getMessage() . ']';
        }
        return $genericMsg;
    }

    /**
     * Membatasi jumlah request berbasis session (fallback).
     */
    public static function rateLimit(string $action, int $maxRequests = 5, int $timeWindowSeconds = 60): void
    {
        self::startSession(startPHP: true);
        
        $now = time();
        $sessionKey = 'rl_' . $action;

        if (!isset($_SESSION[$sessionKey])) {
            $_SESSION[$sessionKey] = [];
        }

        // Hapus history yang lebih tua dari timeWindow
        $_SESSION[$sessionKey] = array_filter($_SESSION[$sessionKey], fn($timestamp) => $now - $timestamp < $timeWindowSeconds);

        if (count($_SESSION[$sessionKey]) >= $maxRequests) {
            http_response_code(429);
            echo json_encode(['error' => 'Terlalu banyak permintaan. Silakan coba beberapa saat lagi.']);
            exit;
        }

        // Catat waktu request ini
        $_SESSION[$sessionKey][] = $now;
    }

    // ============================================================
    // UTILITY: parse NIM dari email ITPLN
    // ============================================================

    /**
     * Parse NIM, angkatan, kode jurusan dari email ITPLN.
     *
     * Format email: {nama}{AA}{BB}{CCC}@itpln.ac.id atau berakhiran 7 digit (AABBCCC) / 9 digit (20AABBCCC)
     *   AA  = 2 digit angkatan (mis. 24 → 2024)
     *   BB  = 2 digit kode jurusan (mis. 12 → Teknik Mesin, 31 → Teknik Informatika)
     *   CCC = 3 digit nomor urut
     *
     * Contoh:
     *   gangsar2431170@itpln.ac.id   → NIM: 202431170, Angkatan: 2024
     *   jauza'2412031@itpln.ac.id    → NIM: 202412031, Angkatan: 2024
     *   2412031@itpln.ac.id          → NIM: 202412031, Angkatan: 2024
     *
     * Return null jika format tidak cocok (mis. akun dosen/staf seperti blka@itpln.ac.id atau nama@itpln.ac.id).
     *
     * @return array{nim:string, angkatan:int, kode_jurusan:string, no_urut_absen:int}|null
     */
    public static function parseNimFromEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        // Normalisasi tanda petik kurva/backtick ke petik lurus standar
        $email = str_replace(['’', '‘', '`'], "'", $email);

        // Cek domain
        if (!str_ends_with($email, '@itpln.ac.id')) {
            return null;
        }

        $prefix = explode('@', $email)[0];

        // Ekstrak 7 digit (AABBCCC) atau 9 digit (20AABBCCC) di akhir prefix email.
        // Karakter nama di awal bebas (huruf, tanda petik ', titik ., strip -, dll).
        if (!preg_match('/(?:20)?(\d{2})(\d{2})(\d{3})$/', $prefix, $m)) {
            return null;
        }

        // Pastikan tidak ada digit berlebih tepat di depan digit NIM yang tertangkap
        // (contoh negatif: nama1234567890 bukan NIM mahasiswa valid)
        $matchedDigits = $m[0];
        $pos = strrpos($prefix, $matchedDigits);
        if ($pos > 0 && ctype_digit($prefix[$pos - 1])) {
            return null;
        }

        $aa  = $m[1];
        $bb  = $m[2];
        $ccc = $m[3];

        $nim          = '20' . $aa . $bb . $ccc; // 9 digit standar: 20AABBCCC
        $angkatan     = (int)('20' . $aa);       // mis. 24 → 2024
        $kode_jurusan = $bb;                     // 2 digit string kode jurusan
        $no_urut_absen= (int)$ccc;               // 3 digit nomor urut

        return [
            'nim'           => $nim,
            'angkatan'      => $angkatan,
            'kode_jurusan'  => $kode_jurusan,
            'no_urut_absen' => $no_urut_absen,
        ];
    }
}

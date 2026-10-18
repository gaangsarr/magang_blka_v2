<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\ProdiCredentialsHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireSuperAdminApi();

$pdo = Database::getInstance();
$action = $_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'list' : '');

try {
    // -------------------------------------------------------------
    // 1. LIST AKUN PRODI
    // -------------------------------------------------------------
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT 
                j.id AS jurusan_id,
                j.kode,
                j.jenjang,
                j.nama_jurusan,
                j.aktif AS jurusan_aktif,
                a.id AS admin_id,
                a.username,
                a.email AS admin_email,
                a.nama AS admin_nama,
                a.force_password_change,
                a.aktif AS admin_aktif,
                a.created_at AS admin_created_at,
                a.updated_at AS admin_updated_at,
                CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS has_account,
                (
                    SELECT COUNT(p.id) 
                    FROM pendaftaran p
                    JOIN mahasiswa m ON p.mahasiswa_id = m.id
                    WHERE m.jurusan_id = j.id
                ) AS total_pendaftar,
                (
                    SELECT COUNT(p.id) 
                    FROM pendaftaran p
                    JOIN mahasiswa m ON p.mahasiswa_id = m.id
                    WHERE m.jurusan_id = j.id AND p.status = 'diterima'
                ) AS total_diterima
            FROM jurusan j
            LEFT JOIN admin a ON a.jurusan_id = j.id AND a.role = 'admin_prodi'
            ORDER BY j.id ASC
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalProdi = count($data);
        $totalHasAccount = 0;
        $totalAktif = 0;

        foreach ($data as &$row) {
            $row['jurusan_id']           = (int)$row['jurusan_id'];
            $row['admin_id']             = !empty($row['admin_id']) ? (int)$row['admin_id'] : null;
            $row['has_account']          = (bool)$row['has_account'];
            $row['jurusan_aktif']        = (bool)$row['jurusan_aktif'];
            $row['admin_aktif']          = !empty($row['admin_aktif']) ? (bool)$row['admin_aktif'] : false;
            $row['force_password_change']= !empty($row['force_password_change']) ? (bool)$row['force_password_change'] : false;
            $row['total_pendaftar']      = (int)$row['total_pendaftar'];
            $row['total_diterima']       = (int)$row['total_diterima'];

            // Template default
            $row['default_username']     = ProdiCredentialsHelper::generateDefaultUsername((string)$row['nama_jurusan'], (string)$row['jenjang']);
            $row['default_password']     = ProdiCredentialsHelper::generateDefaultPassword((string)$row['nama_jurusan'], (string)$row['kode'], (string)$row['jenjang']);

            if ($row['has_account']) {
                $totalHasAccount++;
                if ($row['admin_aktif']) {
                    $totalAktif++;
                }
            }
        }
        unset($row);

        echo json_encode([
            'ok'    => true,
            'stats' => [
                'total_prodi'       => $totalProdi,
                'total_has_account' => $totalHasAccount,
                'total_no_account'  => $totalProdi - $totalHasAccount,
                'total_aktif'       => $totalAktif,
            ],
            'data'  => $data
        ]);
        exit;
    }

    // Mutating actions require CSRF validation
    Auth::requireCsrfApi();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // -------------------------------------------------------------
    // 2. GENERATE SEMUA AKUN PRODI
    // -------------------------------------------------------------
    if ($action === 'generate_all') {
        $stmtUnassigned = $pdo->query("
            SELECT j.id, j.kode, j.jenjang, j.nama_jurusan
            FROM jurusan j
            LEFT JOIN admin a ON a.jurusan_id = j.id AND a.role = 'admin_prodi'
            WHERE a.id IS NULL AND j.aktif = 1
            ORDER BY j.id ASC
        ");
        $prodis = $stmtUnassigned->fetchAll(PDO::FETCH_ASSOC);

        if (empty($prodis)) {
            echo json_encode([
                'ok'      => true,
                'message' => 'Semua Program Studi sudah memiliki akun Admin Prodi.',
                'created' => 0,
                'results' => []
            ]);
            exit;
        }

        $createdAccounts = [];
        $stmtInsert = $pdo->prepare("
            INSERT INTO admin (nama, username, password_hash, role, jurusan_id, force_password_change, aktif, created_at, updated_at)
            VALUES (:nama, :username, :password_hash, 'admin_prodi', :jurusan_id, 1, 1, NOW(), NOW())
        ");

        $superAdmin = Auth::getAdmin();
        $adminId = $superAdmin ? (int)$superAdmin['id'] : null;

        foreach ($prodis as $p) {
            $jId      = (int)$p['id'];
            $nama     = 'Admin ' . $p['jenjang'] . ' ' . $p['nama_jurusan'];
            $username = ProdiCredentialsHelper::generateDefaultUsername((string)$p['nama_jurusan'], (string)$p['jenjang']);
            $plainPass= ProdiCredentialsHelper::generateDefaultPassword((string)$p['nama_jurusan'], (string)$p['kode'], (string)$p['jenjang']);
            $hash     = password_hash($plainPass, PASSWORD_BCRYPT);

            // Cek apakah username sudah dipakai
            $stmtCek = $pdo->prepare("SELECT id FROM admin WHERE username = :u LIMIT 1");
            $stmtCek->execute([':u' => $username]);
            if ($stmtCek->fetch()) {
                // Tambahkan kode jika bentrok
                $username .= $p['kode'];
            }

            $stmtInsert->execute([
                ':nama'          => $nama,
                ':username'      => $username,
                ':password_hash' => $hash,
                ':jurusan_id'    => $jId
            ]);

            $newAdminId = (int)$pdo->lastInsertId();

            $createdAccounts[] = [
                'admin_id'     => $newAdminId,
                'jurusan_id'   => $jId,
                'prodi_nama'   => $p['jenjang'] . ' ' . $p['nama_jurusan'],
                'kode'         => $p['kode'],
                'username'     => $username,
                'password'     => $plainPass,
            ];
        }

        // Log Aktivitas
        if ($adminId) {
            $stmtLog = $pdo->prepare("
                INSERT INTO log_aktivitas (admin_id, aksi, entitas_tipe, entitas_id, detail_json, ip_address, created_at)
                VALUES (:admin_id, 'generate_all_akun_prodi', 'admin', NULL, :detail, :ip, NOW())
            ");
            $stmtLog->execute([
                ':admin_id' => $adminId,
                ':detail'   => json_encode(['total_dibuat' => count($createdAccounts), 'daftar' => $createdAccounts]),
                ':ip'       => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Berhasil membuat ' . count($createdAccounts) . ' akun Admin Prodi.',
            'created' => count($createdAccounts),
            'results' => $createdAccounts
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 3. RESET PASSWORD AKUN PRODI
    // -------------------------------------------------------------
    if ($action === 'reset_password') {
        $jurusanId = (int)($input['jurusan_id'] ?? 0);
        $adminId   = (int)($input['admin_id'] ?? 0);
        $mode      = $input['mode'] ?? 'default'; // 'default' atau 'custom'
        $customPass= trim((string)($input['new_password'] ?? ''));

        // Cari admin
        if ($adminId > 0) {
            $stmt = $pdo->prepare("SELECT a.id, a.username, a.jurusan_id, j.nama_jurusan, j.kode, j.jenjang FROM admin a JOIN jurusan j ON a.jurusan_id = j.id WHERE a.id = :id AND a.role = 'admin_prodi' LIMIT 1");
            $stmt->execute([':id' => $adminId]);
        } else {
            $stmt = $pdo->prepare("SELECT a.id, a.username, a.jurusan_id, j.nama_jurusan, j.kode, j.jenjang FROM admin a JOIN jurusan j ON a.jurusan_id = j.id WHERE a.jurusan_id = :jid AND a.role = 'admin_prodi' LIMIT 1");
            $stmt->execute([':jid' => $jurusanId]);
        }
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            http_response_code(404);
            echo json_encode(['error' => 'Akun Admin Prodi tidak ditemukan.']);
            exit;
        }

        if ($mode === 'custom') {
            if (strlen($customPass) < 4) {
                http_response_code(400);
                echo json_encode(['error' => 'Password kustom minimal 4 karakter.']);
                exit;
            }
            $plainPass = $customPass;
        } else {
            $plainPass = ProdiCredentialsHelper::generateDefaultPassword(
                (string)$account['nama_jurusan'], 
                (string)$account['kode'], 
                (string)$account['jenjang']
            );
        }

        $forceFlag = ($mode === 'default') ? 1 : 0;
        $hash = password_hash($plainPass, PASSWORD_BCRYPT);
        $stmtUpd = $pdo->prepare("
            UPDATE admin 
            SET password_hash = :hash, force_password_change = :force, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':hash'  => $hash,
            ':force' => $forceFlag,
            ':id'    => (int)$account['id']
        ]);

        echo json_encode([
            'ok'           => true,
            'message'      => 'Password akun prodi berhasil direset.',
            'username'     => $account['username'],
            'new_password' => $plainPass,
            'prodi_nama'   => $account['jenjang'] . ' ' . $account['nama_jurusan']
        ]);
        exit;
    }

    // -------------------------------------------------------------
    // 4. TOGGLE STATUS AKUN PRODI (AKTIF / NONAKTIF)
    // -------------------------------------------------------------
    if ($action === 'toggle_status') {
        $adminId = (int)($input['admin_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, aktif, username FROM admin WHERE id = :id AND role = 'admin_prodi' LIMIT 1");
        $stmt->execute([':id' => $adminId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Akun Admin Prodi tidak ditemukan.']);
            exit;
        }

        $newStatus = (int)!((bool)$row['aktif']);
        $stmtUpd = $pdo->prepare("UPDATE admin SET aktif = :status, updated_at = NOW() WHERE id = :id");
        $stmtUpd->execute([':status' => $newStatus, ':id' => $adminId]);

        echo json_encode([
            'ok'        => true,
            'message'   => 'Status akun prodi ' . $row['username'] . ' berhasil diubah menjadi ' . ($newStatus ? 'Aktif' : 'Nonaktif') . '.',
            'new_status'=> (bool)$newStatus
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Aksi tidak valid.']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan sistem saat memproses data akun prodi.')]);
}

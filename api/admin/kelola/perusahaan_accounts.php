<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requireSuperAdminApi();

$pdo = Database::getInstance();
$action = $_GET['action'] ?? ($_SERVER['REQUEST_METHOD'] === 'GET' ? 'list' : '');

try {
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT 
                ep.id AS entitas_id,
                ep.nama AS entitas_nama,
                ep.singkatan,
                ep.tipe,
                ep.alamat,
                ep.menerima_magang,
                ep.aktif AS entitas_aktif,
                ep.pic_nama,
                ep.pic_jabatan,
                ep.pic_kontak,
                ep.pic_email,
                parent.nama AS parent_nama,
                a.id AS admin_id,
                a.username,
                a.email AS admin_email,
                a.force_password_change,
                a.aktif AS admin_aktif,
                a.created_at AS admin_created_at,
                a.updated_at AS admin_updated_at,
                CASE WHEN a.id IS NOT NULL THEN 1 ELSE 0 END AS has_account
            FROM entitas_perusahaan ep
            LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
            LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
            WHERE ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding')
            ORDER BY ep.parent_id ASC, ep.tipe ASC, ep.nama ASC
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalEntities = count($data);
        $totalHasAccount = 0;
        $totalNeedReset = 0;

        foreach ($data as &$row) {
            $row['entitas_id'] = (int)$row['entitas_id'];
            $row['admin_id'] = !empty($row['admin_id']) ? (int)$row['admin_id'] : null;
            $row['has_account'] = (bool)$row['has_account'];
            $row['menerima_magang'] = (bool)$row['menerima_magang'];
            $row['entitas_aktif'] = (bool)$row['entitas_aktif'];
            $row['admin_aktif'] = !empty($row['admin_aktif']) ? (bool)$row['admin_aktif'] : false;
            $row['force_password_change'] = !empty($row['force_password_change']) ? (bool)$row['force_password_change'] : false;

            if ($row['has_account']) {
                $totalHasAccount++;
                if ($row['force_password_change']) {
                    $totalNeedReset++;
                }
            }
        }
        unset($row);

        echo json_encode([
            'ok' => true,
            'stats' => [
                'total_entities'     => $totalEntities,
                'total_has_account'  => $totalHasAccount,
                'total_need_reset'   => $totalNeedReset,
                'total_no_account'   => $totalEntities - $totalHasAccount,
            ],
            'data' => $data
        ]);
        exit;
    }

    if ($action === 'count_unassigned') {
        $stmtCount = $pdo->query("
            SELECT COUNT(*) 
            FROM entitas_perusahaan ep
            LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
            WHERE a.id IS NULL AND ep.aktif = 1 AND ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding')
        ");
        $totalUnassigned = (int)$stmtCount->fetchColumn();

        echo json_encode([
            'ok'               => true,
            'total_unassigned' => $totalUnassigned
        ]);
        exit;
    }

    // Mutating actions require CSRF validation
    Auth::requireCsrfApi();

    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($action === 'generate_batch') {
        $limit = max(10, min(200, (int)($input['limit'] ?? 50)));

        $stmtUnassigned = $pdo->prepare("
            SELECT ep.id, ep.nama, ep.singkatan, ep.tipe
            FROM entitas_perusahaan ep
            LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
            WHERE a.id IS NULL AND ep.aktif = 1 AND ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding')
            ORDER BY ep.id ASC
            LIMIT :lim
        ");
        $stmtUnassigned->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmtUnassigned->execute();
        $unassigned = $stmtUnassigned->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unassigned)) {
            echo json_encode([
                'ok'                 => true,
                'batch_count'        => 0,
                'remaining_count'    => 0,
                'generated_accounts' => [],
                'message'            => 'Semua akun telah berhasil dibuat.'
            ]);
            exit;
        }

        $generatedAccounts = [];
        $stmtInsert = $pdo->prepare("
            INSERT INTO admin (nama, username, email, password_hash, role, entitas_id, force_password_change, aktif, created_at, updated_at)
            VALUES (:nama, :username, :email, :password_hash, 'admin_perusahaan', :entitas_id, 1, 1, NOW(), NOW())
        ");

        $pdo->beginTransaction();

        foreach ($unassigned as $unit) {
            $unitId = (int)$unit['id'];
            $singkatan = !empty($unit['singkatan']) ? trim($unit['singkatan']) : trim($unit['nama']);
            
            $baseUsername = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $singkatan));
            if ($baseUsername === '') {
                $baseUsername = 'unit' . $unitId;
            }

            $username = $baseUsername;
            $counter = 1;
            while (true) {
                $stmtCheck = $pdo->prepare("SELECT id FROM admin WHERE username = :u LIMIT 1");
                $stmtCheck->execute([':u' => $username]);
                if (!$stmtCheck->fetch()) {
                    break;
                }
                $counter++;
                $username = $baseUsername . $counter;
            }

            $tempPassword = 'PLN-' . strtoupper(substr($baseUsername, 0, 8)) . '-2026';
            $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

            $adminNama = 'Admin ' . $unit['nama'];
            $defaultEmail = $username . '@mitra.magang';

            $stmtInsert->execute([
                ':nama'          => $adminNama,
                ':username'      => $username,
                ':email'         => $defaultEmail,
                ':password_hash' => $passwordHash,
                ':entitas_id'    => $unitId,
            ]);

            $generatedAccounts[] = [
                'entitas_id'    => $unitId,
                'entitas_nama'  => $unit['nama'],
                'username'      => $username,
                'temp_password' => $tempPassword,
            ];
        }

        $pdo->commit();

        // Hitung sisa
        $stmtRem = $pdo->query("
            SELECT COUNT(*) 
            FROM entitas_perusahaan ep
            LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
            WHERE a.id IS NULL AND ep.aktif = 1 AND ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding')
        ");
        $remainingCount = (int)$stmtRem->fetchColumn();

        echo json_encode([
            'ok'                 => true,
            'batch_count'        => count($generatedAccounts),
            'remaining_count'    => $remainingCount,
            'generated_accounts' => $generatedAccounts,
            'last_unit_nama'     => end($generatedAccounts)['entitas_nama'] ?? ''
        ]);
        exit;
    }

    if ($action === 'generate_all') {
        // Cari semua entitas yang belum memiliki akun admin_perusahaan
        $stmtUnassigned = $pdo->query("
            SELECT ep.id, ep.nama, ep.singkatan, ep.tipe
            FROM entitas_perusahaan ep
            LEFT JOIN admin a ON a.entitas_id = ep.id AND a.role = 'admin_perusahaan'
            WHERE a.id IS NULL AND ep.aktif = 1 AND ep.tipe IN ('unit_pelaksana', 'unit_layanan', 'anak_perusahaan', 'unit_induk', 'subholding', 'holding')
            ORDER BY ep.id ASC
        ");
        $unassigned = $stmtUnassigned->fetchAll(PDO::FETCH_ASSOC);

        if (empty($unassigned)) {
            echo json_encode([
                'ok' => true,
                'message' => 'Semua entitas unit mitra aktif sudah memiliki akun admin perusahaan.',
                'generated_count' => 0,
                'generated_accounts' => []
            ]);
            exit;
        }

        $generatedAccounts = [];
        $stmtInsert = $pdo->prepare("
            INSERT INTO admin (nama, username, email, password_hash, role, entitas_id, force_password_change, aktif, created_at, updated_at)
            VALUES (:nama, :username, :email, :password_hash, 'admin_perusahaan', :entitas_id, 1, 1, NOW(), NOW())
        ");

        $pdo->beginTransaction();

        foreach ($unassigned as $unit) {
            $unitId = (int)$unit['id'];
            $singkatan = !empty($unit['singkatan']) ? trim($unit['singkatan']) : trim($unit['nama']);
            
            // Format username bersih: hanya huruf dan angka lowercase
            $baseUsername = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $singkatan));
            if ($baseUsername === '') {
                $baseUsername = 'unit' . $unitId;
            }

            // Pastikan username unik
            $username = $baseUsername;
            $counter = 1;
            while (true) {
                $stmtCheck = $pdo->prepare("SELECT id FROM admin WHERE username = :u LIMIT 1");
                $stmtCheck->execute([':u' => $username]);
                if (!$stmtCheck->fetch()) {
                    break;
                }
                $counter++;
                $username = $baseUsername . $counter;
            }

            // Generate password default yang mudah dibaca, konsisten dengan lembar surat/kredensial
            $tempPassword = 'PLN-' . strtoupper(substr($baseUsername, 0, 8)) . '-2026';
            $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

            $adminNama = 'Admin ' . $unit['nama'];
            $defaultEmail = $username . '@mitra.magang';

            $stmtInsert->execute([
                ':nama'          => $adminNama,
                ':username'      => $username,
                ':email'         => $defaultEmail,
                ':password_hash' => $passwordHash,
                ':entitas_id'    => $unitId,
            ]);

            $generatedAccounts[] = [
                'entitas_id'    => $unitId,
                'entitas_nama'  => $unit['nama'],
                'username'      => $username,
                'temp_password' => $tempPassword,
            ];
        }

        $pdo->commit();

        echo json_encode([
            'ok'                 => true,
            'message'            => count($generatedAccounts) . ' akun admin perusahaan berhasil dibuat secara otomatis.',
            'generated_count'    => count($generatedAccounts),
            'generated_accounts' => $generatedAccounts,
        ]);
        exit;
    }

    if ($action === 'create_single') {
        $entitasId = (int)($input['entitas_id'] ?? 0);
        $customUsername = trim((string)($input['username'] ?? ''));

        if ($entitasId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Entitas unit wajib dipilih.']);
            exit;
        }

        $stmtE = $pdo->prepare("SELECT id, nama, singkatan FROM entitas_perusahaan WHERE id = ? LIMIT 1");
        $stmtE->execute([$entitasId]);
        $unit = $stmtE->fetch(PDO::FETCH_ASSOC);

        if (!$unit) {
            http_response_code(404);
            echo json_encode(['error' => 'Entitas perusahaan tidak ditemukan.']);
            exit;
        }

        // Cek apakah sudah punya akun
        $stmtCheck = $pdo->prepare("SELECT id, username FROM admin WHERE entitas_id = ? AND role = 'admin_perusahaan' LIMIT 1");
        $stmtCheck->execute([$entitasId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            http_response_code(400);
            echo json_encode(['error' => 'Entitas ini sudah memiliki akun admin perusahaan (' . $existing['username'] . '). Gunakan fitur Reset Password jika ingin mengganti kredensial.']);
            exit;
        }

        $singkatan = !empty($unit['singkatan']) ? trim($unit['singkatan']) : trim($unit['nama']);
        $baseUsername = $customUsername !== '' ? strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $customUsername)) : strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $singkatan));
        if ($baseUsername === '') {
            $baseUsername = 'unit' . $entitasId;
        }

        $username = $baseUsername;
        $stmtCheckU = $pdo->prepare("SELECT id FROM admin WHERE username = :u LIMIT 1");
        $stmtCheckU->execute([':u' => $username]);
        if ($stmtCheckU->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => "Username '{$username}' sudah digunakan akun lain. Silakan gunakan username lain."]);
            exit;
        }

        $tempPassword = 'PLN-' . strtoupper(substr($baseUsername, 0, 8)) . '-2026';
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

        $stmtIns = $pdo->prepare("
            INSERT INTO admin (nama, username, email, password_hash, role, entitas_id, force_password_change, aktif, created_at, updated_at)
            VALUES (:nama, :username, :email, :password_hash, 'admin_perusahaan', :entitas_id, 1, 1, NOW(), NOW())
        ");
        $stmtIns->execute([
            ':nama'          => 'Admin ' . $unit['nama'],
            ':username'      => $username,
            ':email'         => $username . '@mitra.magang',
            ':password_hash' => $passwordHash,
            ':entitas_id'    => $entitasId,
        ]);

        echo json_encode([
            'ok'            => true,
            'message'       => 'Akun admin perusahaan berhasil dibuat.',
            'entitas_nama'  => $unit['nama'],
            'username'      => $username,
            'temp_password' => $tempPassword,
        ]);
        exit;
    }

    if ($action === 'reset_password') {
        $entitasId = (int)($input['entitas_id'] ?? 0);
        $adminId   = (int)($input['admin_id'] ?? 0);

        $admin = null;
        if ($adminId > 0) {
            $stmtA = $pdo->prepare("
                SELECT a.id, a.username, a.nama, ep.nama AS entitas_nama, ep.singkatan
                FROM admin a
                JOIN entitas_perusahaan ep ON a.entitas_id = ep.id
                WHERE a.id = ? AND a.role = 'admin_perusahaan'
                LIMIT 1
            ");
            $stmtA->execute([$adminId]);
            $admin = $stmtA->fetch(PDO::FETCH_ASSOC);
        } elseif ($entitasId > 0) {
            $stmtA = $pdo->prepare("
                SELECT a.id, a.username, a.nama, ep.nama AS entitas_nama, ep.singkatan
                FROM admin a
                JOIN entitas_perusahaan ep ON a.entitas_id = ep.id
                WHERE a.entitas_id = ? AND a.role = 'admin_perusahaan'
                LIMIT 1
            ");
            $stmtA->execute([$entitasId]);
            $admin = $stmtA->fetch(PDO::FETCH_ASSOC);
        }

        if (!$admin) {
            http_response_code(404);
            echo json_encode(['error' => 'Akun admin perusahaan tidak ditemukan untuk unit ini.']);
            exit;
        }

        $baseCode = !empty($admin['singkatan']) ? strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $admin['singkatan'])) : 'pln';
        $newTempPassword = 'PLN-' . strtoupper(substr($baseCode, 0, 8)) . '-2026';
        $passwordHash = password_hash($newTempPassword, PASSWORD_BCRYPT);

        $stmtUpd = $pdo->prepare("
            UPDATE admin
            SET password_hash = :p_hash, force_password_change = 1, aktif = 1, updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':p_hash' => $passwordHash,
            ':id'     => (int)$admin['id']
        ]);

        echo json_encode([
            'ok'            => true,
            'message'       => 'Password berhasil direset menjadi password sementara baru.',
            'entitas_nama'  => $admin['entitas_nama'],
            'username'      => $admin['username'],
            'temp_password' => $newTempPassword,
        ]);
        exit;
    }

    if ($action === 'toggle_status') {
        $adminId = (int)($input['admin_id'] ?? 0);
        $aktif   = !empty($input['aktif']) ? 1 : 0;

        if ($adminId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Parameter admin_id wajib diisi.']);
            exit;
        }

        $stmtUpd = $pdo->prepare("UPDATE admin SET aktif = :aktif, updated_at = NOW() WHERE id = :id AND role = 'admin_perusahaan'");
        $stmtUpd->execute([':aktif' => $aktif, ':id' => $adminId]);

        echo json_encode([
            'ok'      => true,
            'message' => 'Status akun admin perusahaan berhasil diperbarui.',
            'aktif'   => (bool)$aktif
        ]);
        exit;
    }

    if ($action === 'delete_all') {
        $stmtDel = $pdo->prepare("DELETE FROM admin WHERE role = 'admin_perusahaan'");
        $stmtDel->execute();
        $deletedCount = $stmtDel->rowCount();

        echo json_encode([
            'ok'            => true,
            'message'       => "Berhasil menghapus seluruh akun admin perusahaan ({$deletedCount} akun).",
            'deleted_count' => $deletedCount
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Aksi tidak valid atau tidak dikenali.']);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Terjadi kesalahan pada server.')]);
}

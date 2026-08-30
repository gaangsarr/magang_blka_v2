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
Auth::requireCsrfApi(); // BLOCKER-05: CSRF protection

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$mahasiswaId = $input['mahasiswa_id'] ?? null;
$emailInput = trim($input['email'] ?? '');
$namaInput = trim($input['nama'] ?? '');
$roleTarget = $input['role'] ?? 'admin_blka'; // 'admin_blka' atau 'super_admin'

if (!$mahasiswaId && empty($emailInput)) {
    http_response_code(400);
    echo json_encode(['error' => 'Parameter mahasiswa_id atau email wajib diisi.']);
    exit;
}

$validRoles = ['admin_blka', 'super_admin', 'admin'];
if (!in_array($roleTarget, $validRoles, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Role target tidak valid.']);
    exit;
}

if ($roleTarget === 'admin') {
    $roleTarget = 'admin_blka';
}

try {
    $pdo = Database::getInstance();
    $email = strtolower($emailInput);
    $nama = $namaInput;
    $mId = $mahasiswaId ? (int)$mahasiswaId : null;

    if ($mId) {
        $stmtMhs = $pdo->prepare("SELECT id, nim, nama, email FROM mahasiswa WHERE id = :id LIMIT 1");
        $stmtMhs->execute([':id' => $mId]);
        $mhs = $stmtMhs->fetch(PDO::FETCH_ASSOC);

        if ($mhs) {
            $email = strtolower(trim($mhs['email']));
            $nama = trim($mhs['nama'] ?: ('Admin (' . $mhs['nim'] . ')'));
        }
    }

    if (empty($email) || !str_ends_with($email, '@itpln.ac.id')) {
        http_response_code(400);
        echo json_encode(['error' => 'Email admin wajib berakhiran @itpln.ac.id.']);
        exit;
    }

    if (empty($nama)) {
        $nama = explode('@', $email)[0];
    }

    // 2. Upsert ke tabel admin
    $stmtCheck = $pdo->prepare("SELECT id FROM admin WHERE email = :email OR (mahasiswa_id IS NOT NULL AND mahasiswa_id = :mid) LIMIT 1");
    $stmtCheck->execute([':email' => $email, ':mid' => $mId ?? 0]);
    $existingAdmin = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingAdmin) {
        $stmtUpd = $pdo->prepare("
            UPDATE admin 
            SET 
                mahasiswa_id = :mid,
                nama = :nama,
                email = :email,
                role = :role,
                aktif = 1,
                updated_at = NOW()
            WHERE id = :aid
        ");
        $stmtUpd->execute([
            ':mid'   => $mahasiswaId,
            ':nama'  => $nama,
            ':email' => $email,
            ':role'  => $roleTarget,
            ':aid'   => $existingAdmin['id'],
        ]);
    } else {
        $stmtIns = $pdo->prepare("
            INSERT INTO admin (mahasiswa_id, email, nama, password_hash, role, aktif, created_at, updated_at)
            VALUES (:mid, :email, :nama, NULL, :role, 1, NOW(), NOW())
        ");
        $stmtIns->execute([
            ':mid'   => $mahasiswaId,
            ':email' => $email,
            ':nama'  => $nama,
            ':role'  => $roleTarget,
        ]);
    }

    echo json_encode([
        'ok'      => true,
        'message' => "Hak akses admin berhasil diberikan kepada {$nama} ({$email}) sebagai {$roleTarget}.",
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memberikan hak akses admin.')]);
}


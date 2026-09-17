<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\AzureAuth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

if (!AzureAuth::isDevMockAllowed()) {
    header('Location: /login.html');
    exit;
}

Auth::startSession(startPHP: true);

$error = null;
$pdo = Database::getInstance();

// 1. Ambil daftar admin yang aktif di database untuk opsi testing
$stmtAdmins = $pdo->query("SELECT id, nama, email, role FROM admin WHERE aktif = 1 ORDER BY id ASC LIMIT 5");
$adminsList = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

// 2. Handle POST submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $nama  = trim((string)($_POST['nama'] ?? ''));

    if (empty($email) || !str_ends_with($email, '@itpln.ac.id')) {
        $error = 'Email harus diakhiri dengan @itpln.ac.id';
    } else {
        // Cek Admin (BLKA, Super Admin, Mitra Perusahaan)
        $stmtAdmin = $pdo->prepare("SELECT id, nama, email, username, role, entitas_id, force_password_change FROM admin WHERE (LOWER(TRIM(email)) = :email OR LOWER(TRIM(username)) = :uname) AND aktif = 1 LIMIT 1");
        $stmtAdmin->execute([':email' => $email, ':uname' => $email]);
        $admin = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        if (!$admin) {
            $stmtMhsLookup = $pdo->prepare("SELECT id FROM mahasiswa WHERE LOWER(TRIM(email)) = :email LIMIT 1");
            $stmtMhsLookup->execute([':email' => $email]);
            $mhsRow = $stmtMhsLookup->fetch(PDO::FETCH_ASSOC);
            if ($mhsRow) {
                $stmtAdminByMhs = $pdo->prepare("SELECT id, nama, email, username, role, entitas_id, force_password_change FROM admin WHERE mahasiswa_id = :mid AND aktif = 1 LIMIT 1");
                $stmtAdminByMhs->execute([':mid' => $mhsRow['id']]);
                $admin = $stmtAdminByMhs->fetch(PDO::FETCH_ASSOC);
            }
        }

        if ($admin) {
            Auth::setAdminSession(
                (int)$admin['id'], 
                $admin['nama'] ?: ($nama ?: 'Admin'), 
                $admin['role'] ?? 'admin_blka',
                !empty($admin['entitas_id']) ? (int)$admin['entitas_id'] : null,
                (bool)($admin['force_password_change'] ?? false)
            );
            if ($admin['role'] === 'admin_perusahaan') {
                header('Location: /perusahaan/index.html');
            } else {
                header('Location: /admin/index.html');
            }
            exit;
        }

        // Mahasiswa
        $nimData = Auth::parseNimFromEmail($email);
        if (!$nimData) {
            $error = "Email {$email} bukan format email mahasiswa ITPLN valid (contoh: nama2211001@itpln.ac.id).";
        } else {
            $nim         = $nimData['nim'];
            $angkatan    = $nimData['angkatan'];
            $kodeJurusan = $nimData['kode_jurusan'];
            $noUrutAbsen = $nimData['no_urut_absen'];

            $stmtJ = $pdo->prepare("SELECT id FROM jurusan WHERE kode = :kode LIMIT 1");
            $stmtJ->execute([':kode' => $kodeJurusan]);
            $j = $stmtJ->fetch(PDO::FETCH_ASSOC);
            $jurusanId = $j ? (int)$j['id'] : null;

            $mockUid = 'mock_azure_' . md5($email);

            $stmtCheck = $pdo->prepare("SELECT id FROM mahasiswa WHERE email = :email OR uid_firebase = :uid LIMIT 1");
            $stmtCheck->execute([':email' => $email, ':uid' => $mockUid]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $mid = (int)$existing['id'];
                $pdo->prepare("UPDATE mahasiswa SET nama = COALESCE(:nama, nama), nim = :nim, angkatan = :angkatan, jurusan_id = :jid, no_urut_absen = :no, uid_firebase = :uid, updated_at = NOW() WHERE id = :id")
                    ->execute([
                        ':nama'     => $nama ?: null,
                        ':nim'      => $nim,
                        ':angkatan' => $angkatan,
                        ':jid'      => $jurusanId,
                        ':no'       => $noUrutAbsen,
                        ':uid'      => $mockUid,
                        ':id'       => $mid,
                    ]);
            } else {
                $stmtIns = $pdo->prepare("INSERT INTO mahasiswa (uid_firebase, email, nim, nama, needs_nama, angkatan, jurusan_id, no_urut_absen, created_at, updated_at) VALUES (:uid, :email, :nim, :nama, 0, :angkatan, :jid, :no, NOW(), NOW())");
                $stmtIns->execute([
                    ':uid'      => $mockUid,
                    ':email'    => $email,
                    ':nim'      => $nim,
                    ':nama'     => $nama ?: 'Mahasiswa Tester',
                    ':angkatan' => $angkatan,
                    ':jid'      => $jurusanId,
                    ':no'       => $noUrutAbsen,
                ]);
                $mid = (int)$pdo->lastInsertId();
            }

            Auth::setMahasiswaSession($mid);
            header('Location: /daftar.html');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dev Mock Login — REMATE ITPLN</title>
    <link rel="stylesheet" href="/css/main.css">
    <style>
        body { background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; font-family: sans-serif; }
        .mock-card { background: #ffffff; border-radius: 18px; border: 1px solid #e2e8f0; box-shadow: 0 10px 25px rgba(0,0,0,0.06); max-width: 520px; width: 100%; padding: 32px; }
        .mock-header { text-align: center; margin-bottom: 24px; }
        .mock-badge { display: inline-block; padding: 4px 12px; background: #fef3c7; color: #b45309; font-size: 0.75rem; font-weight: 700; border-radius: 20px; margin-bottom: 8px; }
        .mock-title { font-size: 1.35rem; font-weight: 800; color: #0b3d6b; margin: 0; }
        .mock-desc { font-size: 0.85rem; color: #64748b; margin-top: 6px; }
        .mock-section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin: 18px 0 10px; letter-spacing: 0.05em; }
        .preset-btn { display: flex; align-items: center; justify-content: space-between; width: 100%; padding: 10px 14px; margin-bottom: 8px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; cursor: pointer; text-align: left; transition: all 0.15s; }
        .preset-btn:hover { background: #f0fdf4; border-color: #86efac; transform: translateY(-1px); }
        .preset-name { font-weight: 700; font-size: 0.875rem; color: #0f172a; }
        .preset-email { font-size: 0.775rem; color: #64748b; font-family: monospace; }
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 700; color: #334155; margin-bottom: 4px; }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.875rem; box-sizing: border-box; }
        .btn-submit { width: 100%; padding: 12px; background: #0b3d6b; color: #ffffff; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background: #082d4f; }
        .alert-error { background: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 10px 14px; border-radius: 8px; font-size: 0.85rem; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="mock-card">
        <div class="mock-header">
            <span class="mock-badge">LOCAL DEV MODE</span>
            <h1 class="mock-title">Simulasi Login Akun ITPLN</h1>
            <p class="mock-desc">Kredensial Azure belum diisi di <code>.env</code>. Gunakan akun simulasi di bawah untuk melanjutkan pengujian lokal.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="mock-section-title">Pilih Akun Cepat (Preset)</div>

        <form method="POST" id="presetForm">
            <input type="hidden" name="email" id="presetEmail">
            <input type="hidden" name="nama" id="presetNama">

            <button type="button" class="preset-btn" onclick="selectPreset('budi2211001@itpln.ac.id', 'Budi Santoso (Informatika 2022)')">
                <div>
                    <div class="preset-name">Budi Santoso</div>
                    <div class="preset-email">budi2211001@itpln.ac.id (NIM: 2211001)</div>
                </div>
                <span style="color: #059669; font-weight: 700; font-size: 0.8rem;">Login &rarr;</span>
            </button>

            <button type="button" class="preset-btn" onclick="selectPreset('siti2112002@itpln.ac.id', 'Siti Rahmawati (Teknik Elektro 2021)')">
                <div>
                    <div class="preset-name">Siti Rahmawati</div>
                    <div class="preset-email">siti2112002@itpln.ac.id (NIM: 2112002)</div>
                </div>
                <span style="color: #059669; font-weight: 700; font-size: 0.8rem;">Login &rarr;</span>
            </button>

            <?php if (!empty($adminsList)): ?>
                <?php foreach ($adminsList as $adm): ?>
                    <button type="button" class="preset-btn" style="background: #eff6ff; border-color: #bfdbfe;" onclick="selectPreset('<?= htmlspecialchars($adm['email']) ?>', '<?= htmlspecialchars($adm['nama']) ?>')">
                        <div>
                            <div class="preset-name"><?= htmlspecialchars($adm['nama']) ?> <span style="font-size: 0.7rem; background: #dbeafe; color: #1d4ed8; padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($adm['role']) ?></span></div>
                            <div class="preset-email"><?= htmlspecialchars($adm['email']) ?></div>
                        </div>
                        <span style="color: #2563eb; font-weight: 700; font-size: 0.8rem;">Admin &rarr;</span>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </form>

        <div class="mock-section-title" style="margin-top: 24px;">Atau Masukkan Email Kustom</div>
        <form method="POST">
            <div class="form-group">
                <label class="form-label" for="customEmail">Email @itpln.ac.id</label>
                <input type="email" id="customEmail" name="email" class="form-input" placeholder="contoh: nama2211001@itpln.ac.id" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="customNama">Nama Lengkap</label>
                <input type="text" id="customNama" name="nama" class="form-input" placeholder="Contoh: Nama Mahasiswa">
            </div>
            <button type="submit" class="btn-submit">Login dengan Akun Kustom</button>
        </form>

        <div style="text-align: center; margin-top: 20px;">
            <a href="/login.html" style="font-size: 0.8rem; color: #64748b; text-decoration: none;">&larr; Kembali ke Beranda</a>
        </div>
    </div>

    <script>
        function selectPreset(email, nama) {
            document.getElementById('presetEmail').value = email;
            document.getElementById('presetNama').value = nama;
            document.getElementById('presetForm').submit();
        }
    </script>
</body>
</html>

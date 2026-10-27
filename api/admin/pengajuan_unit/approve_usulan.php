<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PengajuanUnitHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard ketat: Khusus Super Admin dan Admin BLKA
Auth::requireBlkaApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format data tidak valid.']);
    exit;
}

$id = (int)($input['id'] ?? 0);
$mode = trim((string)($input['mode'] ?? 'buat_baru')); // 'buat_baru' | 'tautkan'
$tautkanIds = isset($input['tautkan_ids']) && is_array($input['tautkan_ids']) ? array_map('intval', $input['tautkan_ids']) : [];

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID usulan unit tidak valid.']);
    exit;
}

try {
    $pdo = Database::getInstance();
    $adminId = Auth::getAdminId();

    $pdo->beginTransaction();

    // 1. Validasi usulan unit baru
    $stmtUsulan = $pdo->prepare("
        SELECT id, nama_unit_baru, alamat_unit_baru, pic_nama, pic_kontak, pic_email, status 
        FROM pengajuan_unit_mahasiswa 
        WHERE id = :id AND jenis = 'usulan_unit_baru'
    ");
    $stmtUsulan->execute([':id' => $id]);
    $usulan = $stmtUsulan->fetch(PDO::FETCH_ASSOC);

    if (!$usulan) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Data usulan unit baru tidak ditemukan.']);
        exit;
    }

    $entitasIdHasil = 0;
    $entitasNama = '';

    // =========================================================================
    // MODE 1: Buat Entitas Unit Baru ke Master entitas_perusahaan
    // =========================================================================
    if ($mode === 'buat_baru') {
        $nama      = trim((string)($input['nama'] ?? $usulan['nama_unit_baru']));
        $singkatan = trim((string)($input['singkatan'] ?? ''));
        $tipe      = trim((string)($input['tipe'] ?? ''));
        $parentId  = !empty($input['parent_id']) ? (int)$input['parent_id'] : null;
        $alamat    = trim((string)($input['alamat'] ?? $usulan['alamat_unit_baru']));
        $picNama   = trim((string)($input['pic_nama'] ?? $usulan['pic_nama']));
        $picKontak = trim((string)($input['pic_kontak'] ?? $usulan['pic_kontak']));
        $picEmail  = trim((string)($input['pic_email'] ?? $usulan['pic_email']));

        $validTipe = ['holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana', 'unit_layanan'];
        if (!in_array($tipe, $validTipe, true)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Tipe hierarki entitas PLN wajib dipilih (Holding, Subholding, Anak Perusahaan, Unit Induk, Unit Pelaksana, atau Unit Layanan).']);
            exit;
        }

        if ($nama === '' || mb_strlen($nama, 'UTF-8') < 3) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Nama unit wajib diisi minimal 3 karakter.']);
            exit;
        }

        // Validasi parent_id jika diisi
        if ($parentId !== null) {
            $stmtPar = $pdo->prepare("SELECT id FROM entitas_perusahaan WHERE id = :pid");
            $stmtPar->execute([':pid' => $parentId]);
            if (!$stmtPar->fetch()) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Induk entitas (parent) yang dipilih tidak ditemukan.']);
                exit;
            }
        }

        // Cek apakah sudah ada entitas aktif dengan nama yang persis sama
        $stmtDup = $pdo->prepare("SELECT id FROM entitas_perusahaan WHERE nama = :nama LIMIT 1");
        $stmtDup->execute([':nama' => $nama]);
        if ($stmtDup->fetch()) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'error' => "Unit dengan nama '{$nama}' sudah terdaftar di master data. Anda dapat memilih mode 'Tautkan ke Unit yang Ada'."
            ]);
            exit;
        }

        // Insert ke entitas_perusahaan
        $stmtInsert = $pdo->prepare("
            INSERT INTO entitas_perusahaan (
                tipe, parent_id, nama, singkatan, alamat,
                aktif, menerima_magang,
                pic_nama, pic_kontak, pic_email,
                created_at, updated_at
            ) VALUES (
                :tipe, :parent_id, :nama, :singkatan, :alamat,
                1, 1,
                :pic_nama, :pic_kontak, :pic_email,
                NOW(), NOW()
            )
        ");
        $stmtInsert->execute([
            ':tipe'       => $tipe,
            ':parent_id'  => $parentId,
            ':nama'       => $nama,
            ':singkatan'  => $singkatan !== '' ? $singkatan : null,
            ':alamat'     => $alamat !== '' ? $alamat : null,
            ':pic_nama'   => $picNama !== '' ? $picNama : null,
            ':pic_kontak' => $picKontak !== '' ? $picKontak : null,
            ':pic_email'  => $picEmail !== '' ? $picEmail : null,
        ]);

        $entitasIdHasil = (int)$pdo->lastInsertId();
        $entitasNama = $nama;

        // Bersihkan cache entitas agar sinkron
        $cacheFile = dirname(__DIR__, 3) . '/cache/active_entities.json';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

    } elseif ($mode === 'tautkan') {
        // =====================================================================
        // MODE 2: Tautkan ke Entitas Terdaftar yang Sudah Ada
        // =====================================================================
        $targetEntitasId = (int)($input['entitas_id'] ?? 0);
        if ($targetEntitasId <= 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Unit PLN yang ditautkan wajib dipilih.']);
            exit;
        }

        $stmtTarget = $pdo->prepare("SELECT id, nama FROM entitas_perusahaan WHERE id = :eid");
        $stmtTarget->execute([':eid' => $targetEntitasId]);
        $targetEnt = $stmtTarget->fetch(PDO::FETCH_ASSOC);

        if (!$targetEnt) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['error' => 'Unit PLN yang ditautkan tidak ditemukan.']);
            exit;
        }

        $entitasIdHasil = $targetEntitasId;
        $entitasNama = $targetEnt['nama'];
    } else {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Mode persetujuan tidak valid.']);
        exit;
    }

    // 2. Kumpulkan seluruh ID usulan yang akan disetujui (usulan utama + tautkan_ids)
    $allUsulanIds = array_unique(array_merge([$id], $tautkanIds));
    $inUsulan = implode(',', $allUsulanIds);

    // 3. Update usulan utama & tautkan_ids
    $stmtApprove = $pdo->prepare("
        UPDATE pengajuan_unit_mahasiswa 
        SET status = 'disetujui',
            entitas_id_hasil = :eid_hasil,
            alasan_admin = 'Usulan unit telah disetujui & ditambahkan ke sistem oleh BLKA.',
            diproses_oleh = :aid,
            diproses_at = NOW(),
            updated_at = NOW()
        WHERE id IN ({$inUsulan})
    ");
    $stmtApprove->execute([
        ':eid_hasil' => $entitasIdHasil,
        ':aid'       => $adminId
    ]);

    // 4. Update juga seluruh dukungan_usulan yang terhubung
    $stmtApproveDuk = $pdo->prepare("
        UPDATE pengajuan_unit_mahasiswa 
        SET status = 'disetujui',
            entitas_id_hasil = :eid_hasil,
            alasan_admin = 'Usulan unit yang Anda dukung telah disetujui oleh BLKA.',
            diproses_oleh = :aid,
            diproses_at = NOW(),
            updated_at = NOW()
        WHERE usulan_induk_id IN ({$inUsulan}) AND status != 'dibatalkan'
    ");
    $stmtApproveDuk->execute([
        ':eid_hasil' => $entitasIdHasil,
        ':aid'       => $adminId
    ]);

    // 5. Catat log audit
    PengajuanUnitHelper::logAdmin($pdo, $adminId, 'approve_usulan_unit', $id, [
        'mode'              => $mode,
        'entitas_id_hasil'  => $entitasIdHasil,
        'entitas_nama'      => $entitasNama,
        'usulan_ids'        => $allUsulanIds
    ]);

    $pdo->commit();

    echo json_encode([
        'ok'               => true,
        'entitas_id'       => $entitasIdHasil,
        'entitas_nama'     => $entitasNama,
        'is_super_admin'   => (Auth::getAdminRole() === 'super_admin'),
        'message'          => "Usulan unit berhasil disetujui dan ditautkan ke '{$entitasNama}'."
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memproses persetujuan usulan unit.')]);
}

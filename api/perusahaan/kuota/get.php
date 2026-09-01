<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

Auth::requirePerusahaanApi();

try {
    $pdo = Database::getInstance();
    $entitasId = Auth::getPerusahaanEntitasId();
    $admin = Auth::getAdmin();

    if (!$entitasId) {
        if ($admin && ($admin['role'] === 'super_admin' || $admin['role'] === 'superadmin')) {
            $requestedEid = isset($_GET['entitas_id']) ? (int)$_GET['entitas_id'] : 0;
            if ($requestedEid > 0) {
                $entitasId = $requestedEid;
            } else {
                $stmtFirst = $pdo->query("SELECT id FROM entitas_perusahaan WHERE tipe IN ('unit_pelaksana', 'unit_layanan') ORDER BY id ASC LIMIT 1");
                $entitasId = (int)$stmtFirst->fetchColumn();
            }
        }
    }

    if (!$entitasId) {
        http_response_code(400);
        echo json_encode(['error' => 'Entitas unit tidak terhubung ke akun ini.']);
        exit;
    }

    // 1. Ambil daftar semua periode untuk dropdown selector
    $stmtAllP = $pdo->query("SELECT id, nama, tanggal_mulai, tanggal_selesai, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAllP->fetchAll(PDO::FETCH_ASSOC);

    $requestedPeriodeId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $selectedPeriode = null;

    if ($requestedPeriodeId > 0) {
        foreach ($allPeriode as $p) {
            if ((int)$p['id'] === $requestedPeriodeId) {
                $selectedPeriode = $p;
                break;
            }
        }
    }

    if (!$selectedPeriode) {
        // Cari periode aktif
        foreach ($allPeriode as $p) {
            if (in_array($p['status'], ['dibuka', 'persiapan'], true)) {
                $selectedPeriode = $p;
                break;
            }
        }
    }

    if (!$selectedPeriode && !empty($allPeriode)) {
        $selectedPeriode = $allPeriode[0];
    }

    if (!$selectedPeriode) {
        echo json_encode([
            'ok' => true,
            'all_periode' => [],
            'selected_periode' => null,
            'kuota' => null,
        ]);
        exit;
    }

    $periodeId = (int)$selectedPeriode['id'];
    $canEdit = in_array($selectedPeriode['status'], ['persiapan', 'draft'], true);

    // 2. Ambil master entitas untuk status menerima magang
    $stmtE = $pdo->prepare("SELECT menerima_magang FROM entitas_perusahaan WHERE id = ?");
    $stmtE->execute([$entitasId]);
    $entitasRow = $stmtE->fetch(PDO::FETCH_ASSOC);
    $menerimaMagangDefault = !empty($entitasRow['menerima_magang']);

    // 3. Ambil data unit_pelaksana_periode untuk periode ini
    $stmtUpp = $pdo->prepare("
        SELECT id, kuota_total, kuota_tersisa, aktif
        FROM unit_pelaksana_periode
        WHERE entitas_id = :eid AND periode_id = :pid
        LIMIT 1
    ");
    $stmtUpp->execute([':eid' => $entitasId, ':pid' => $periodeId]);
    $upp = $stmtUpp->fetch(PDO::FETCH_ASSOC);

    $uppId = $upp ? (int)$upp['id'] : 0;
    $kuotaTotal = $upp ? (int)$upp['kuota_total'] : 0;
    $kuotaTersisa = $upp ? (int)$upp['kuota_tersisa'] : 0;
    // Sinkron penuh: Jika master entitas tidak menerima magang (0), maka otomatis false.
    $uppAktif = $menerimaMagangDefault && ($upp ? (bool)$upp['aktif'] : true);

    // 4. Ambil semua jurusan aktif & tandai yang terpilih
    $stmtJAll = $pdo->query("SELECT id, kode, nama_jurusan FROM jurusan WHERE aktif = 1 ORDER BY nama_jurusan ASC");
    $allJurusan = $stmtJAll->fetchAll(PDO::FETCH_ASSOC);

    $selectedJurusanIds = [];
    if ($uppId > 0) {
        $stmtJSelected = $pdo->prepare("SELECT jurusan_id FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = ?");
        $stmtJSelected->execute([$uppId]);
        $selectedJurusanIds = $stmtJSelected->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Fallback: ambil template default dari unit_jurusan jika ada
        try {
            $stmtJTemplate = $pdo->prepare("SELECT jurusan_id FROM unit_jurusan WHERE entitas_id = ?");
            $stmtJTemplate->execute([$entitasId]);
            $selectedJurusanIds = $stmtJTemplate->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            $selectedJurusanIds = [];
        }
    }

    $jurusanList = [];
    foreach ($allJurusan as $j) {
        $jId = (int)$j['id'];
        $jurusanList[] = [
            'id'           => $jId,
            'kode_jurusan' => $j['kode'] ?? '',
            'nama_jurusan' => $j['nama_jurusan'],
            'jenjang'      => 'S1',
            'is_selected'  => in_array($jId, array_map('intval', $selectedJurusanIds), true),
        ];
    }

    // 5. Ambil semua peminatan aktif & tandai yang terpilih
    $stmtPemAll = $pdo->query("SELECT id, nama, deskripsi FROM peminatan WHERE aktif = 1 ORDER BY nama ASC");
    $allPeminatan = $stmtPemAll->fetchAll(PDO::FETCH_ASSOC);

    $selectedPeminatanIds = [];
    if ($uppId > 0) {
        $stmtPemSelected = $pdo->prepare("SELECT peminatan_id FROM unit_periode_peminatan WHERE unit_pelaksana_periode_id = ?");
        $stmtPemSelected->execute([$uppId]);
        $selectedPeminatanIds = $stmtPemSelected->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Fallback: ambil template default dari unit_peminatan jika ada
        try {
            $stmtPemTemplate = $pdo->prepare("SELECT peminatan_id FROM unit_peminatan WHERE entitas_id = ?");
            $stmtPemTemplate->execute([$entitasId]);
            $selectedPeminatanIds = $stmtPemTemplate->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            $selectedPeminatanIds = [];
        }
    }

    $peminatanList = [];
    foreach ($allPeminatan as $pem) {
        $pemId = (int)$pem['id'];
        $peminatanList[] = [
            'id'             => $pemId,
            'nama_peminatan' => $pem['nama'],
            'deskripsi'      => $pem['deskripsi'],
            'is_selected'    => in_array($pemId, array_map('intval', $selectedPeminatanIds), true),
        ];
    }

    // 6. Hitung jumlah pendaftar
    $totalPendaftar = 0;
    if ($uppId > 0) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE unit_pelaksana_periode_id = ?");
        $stmtCount->execute([$uppId]);
        $totalPendaftar = (int)$stmtCount->fetchColumn();
    }

    echo json_encode([
        'ok' => true,
        'all_periode' => $allPeriode,
        'selected_periode' => [
            'id'             => $periodeId,
            'nama'           => $selectedPeriode['nama'],
            'tanggal_mulai'  => $selectedPeriode['tanggal_mulai'] ?? null,
            'tanggal_selesai'=> $selectedPeriode['tanggal_selesai'] ?? null,
            'status'         => $selectedPeriode['status'],
            'can_edit'       => $canEdit,
        ],
        'kuota' => [
            'upp_id'           => $uppId,
            'menerima_magang'  => $uppAktif,
            'kuota_total'      => $kuotaTotal,
            'kuota_tersisa'    => $kuotaTersisa,
            'total_pendaftar'  => $totalPendaftar,
            'jurusan_list'     => $jurusanList,
            'peminatan_list'   => $peminatanList,
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil data kuota periode.')]);
}

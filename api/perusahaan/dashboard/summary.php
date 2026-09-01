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

    // 1. Data Entitas & PIC
    $stmtE = $pdo->prepare("
        SELECT 
            ep.id, ep.nama, ep.singkatan, ep.tipe, ep.alamat, ep.latitude, ep.longitude,
            ep.menerima_magang, ep.pic_nama, ep.pic_jabatan, ep.pic_kontak, ep.pic_email,
            parent.nama AS parent_nama
        FROM entitas_perusahaan ep
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        WHERE ep.id = ?
        LIMIT 1
    ");
    $stmtE->execute([$entitasId]);
    $entitas = $stmtE->fetch(PDO::FETCH_ASSOC);

    if (!$entitas) {
        http_response_code(404);
        echo json_encode(['error' => 'Data entitas perusahaan tidak ditemukan.']);
        exit;
    }

    // 2. Ambil Periode Aktif (Prioritas: 'dibuka' -> 'persiapan' -> periode terbaru)
    $stmtPeriode = $pdo->query("
        SELECT id, nama, tanggal_mulai, tanggal_selesai, status, created_at
        FROM periode 
        WHERE status IN ('dibuka', 'persiapan', 'ditutup')
        ORDER BY (status = 'dibuka') DESC, (status = 'persiapan') DESC, id DESC
        LIMIT 1
    ");
    $periodeAktif = $stmtPeriode->fetch(PDO::FETCH_ASSOC);

    if (!$periodeAktif) {
        // Fallback periode terbaru
        $stmtFall = $pdo->query("SELECT id, nama, tanggal_mulai, tanggal_selesai, status, created_at FROM periode ORDER BY id DESC LIMIT 1");
        $periodeAktif = $stmtFall->fetch(PDO::FETCH_ASSOC);
    }

    $periodeSummary = null;
    $uppData = null;
    $statsPendaftar = [
        'total'        => 0,
        'diajukan'     => 0,
        'diverifikasi' => 0,
        'diterima'     => 0,
        'ditolak'      => 0,
    ];
    $selectedJurusan = [];
    $selectedPeminatan = [];
    $bannerNotification = null;

    if ($periodeAktif) {
        $periodeId = (int)$periodeAktif['id'];
        $periodeStatus = $periodeAktif['status'];

        // Cek / buat snapshot unit_pelaksana_periode jika belum ada
        $stmtUpp = $pdo->prepare("
            SELECT id, kuota_total, kuota_tersisa, aktif 
            FROM unit_pelaksana_periode 
            WHERE entitas_id = :eid AND periode_id = :pid 
            LIMIT 1
        ");
        $stmtUpp->execute([':eid' => $entitasId, ':pid' => $periodeId]);
        $uppData = $stmtUpp->fetch(PDO::FETCH_ASSOC);

        $uppId = $uppData ? (int)$uppData['id'] : null;

        // Ambil data prodi & peminatan yang dipilih pada periode ini
        if ($uppId) {
            $stmtJ = $pdo->prepare("
                SELECT j.id, j.nama_jurusan, j.kode, j.jenjang
                FROM unit_periode_jurusan upj
                JOIN jurusan j ON upj.jurusan_id = j.id
                WHERE upj.unit_pelaksana_periode_id = ?
                ORDER BY j.jenjang ASC, j.nama_jurusan ASC
            ");
            $stmtJ->execute([$uppId]);
            $selectedJurusan = $stmtJ->fetchAll(PDO::FETCH_ASSOC);

            $stmtPem = $pdo->prepare("
                SELECT pem.id, pem.nama AS nama_peminatan
                FROM unit_periode_peminatan uppem
                JOIN peminatan pem ON uppem.peminatan_id = pem.id
                WHERE uppem.unit_pelaksana_periode_id = ?
                ORDER BY pem.nama ASC
            ");
            $stmtPem->execute([$uppId]);
            $selectedPeminatan = $stmtPem->fetchAll(PDO::FETCH_ASSOC);

            // Hitung statistik pendaftar masuk ke unit ini
            $stmtStats = $pdo->prepare("
                SELECT status, COUNT(*) AS jml
                FROM pendaftaran
                WHERE unit_pelaksana_periode_id = :upp_id AND periode_id = :pid
                GROUP BY status
            ");
            $stmtStats->execute([':upp_id' => $uppId, ':pid' => $periodeId]);
            $rowsStats = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

            $totalMhs = 0;
            foreach ($rowsStats as $st) {
                $statusKey = $st['status'];
                $count = (int)$st['jml'];
                $totalMhs += $count;
                if (isset($statsPendaftar[$statusKey])) {
                    $statsPendaftar[$statusKey] = $count;
                }
            }
            $statsPendaftar['total'] = $totalMhs;
        }

        // Susun Action Banner
        if ($periodeStatus === 'persiapan') {
            $bannerNotification = [
                'type'        => 'info',
                'title'       => "Periode {$periodeAktif['nama']} (Tahap Persiapan)",
                'message'     => 'Pendaftaran mahasiswa belum dibuka. Anda dapat mengonfigurasi kesediaan menerima magang, kuota kuantitas, program studi, dan peminatan pada menu Pengaturan Kuota.',
                'can_edit'    => true,
            ];
        } elseif ($periodeStatus === 'dibuka') {
            $bannerNotification = [
                'type'        => 'success',
                'title'       => "Periode {$periodeAktif['nama']} (Pendaftaran Dibuka)",
                'message'     => 'Pendaftaran mahasiswa sedang aktif dan live. Anda dapat memantau pendaftar yang masuk ke unit Anda pada menu Live Pendaftar.',
                'can_edit'    => false,
            ];
        } else {
            $bannerNotification = [
                'type'        => 'neutral',
                'title'       => "Periode {$periodeAktif['nama']} (" . strtoupper($periodeStatus) . ")",
                'message'     => 'Status periode saat ini sedang dalam tahap ' . strtoupper($periodeStatus) . '.',
                'can_edit'    => false,
            ];
        }

        $periodeSummary = [
            'id'                 => $periodeId,
            'nama'               => $periodeAktif['nama'],
            'status'             => $periodeStatus,
            'tanggal_mulai'      => $periodeAktif['tanggal_mulai'] ?? null,
            'tanggal_selesai'    => $periodeAktif['tanggal_selesai'] ?? null,
            'upp'                => $uppData ? [
                'id'            => (int)$uppData['id'],
                'kuota_total'   => (int)$uppData['kuota_total'],
                'kuota_tersisa' => (int)$uppData['kuota_tersisa'],
                'aktif'         => (bool)$uppData['aktif'] && (bool)$entitas['menerima_magang'],
            ] : null,
            'jurusan_count'      => count($selectedJurusan),
            'peminatan_count'    => count($selectedPeminatan),
            'selected_jurusan'   => $selectedJurusan,
            'selected_peminatan' => $selectedPeminatan,
        ];
    }

    echo json_encode([
        'ok'           => true,
        'user'         => [
            'id'                    => (int)$admin['id'],
            'nama'                  => $admin['nama'],
            'username'              => $admin['username'] ?? '',
            'email'                 => $admin['email'] ?? '',
            'role'                  => $admin['role'],
            'force_password_change' => (bool)($admin['force_password_change'] ?? false),
        ],
        'entitas' => [
            'id'              => (int)$entitas['id'],
            'nama'            => $entitas['nama'],
            'singkatan'       => $entitas['singkatan'],
            'tipe'            => $entitas['tipe'],
            'alamat'          => $entitas['alamat'],
            'latitude'        => $entitas['latitude'],
            'longitude'       => $entitas['longitude'],
            'menerima_magang' => (bool)$entitas['menerima_magang'],
            'parent_nama'     => $entitas['parent_nama'] ?? 'PLN Group',
            'pic'             => [
                'nama'    => $entitas['pic_nama'] ?? '',
                'jabatan' => $entitas['pic_jabatan'] ?? '',
                'kontak'  => $entitas['pic_kontak'] ?? '',
                'email'   => $entitas['pic_email'] ?? '',
            ]
        ],
        'periode'        => $periodeSummary,
        'stats'          => $statsPendaftar,
        'notification'   => $bannerNotification,
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal mengambil ringkasan dashboard.')]);
}

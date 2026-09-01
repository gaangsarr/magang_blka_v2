<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil daftar semua periode untuk dropdown
    $stmtAll = $pdo->query("SELECT id, nama, status FROM periode ORDER BY id DESC");
    $allPeriode = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

    $requestedId = isset($_GET['periode_id']) ? (int)$_GET['periode_id'] : 0;
    $periode = null;

    if ($requestedId > 0) {
        $stmtSel = $pdo->prepare("SELECT id, nama, status FROM periode WHERE id = ?");
        $stmtSel->execute([$requestedId]);
        $periode = $stmtSel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode) {
        // Cari periode dengan status dibuka atau persiapan terlebih dahulu
        $stmtPeriode = $pdo->query("SELECT id, nama, status FROM periode WHERE status IN ('dibuka', 'persiapan') ORDER BY (status = 'dibuka') DESC, id DESC LIMIT 1");
        $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    }

    if (!$periode && !empty($allPeriode)) {
        $periode = $allPeriode[0];
    }

    // Cek apakah ada periode yang aktif di sistem secara umum (dibuka atau persiapan)
    $stmtCheckAktif = $pdo->query("SELECT COUNT(*) FROM periode WHERE status IN ('dibuka', 'persiapan')");
    $hasPeriodeAktif = ((int)$stmtCheckAktif->fetchColumn()) > 0;
    
    $periodeId = $periode ? (int)$periode['id'] : null;
    
    // Tampilkan semua entitas yang aktif dan menerima magang (semua level hierarki)
    $query = "
        SELECT 
            ep.id AS entitas_id,
            ep.tipe,
            ep.nama,
            ep.singkatan,
            ep.alamat,
            parent.nama  AS nama_parent,
            parent.tipe  AS tipe_parent,
            upp.id       AS upp_id,
            upp.kuota_total,
            upp.kuota_tersisa,
            upp.aktif
        FROM entitas_perusahaan ep
        LEFT JOIN entitas_perusahaan parent ON ep.parent_id = parent.id
        LEFT JOIN unit_pelaksana_periode upp 
            ON ep.id = upp.entitas_id AND upp.periode_id = :periode_id
        WHERE ep.aktif = 1 AND ep.menerima_magang = 1
        ORDER BY FIELD(ep.tipe, 'holding', 'subholding', 'anak_perusahaan', 'unit_induk', 'unit_pelaksana', 'unit_layanan'), parent.nama ASC, ep.nama ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindValue(':periode_id', $periodeId, PDO::PARAM_INT);
    $stmt->execute();
    
    $units = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ambil Master Jurusan & Master Peminatan
    $allJurusan = $pdo->query("SELECT id, kode, nama_jurusan, aktif FROM jurusan ORDER BY nama_jurusan ASC")->fetchAll(PDO::FETCH_ASSOC);
    $jurusanById = [];
    foreach ($allJurusan as $j) {
        $jurusanById[(int)$j['id']] = $j;
    }

    $allPeminatan = $pdo->query("SELECT id, nama, deskripsi, aktif FROM peminatan ORDER BY nama ASC")->fetchAll(PDO::FETCH_ASSOC);
    $peminatanById = [];
    foreach ($allPeminatan as $p) {
        $peminatanById[(int)$p['id']] = $p;
    }

    // Kumpulkan IDs
    $entitasIds = [];
    $uppIds = [];
    foreach ($units as $u) {
        $entitasIds[] = (int)$u['entitas_id'];
        if (!empty($u['upp_id'])) {
            $uppIds[] = (int)$u['upp_id'];
        }
    }

    // Map Master Template
    $masterJurusanByEntitas = [];
    if (!empty($entitasIds)) {
        $inEntitas = implode(',', $entitasIds);
        $stmtUj = $pdo->query("SELECT entitas_id, jurusan_id FROM unit_jurusan WHERE entitas_id IN ($inEntitas)");
        while ($row = $stmtUj->fetch(PDO::FETCH_ASSOC)) {
            $masterJurusanByEntitas[(int)$row['entitas_id']][] = (int)$row['jurusan_id'];
        }
    }

    $masterPeminatanByEntitas = [];
    if (!empty($entitasIds)) {
        $inEntitas = implode(',', $entitasIds);
        $stmtUp = $pdo->query("SELECT entitas_id, peminatan_id FROM unit_peminatan WHERE entitas_id IN ($inEntitas)");
        while ($row = $stmtUp->fetch(PDO::FETCH_ASSOC)) {
            $masterPeminatanByEntitas[(int)$row['entitas_id']][] = (int)$row['peminatan_id'];
        }
    }

    // Map Periode Snapshot
    $periodeJurusanByUpp = [];
    $periodePeminatanByUpp = [];
    if (!empty($uppIds)) {
        $inUpp = implode(',', $uppIds);
        $stmtUpj = $pdo->query("SELECT unit_pelaksana_periode_id, jurusan_id FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id IN ($inUpp)");
        while ($row = $stmtUpj->fetch(PDO::FETCH_ASSOC)) {
            $periodeJurusanByUpp[(int)$row['unit_pelaksana_periode_id']][] = (int)$row['jurusan_id'];
        }

        $stmtUppem = $pdo->query("SELECT unit_pelaksana_periode_id, peminatan_id FROM unit_periode_peminatan WHERE unit_pelaksana_periode_id IN ($inUpp)");
        while ($row = $stmtUppem->fetch(PDO::FETCH_ASSOC)) {
            $periodePeminatanByUpp[(int)$row['unit_pelaksana_periode_id']][] = (int)$row['peminatan_id'];
        }
    }

    // Pasangkan ke setiap item unit
    foreach ($units as &$u) {
        $eid = (int)$u['entitas_id'];
        $uppId = !empty($u['upp_id']) ? (int)$u['upp_id'] : null;

        // Tentukan prodi_ids: jika ada di periode snapshot gunakan itu; jika tidak ada upp_id atau snapshot belum ada, gunakan default master
        $hasCustomProdi = ($uppId !== null && array_key_exists($uppId, $periodeJurusanByUpp));
        $prodiIds = $hasCustomProdi ? ($periodeJurusanByUpp[$uppId] ?? []) : ($masterJurusanByEntitas[$eid] ?? []);

        // Tentukan peminatan_ids: jika ada di periode snapshot gunakan itu; jika tidak ada, gunakan master
        $hasCustomPeminatan = ($uppId !== null && array_key_exists($uppId, $periodePeminatanByUpp));
        $peminatanIds = $hasCustomPeminatan ? ($periodePeminatanByUpp[$uppId] ?? []) : ($masterPeminatanByEntitas[$eid] ?? []);

        $prodiDetails = [];
        foreach ($prodiIds as $jid) {
            if (isset($jurusanById[$jid])) {
                $prodiDetails[] = [
                    'id' => $jid,
                    'kode' => $jurusanById[$jid]['kode'],
                    'nama_jurusan' => $jurusanById[$jid]['nama_jurusan']
                ];
            }
        }

        $peminatanDetails = [];
        foreach ($peminatanIds as $pid) {
            if (isset($peminatanById[$pid])) {
                $peminatanDetails[] = [
                    'id' => $pid,
                    'nama' => $peminatanById[$pid]['nama']
                ];
            }
        }

        $u['prodi_ids'] = $prodiIds;
        $u['prodi_details'] = $prodiDetails;
        $u['peminatan_ids'] = $peminatanIds;
        $u['peminatan_details'] = $peminatanDetails;
    }
    unset($u);

    echo json_encode([
        'ok' => true,
        'has_periode_aktif' => $hasPeriodeAktif,
        'periode_terpilih' => $periode,
        'periode_aktif' => $periode,
        'all_periode' => $allPeriode,
        'all_jurusan' => $allJurusan,
        'all_peminatan' => $allPeminatan,
        'data' => $units
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat data unit pelaksana.')]);
}


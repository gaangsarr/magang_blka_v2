<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // 0. Ambil list periode
    $stmtAllPeriode = $pdo->query("
        SELECT id, nama, status, pengumuman_dibuka, tanggal_mulai, tanggal_selesai 
        FROM periode 
        ORDER BY id DESC
    ");
    $listPeriode = $stmtAllPeriode->fetchAll(PDO::FETCH_ASSOC);

    // 1. Ambil periode yang berstatus 'dibuka' atau 'persiapan' (utamakan 'dibuka', lalu 'persiapan')
    // Status 'draft', 'ditutup', dan 'diarsipkan' TIDAK akan tampil di dashboard aktif.
    $stmtPeriode = $pdo->query("
        SELECT id, nama, tanggal_mulai, tanggal_selesai, status, pengumuman_dibuka 
        FROM periode 
        WHERE status IN ('dibuka', 'persiapan') 
        ORDER BY FIELD(status, 'dibuka', 'persiapan'), id DESC 
        LIMIT 1
    ");
    $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);
    $periodeId = $periode ? (int)$periode['id'] : null;
    
    // Jika tidak ada periode yang berstatus 'dibuka' atau 'persiapan'
    if (!$periodeId) {
        echo json_encode([
            'ok' => true,
            'periode' => null,
            'data' => [
                'total_pendaftar' => 0,
                'total_kuota' => 0,
                'sisa_kuota' => 0,
                'terisi_kuota' => 0,
                'persen_okupansi' => 0,
                'status_counts' => [
                    'diterima' => 0,
                    'dipindahkan' => 0,
                    'menunggu' => 0,
                    'ditolak' => 0
                ],
                'unit_penuh' => [],
                'unit_butuh_mahasiswa' => [],
                'sebaran_hierarki' => [],
                'sebaran_peminatan' => [],
                'sebaran_jurusan' => []
            ]
        ]);
        exit;
    }

    // Hitung sisa hari pendaftaran
    $today = new \DateTime('today', new \DateTimeZone('Asia/Jakarta'));
    $daysRemaining = 0;
    $isRegistrationActive = false;
    if (!empty($periode['tanggal_selesai'])) {
        $endDate = new \DateTime($periode['tanggal_selesai'], new \DateTimeZone('Asia/Jakarta'));
        if ($endDate >= $today) {
            $diff = $today->diff($endDate);
            $daysRemaining = (int)$diff->format('%a');
            $isRegistrationActive = ($periode['status'] === 'dibuka');
        } else {
            $daysRemaining = 0;
            $isRegistrationActive = false;
        }
    }
    
    // 2. Total pendaftar
    $stmtPendaftar = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE periode_id = ?");
    $stmtPendaftar->execute([$periodeId]);
    $totalPendaftar = (int)$stmtPendaftar->fetchColumn();
    
    // 3. Total & Sisa Kuota
    $stmtKuota = $pdo->prepare("SELECT SUM(kuota_total) as total, SUM(kuota_tersisa) as sisa FROM unit_pelaksana_periode WHERE periode_id = ? AND aktif = 1");
    $stmtKuota->execute([$periodeId]);
    $kuota = $stmtKuota->fetch(PDO::FETCH_ASSOC);
    $totalKuota = (int)($kuota['total'] ?? 0);
    $sisaKuota = (int)($kuota['sisa'] ?? 0);
    $terisiKuota = max(0, $totalKuota - $sisaKuota);
    $persenOkupansi = $totalKuota > 0 ? round(($terisiKuota / $totalKuota) * 100, 1) : 0;

    // 4. Breakdown Status Seleksi
    $stmtStatus = $pdo->prepare("
        SELECT status, is_dipindahkan, COUNT(*) as jml 
        FROM pendaftaran 
        WHERE periode_id = ? 
        GROUP BY status, is_dipindahkan
    ");
    $stmtStatus->execute([$periodeId]);
    $statusRows = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);

    $countDiterima = 0;
    $countDipindahkan = 0;
    $countMenunggu = 0;
    $countDitolak = 0;

    foreach ($statusRows as $row) {
        $st = strtolower(trim((string)$row['status']));
        $isMoved = (int)$row['is_dipindahkan'] === 1;
        $jml = (int)$row['jml'];

        if ($st === 'ditolak') {
            $countDitolak += $jml;
        } elseif ($isMoved || $st === 'dipindahkan') {
            $countDipindahkan += $jml;
        } elseif ($st === 'diterima') {
            $countDiterima += $jml;
        } else {
            $countMenunggu += $jml;
        }
    }

    // 5. Unit Kuota Penuh (100% Terisi)
    $stmtPenuh = $pdo->prepare("
        SELECT e.nama, e.tipe, upp.kuota_total, upp.kuota_tersisa, (upp.kuota_total - upp.kuota_tersisa) as terisi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE upp.periode_id = ? AND upp.aktif = 1 AND upp.kuota_tersisa = 0 AND upp.kuota_total > 0
        ORDER BY upp.kuota_total DESC, e.nama ASC
        LIMIT 5
    ");
    $stmtPenuh->execute([$periodeId]);
    $unitPenuh = $stmtPenuh->fetchAll(PDO::FETCH_ASSOC);

    // 6. Unit Butuh Mahasiswa (Under-capacity / Sisa Kuota Terbesar)
    $stmtUnder = $pdo->prepare("
        SELECT e.nama, e.tipe, upp.kuota_total, upp.kuota_tersisa, (upp.kuota_total - upp.kuota_tersisa) as terisi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE upp.periode_id = ? AND upp.aktif = 1 AND upp.kuota_tersisa > 0
        ORDER BY upp.kuota_tersisa DESC, terisi ASC, e.nama ASC
        LIMIT 5
    ");
    $stmtUnder->execute([$periodeId]);
    $unitButuhMahasiswa = $stmtUnder->fetchAll(PDO::FETCH_ASSOC);

    // 7. Sebaran Partisipasi Level Hierarki PLN
    $stmtHierarki = $pdo->prepare("
        SELECT e.tipe, COUNT(upp.id) as jumlah_unit, SUM(upp.kuota_total) as total_kuota, SUM(upp.kuota_total - upp.kuota_tersisa) as total_terisi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE upp.periode_id = ? AND upp.aktif = 1
        GROUP BY e.tipe
    ");
    $stmtHierarki->execute([$periodeId]);
    $sebaranHierarkiRaw = $stmtHierarki->fetchAll(PDO::FETCH_ASSOC);

    $hierarkiLabels = [
        'holding' => 'Holding (Pusat)',
        'subholding' => 'Subholding',
        'anak_perusahaan' => 'Anak Perusahaan',
        'unit_induk' => 'Unit Induk (UP3/UID)',
        'unit_pelaksana' => 'Unit Pelaksana (ULP)'
    ];

    $sebaranHierarki = [];
    foreach ($hierarkiLabels as $key => $label) {
        $found = null;
        foreach ($sebaranHierarkiRaw as $h) {
            if ($h['tipe'] === $key) {
                $found = $h;
                break;
            }
        }
        $sebaranHierarki[] = [
            'tipe' => $key,
            'label' => $label,
            'jumlah_unit' => $found ? (int)$found['jumlah_unit'] : 0,
            'total_kuota' => $found ? (int)$found['total_kuota'] : 0,
            'total_terisi' => $found ? (int)$found['total_terisi'] : 0
        ];
    }

    // 8. Sebaran Peminatan Bidang Magang (via pivot pendaftaran_peminatan)
    $stmtPeminatan = $pdo->prepare("
        SELECT pm.nama AS peminatan, COUNT(pp.pendaftaran_id) AS jumlah
        FROM pendaftaran_peminatan pp
        JOIN pendaftaran p ON pp.pendaftaran_id = p.id
        JOIN peminatan pm ON pp.peminatan_id = pm.id
        WHERE p.periode_id = ?
        GROUP BY pm.id, pm.nama
        ORDER BY jumlah DESC
        LIMIT 8
    ");
    $stmtPeminatan->execute([$periodeId]);
    $sebaranPeminatan = $stmtPeminatan->fetchAll(PDO::FETCH_ASSOC);

    // 9. Sebaran Pendaftar Per Jurusan / Prodi
    $stmtJurusan = $pdo->prepare("
        SELECT COALESCE(j.nama_jurusan, 'Belum Diisi') AS jurusan, COUNT(p.id) AS jumlah
        FROM pendaftaran p
        JOIN mahasiswa m ON p.mahasiswa_id = m.id
        LEFT JOIN jurusan j ON m.jurusan_id = j.id
        WHERE p.periode_id = ?
        GROUP BY j.id, j.nama_jurusan
        ORDER BY jumlah DESC
    ");
    $stmtJurusan->execute([$periodeId]);
    $sebaranJurusan = $stmtJurusan->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'periode' => [
            'id' => $periodeId,
            'nama' => $periode['nama'],
            'status' => $periode['status'],
            'pengumuman_dibuka' => (int)$periode['pengumuman_dibuka'] === 1,
            'tanggal_mulai' => $periode['tanggal_mulai'],
            'tanggal_selesai' => $periode['tanggal_selesai'],
            'sisa_hari_daftar' => $daysRemaining,
            'is_daftar_aktif' => $isRegistrationActive
        ],
        'list_periode' => $listPeriode,
        'data' => [
            'total_pendaftar' => $totalPendaftar,
            'total_kuota' => $totalKuota,
            'sisa_kuota' => $sisaKuota,
            'terisi_kuota' => $terisiKuota,
            'persen_okupansi' => $persenOkupansi,
            'status_counts' => [
                'diterima' => $countDiterima,
                'dipindahkan' => $countDipindahkan,
                'menunggu' => $countMenunggu,
                'ditolak' => $countDitolak
            ],
            'unit_penuh' => $unitPenuh,
            'unit_butuh_mahasiswa' => $unitButuhMahasiswa,
            'sebaran_hierarki' => $sebaranHierarki,
            'sebaran_peminatan' => $sebaranPeminatan,
            'sebaran_jurusan' => $sebaranJurusan
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat ringkasan dashboard.')]);
}


<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

if (!Auth::isLoggedInAdmin()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();
    
    // Ambil periode aktif ('dibuka' atau 'persiapan')
    $stmtPeriode = $pdo->query("
        SELECT id, nama, tanggal_mulai, tanggal_selesai, status, pengumuman_dibuka 
        FROM periode 
        WHERE status IN ('dibuka', 'persiapan') 
        ORDER BY FIELD(status, 'dibuka', 'persiapan'), id DESC 
        LIMIT 1
    ");
    $periode = $stmtPeriode->fetch(PDO::FETCH_ASSOC);

    $periodeId = $periode ? (int)$periode['id'] : 0;
    $periodeNama = $periode ? $periode['nama'] : 'Tidak Ada Periode Aktif';

    // 1. Hitung KPI
    $stmtPendaftar = $pdo->prepare("SELECT COUNT(*) FROM pendaftaran WHERE periode_id = ?");
    $stmtPendaftar->execute([$periodeId]);
    $totalPendaftar = (int)$stmtPendaftar->fetchColumn();

    $stmtKuota = $pdo->prepare("SELECT SUM(kuota_total) as total, SUM(kuota_tersisa) as sisa FROM unit_pelaksana_periode WHERE periode_id = ? AND aktif = 1");
    $stmtKuota->execute([$periodeId]);
    $kuota = $stmtKuota->fetch(PDO::FETCH_ASSOC);
    $totalKuota = (int)($kuota['total'] ?? 0);
    $sisaKuota = (int)($kuota['sisa'] ?? 0);
    $terisiKuota = max(0, $totalKuota - $sisaKuota);
    $persenOkupansi = $totalKuota > 0 ? round(($terisiKuota / $totalKuota) * 100, 1) : 0;

    // Status counts
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

    // Unit Penuh
    $stmtPenuh = $pdo->prepare("
        SELECT e.nama, e.tipe, upp.kuota_total, upp.kuota_tersisa, (upp.kuota_total - upp.kuota_tersisa) as terisi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE upp.periode_id = ? AND upp.aktif = 1 AND upp.kuota_tersisa = 0 AND upp.kuota_total > 0
        ORDER BY upp.kuota_total DESC, e.nama ASC
    ");
    $stmtPenuh->execute([$periodeId]);
    $unitPenuh = $stmtPenuh->fetchAll(PDO::FETCH_ASSOC);

    // Unit Butuh Mahasiswa (Sisa Kuota Terbesar)
    $stmtUnder = $pdo->prepare("
        SELECT e.nama, e.tipe, upp.kuota_total, upp.kuota_tersisa, (upp.kuota_total - upp.kuota_tersisa) as terisi
        FROM unit_pelaksana_periode upp
        JOIN entitas_perusahaan e ON upp.entitas_id = e.id
        WHERE upp.periode_id = ? AND upp.aktif = 1 AND upp.kuota_tersisa > 0
        ORDER BY upp.kuota_tersisa DESC, terisi ASC, e.nama ASC
    ");
    $stmtUnder->execute([$periodeId]);
    $unitButuhMahasiswa = $stmtUnder->fetchAll(PDO::FETCH_ASSOC);

    // Sebaran Peminatan
    $stmtPeminatan = $pdo->prepare("
        SELECT pm.nama AS peminatan, COUNT(pp.pendaftaran_id) AS jumlah
        FROM pendaftaran_peminatan pp
        JOIN pendaftaran p ON pp.pendaftaran_id = p.id
        JOIN peminatan pm ON pp.peminatan_id = pm.id
        WHERE p.periode_id = ?
        GROUP BY pm.id, pm.nama
        ORDER BY jumlah DESC
    ");
    $stmtPeminatan->execute([$periodeId]);
    $sebaranPeminatan = $stmtPeminatan->fetchAll(PDO::FETCH_ASSOC);

    // Sebaran Jurusan
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

    // Stream CSV
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $periodeNama);
    $filename = 'Laporan_Eksekutif_REMATE_' . $safeName . '_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, ['REMATE — REKRUTMEN MAGANG TALENTA ENERGI']);
    fputcsv($output, ['LAPORAN RINGKASAN EKSEKUTIF & MONITORING SELEKSI']);
    fputcsv($output, ['Periode:', $periodeNama]);
    fputcsv($output, ['Tanggal Unduh:', date('d/m/Y H:i:s') . ' WIB']);
    fputcsv($output, ['Status Pengumuman:', ($periode && $periode['pengumuman_dibuka'] == 1) ? 'SUDAH DIPUBLIKASIKAN (LIVE)' : 'BELUM DIPUBLIKASIKAN (DRAFT)']);
    fputcsv($output, []);

    // Section 1: KPI
    fputcsv($output, ['--- 1. INDIKATOR UTAMA (KPI) ---']);
    fputcsv($output, ['Metrik', 'Nilai']);
    fputcsv($output, ['Total Mahasiswa Mendaftar', $totalPendaftar]);
    fputcsv($output, ['Total Kuota Nasional', $totalKuota]);
    fputcsv($output, ['Kuota Terisi / Dipesan', $terisiKuota]);
    fputcsv($output, ['Sisa Kuota Tersedia', $sisaKuota]);
    fputcsv($output, ['Tingkat Okupansi Kuota (%)', $persenOkupansi . '%']);
    fputcsv($output, []);

    // Section 2: Progres Seleksi
    fputcsv($output, ['--- 2. PROGRES PENETAPAN & SELEKSI MAHASISWA ---']);
    fputcsv($output, ['Status Penetapan', 'Jumlah Mahasiswa', 'Persentase (%)']);
    fputcsv($output, ['Diterima (Sesuai Pilihan Awal)', $countDiterima, $totalPendaftar > 0 ? round(($countDiterima / $totalPendaftar) * 100, 1) . '%' : '0%']);
    fputcsv($output, ['Dipindahkan (Relokasi Unit)', $countDipindahkan, $totalPendaftar > 0 ? round(($countDipindahkan / $totalPendaftar) * 100, 1) . '%' : '0%']);
    fputcsv($output, ['Menunggu Review / Verifikasi Berkas', $countMenunggu, $totalPendaftar > 0 ? round(($countMenunggu / $totalPendaftar) * 100, 1) . '%' : '0%']);
    fputcsv($output, ['Ditolak / Berkas Tidak Memenuhi Syarat', $countDitolak, $totalPendaftar > 0 ? round(($countDitolak / $totalPendaftar) * 100, 1) . '%' : '0%']);
    fputcsv($output, []);

    // Section 3: Unit Penuh
    fputcsv($output, ['--- 3. DAFTAR UNIT PLN KUOTA PENUH (100% TERISI) ---']);
    fputcsv($output, ['No', 'Nama Unit PLN', 'Tingkat Hierarki', 'Total Kuota', 'Terisi', 'Sisa Kuota']);
    if (empty($unitPenuh)) {
        fputcsv($output, ['-', 'Belum ada unit yang terisi penuh 100%', '-', '-', '-', '-']);
    } else {
        $no = 1;
        foreach ($unitPenuh as $up) {
            fputcsv($output, [$no++, $up['nama'], strtoupper(str_replace('_', ' ', (string)$up['tipe'])), $up['kuota_total'], $up['terisi'], $up['kuota_tersisa']]);
        }
    }
    fputcsv($output, []);

    // Section 4: Unit Butuh Mahasiswa
    fputcsv($output, ['--- 4. DAFTAR UNIT PLN DENGAN SISA KUOTA TERBANYAK (REKOMENDASI RELOKASI) ---']);
    fputcsv($output, ['No', 'Nama Unit PLN', 'Tingkat Hierarki', 'Total Kuota', 'Terisi', 'Sisa Kuota']);
    if (empty($unitButuhMahasiswa)) {
        fputcsv($output, ['-', 'Tidak ada sisa kuota yang tersedia', '-', '-', '-', '-']);
    } else {
        $no = 1;
        foreach ($unitButuhMahasiswa as $ub) {
            fputcsv($output, [$no++, $ub['nama'], strtoupper(str_replace('_', ' ', (string)$ub['tipe'])), $ub['kuota_total'], $ub['terisi'], $ub['kuota_tersisa']]);
        }
    }
    fputcsv($output, []);

    // Section 5: Sebaran Peminatan
    fputcsv($output, ['--- 5. DISTRIBUSI PEMINATAN BIDANG MAGANG ---']);
    fputcsv($output, ['No', 'Bidang Minat', 'Jumlah Peminat']);
    if (empty($sebaranPeminatan)) {
        fputcsv($output, ['-', 'Belum ada data peminatan', 0]);
    } else {
        $no = 1;
        foreach ($sebaranPeminatan as $sp) {
            fputcsv($output, [$no++, $sp['peminatan'], $sp['jumlah']]);
        }
    }
    fputcsv($output, []);

    // Section 6: Sebaran Jurusan
    fputcsv($output, ['--- 6. SEBARAN PENDAFTAR PER PROGRAM STUDI ---']);
    fputcsv($output, ['No', 'Program Studi / Jurusan', 'Jumlah Pendaftar']);
    if (empty($sebaranJurusan)) {
        fputcsv($output, ['-', 'Belum ada data pendaftar', 0]);
    } else {
        $no = 1;
        foreach ($sebaranJurusan as $sj) {
            fputcsv($output, [$no++, $sj['jurusan'], $sj['jumlah']]);
        }
    }

    fclose($output);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Gagal mengekspor laporan eksekutif: ' . $e->getMessage();
}

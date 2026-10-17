<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60'); // Cache for 1 minute
}

try {
    $pdo = Database::getInstance();

    $stmt = $pdo->prepare("SELECT kunci, nilai FROM pengaturan WHERE kunci LIKE 'doc_%'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $pedomanTipe = $rows['doc_pedoman_tipe'] ?? 'file';
    $pedomanFile = !empty($rows['doc_pedoman_file']) ? $rows['doc_pedoman_file'] : '/assets/docs/pedoman-magang.pdf';
    $pedomanUrl  = !empty($rows['doc_pedoman_url']) ? $rows['doc_pedoman_url'] : '';
    $pedomanDownload = ($pedomanTipe === 'url' && !empty($pedomanUrl)) ? $pedomanUrl : $pedomanFile;

    $logbookTipe = $rows['doc_logbook_tipe'] ?? 'file';
    $logbookFile = !empty($rows['doc_logbook_file']) ? $rows['doc_logbook_file'] : '/assets/docs/logbook.docx';
    $logbookUrl  = !empty($rows['doc_logbook_url']) ? $rows['doc_logbook_url'] : '';
    $logbookDownload = ($logbookTipe === 'url' && !empty($logbookUrl)) ? $logbookUrl : $logbookFile;

    $data = [
        'pedoman' => [
            'tipe'         => $pedomanTipe,
            'judul'        => $rows['doc_pedoman_judul'] ?? 'Buku Pedoman Magang',
            'meta'         => $rows['doc_pedoman_meta'] ?? 'Panduan Resmi',
            'deskripsi'    => $rows['doc_pedoman_deskripsi'] ?? 'Panduan lengkap alur pendaftaran, persyaratan berkas, hak dan kewajiban mahasiswa, tata tertib, serta kriteria evaluasi program magang bersama PT PLN (Persero).',
            'badge'        => $rows['doc_pedoman_badge'] ?? ($pedomanTipe === 'url' ? 'Cloud' : 'PDF'),
            'btn_text'     => $rows['doc_pedoman_btn_text'] ?? ($pedomanTipe === 'url' ? 'Buka Dokumen' : 'Buka Dokumen PDF'),
            'file'         => $pedomanFile,
            'external_url' => $pedomanUrl,
            'download_url' => $pedomanDownload,
        ],
        'logbook' => [
            'tipe'         => $logbookTipe,
            'judul'        => $rows['doc_logbook_judul'] ?? 'Buku Catatan / Log Book',
            'meta'         => $rows['doc_logbook_meta'] ?? 'Format Laporan',
            'deskripsi'    => $rows['doc_logbook_deskripsi'] ?? 'Format resmi pencatatan aktivitas harian, rencana kerja mingguan, lembar bimbingan mentor lapangan, dan rekapitulasi kehadiran selama masa penempatan.',
            'badge'        => $rows['doc_logbook_badge'] ?? ($logbookTipe === 'url' ? 'Cloud' : 'Word'),
            'btn_text'     => $rows['doc_logbook_btn_text'] ?? 'Buka Dokumen',
            'file'         => $logbookFile,
            'external_url' => $logbookUrl,
            'download_url' => $logbookDownload,
        ]
    ];

    echo json_encode([
        'ok'   => true,
        'data' => $data,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Gagal memuat dokumen panduan.',
    ]);
}

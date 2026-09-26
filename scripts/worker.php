<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/process_email_queue.php';

use Dotenv\Dotenv;
use App\Database;
use App\ReservasiHelper;
use App\PeriodeHelper;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

try {
    $pdo = Database::getInstance();

    // 1. Pengecekan Jam Tutup Periode Otomatis (Failsafe Jam Server WIB)
    try {
        $closedIds = PeriodeHelper::closeExpiredPeriodes($pdo);
        if (!empty($closedIds)) {
            echo "[" . date('Y-m-d H:i:s') . "] [Periode] Otomatis menutup periode melewati jadwal: " . implode(', ', $closedIds) . "\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] [Periode Error] " . $e->getMessage() . "\n";
    }

    // 2. Pembersihan Reservasi Kadaluarsa (Pengembalian Kuota Tertahan)
    try {
        $count = ReservasiHelper::cleanupExpired($pdo);
        if ($count > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] [Reservasi] Mengembalikan kuota untuk {$count} reservasi kadaluarsa.\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] [Reservasi Error] " . $e->getMessage() . "\n";
    }

    // 3. Pemrosesan Antrean Pengiriman Email (Rate-Limit Safe)
    $emailRes = processEmailQueue();
    if (($emailRes['processed'] ?? 0) > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] [EmailQueue] Selesai batch: {$emailRes['success']} sukses, {$emailRes['failed']} gagal.\n";
    }

    // Heartbeat log: tampilkan status ringkas agar di terminal terlihat aktif berjalan
    $hasActivity = !empty($closedIds) || $count > 0 || (($emailRes['processed'] ?? 0) > 0);
    if (!$hasActivity) {
        $pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'")->fetchColumn();
        echo "[" . date('Y-m-d H:i:s') . "] [Worker] Siap (0 reservasi kadaluarsa, {$pendingCount} email antre)\n";
    }

} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [Worker Master Error] " . $e->getMessage() . "\n";
}

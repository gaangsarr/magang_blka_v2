<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Mailer;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

function processEmailQueue(int $batchLimit = 10): array
{
    $timestamp = date('Y-m-d H:i:s');

    if (!Mailer::isConfigured()) {
        return ['processed' => 0, 'success' => 0, 'failed' => 0, 'message' => 'SMTP belum dikonfigurasi di .env.'];
    }

    try {
        $pdo = Database::getInstance();

        // 1. Auto-Recovery: Kembalikan job yang tertahan di 'processing' lebih dari 5 menit (mis. jika worker terhenti mendadak)
        $pdo->query("
            UPDATE email_queue 
            SET status = 'pending', updated_at = NOW() 
            WHERE status = 'processing' AND updated_at < (NOW() - INTERVAL 5 MINUTE)
        ");

        $stmt = $pdo->prepare("
            SELECT id, to_email, to_name, subject, body_html, body_text, attempts, max_attempts
            FROM email_queue
            WHERE status = 'pending' AND attempts < max_attempts
            ORDER BY id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $batchLimit, PDO::PARAM_INT);
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($jobs)) {
            return ['processed' => 0, 'success' => 0, 'failed' => 0, 'message' => 'Antrean email kosong.'];
        }

        $totalJobs = count($jobs);
        echo "[{$timestamp}] [EmailQueue] Ditemukan {$totalJobs} email dalam antrean. Memproses...\n";

        $successCount = 0;
        $failCount = 0;

        $stmtClaim = $pdo->prepare("UPDATE email_queue SET status = 'processing', updated_at = NOW() WHERE id = ? AND status = 'pending'");

        foreach ($jobs as $job) {
            $jobId = (int)$job['id'];
            $toEmail = trim($job['to_email']);
            $toName = trim($job['to_name'] ?? '');
            $attempts = (int)$job['attempts'] + 1;
            $maxAttempts = (int)$job['max_attempts'];

            // Atomic Claim: Pastikan job masih 'pending' dan belum diklaim oleh worker paralel lain
            $stmtClaim->execute([$jobId]);
            if ($stmtClaim->rowCount() === 0) {
                // Job telah diambil / diproses oleh worker lain
                continue;
            }

            // Kirim via PHPMailer
            $result = Mailer::send($toEmail, $toName, $job['subject'], $job['body_html'], $job['body_text'] ?? '');

            if ($result['ok']) {
                $pdo->prepare("
                    UPDATE email_queue 
                    SET status = 'sent', sent_at = NOW(), attempts = ?, updated_at = NOW() 
                    WHERE id = ?
                ")->execute([$attempts, $jobId]);

                echo "  [+] [ID: {$jobId}] Sukses kirim ke: {$toEmail}\n";
                $successCount++;
            } else {
                $errorMsg = substr($result['error'] ?? 'Gagal mengirim email.', 0, 500);
                $newStatus = ($attempts >= $maxAttempts) ? 'failed' : 'pending';

                $pdo->prepare("
                    UPDATE email_queue 
                    SET status = ?, attempts = ?, last_error = ?, updated_at = NOW() 
                    WHERE id = ?
                ")->execute([$newStatus, $attempts, $errorMsg, $jobId]);

                echo "  [-] [ID: {$jobId}] GAGAL ke: {$toEmail} (Attempt {$attempts}/{$maxAttempts}) - Error: {$errorMsg}\n";
                $failCount++;
            }

            // Pacing: Jeda 2 detik antar email untuk mematuhi rate-limit (maks ~30 email/menit)
            if ($totalJobs > 1) {
                sleep(2);
            }
        }

        return ['processed' => $totalJobs, 'success' => $successCount, 'failed' => $failCount];
    } catch (\Throwable $e) {
        echo "[-] Terjadi kesalahan pada worker antrean email: " . $e->getMessage() . "\n";
        return ['processed' => 0, 'success' => 0, 'failed' => 0, 'error' => $e->getMessage()];
    }
}

// Jika dijalankan langsung dari terminal
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $res = processEmailQueue();
    if (!empty($res['message']) && $res['processed'] === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] {$res['message']}\n";
    }
}

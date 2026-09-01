<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

$root = dirname(__DIR__, 2);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireMahasiswaApi();
Auth::requireCsrfApi();
Auth::rateLimit('reservasi', 5, 60); // Max 5 request per menit

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!isset($body['upp_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'upp_id wajib diisi.']);
    exit;
}

$uppId = (int)$body['upp_id'];
$mahasiswaId = Auth::getMahasiswaId();

try {
    $result = Database::transaction(function (PDO $pdo) use ($mahasiswaId, $uppId) {
        // 0. Auto-cleanup reservasi kadaluarsa di sistem agar kuota yang tertahan lama otomatis kembali
        $pdo->exec("
            UPDATE unit_pelaksana_periode upp
            JOIN (
                SELECT unit_pelaksana_periode_id, COUNT(*) AS jumlah
                FROM reservasi
                WHERE status = 'ditahan' AND expired_at < NOW()
                GROUP BY unit_pelaksana_periode_id
            ) r ON upp.id = r.unit_pelaksana_periode_id
            SET upp.kuota_tersisa = LEAST(upp.kuota_total, upp.kuota_tersisa + r.jumlah);
            
            UPDATE reservasi SET status = 'kadaluarsa' WHERE status = 'ditahan' AND expired_at < NOW();
        ");

        // 1. Batalkan reservasi sebelumnya milik mahasiswa ini yang masih 'ditahan'
        $stmtCekRes = $pdo->prepare("SELECT id, unit_pelaksana_periode_id FROM reservasi WHERE mahasiswa_id = :mid AND status = 'ditahan' AND expired_at > NOW()");
        $stmtCekRes->execute([':mid' => $mahasiswaId]);
        $oldReservations = $stmtCekRes->fetchAll(PDO::FETCH_ASSOC);

        foreach ($oldReservations as $old) {
            // Batalkan
            $pdo->prepare("UPDATE reservasi SET status = 'dibatalkan' WHERE id = :id")->execute([':id' => $old['id']]);
            // Kembalikan kuota (tidak boleh melebihi kuota_total)
            $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = LEAST(kuota_total, kuota_tersisa + 1) WHERE id = :upp_id")->execute([':upp_id' => $old['unit_pelaksana_periode_id']]);
        }

        // 2. Kunci row unit_pelaksana_periode untuk cek kuota dan periode
        $stmtUpp = $pdo->prepare("
            SELECT upp.kuota_tersisa, upp.kuota_total, upp.periode_id, pr.nama AS nama_periode, pr.status AS status_periode, pr.angkatan_eligible
            FROM unit_pelaksana_periode upp 
            JOIN periode pr ON upp.periode_id = pr.id
            WHERE upp.id = :upp_id FOR UPDATE
        ");
        $stmtUpp->execute([':upp_id' => $uppId]);
        $upp = $stmtUpp->fetch(PDO::FETCH_ASSOC);

        if (!$upp) {
            throw new \Exception('Unit pelaksana tidak ditemukan.');
        }

        if ($upp['status_periode'] !== 'dibuka') {
            throw new \Exception('Periode magang untuk unit ini tidak sedang dibuka.');
        }

        if (!empty($upp['angkatan_eligible'])) {
            $mhsData = Auth::getMahasiswa();
            $mhsAngkatan = (int)($mhsData['angkatan'] ?? 0);
            $fullAngkatan = $mhsAngkatan < 100 ? (2000 + $mhsAngkatan) : $mhsAngkatan;
            $eligibleList = array_map('trim', explode(',', $upp['angkatan_eligible']));
            if (!in_array((string)$fullAngkatan, $eligibleList, true) && !in_array((string)$mhsAngkatan, $eligibleList, true)) {
                throw new \Exception("Pendaftaran periode {$upp['nama_periode']} dikhususkan untuk Angkatan " . implode(', ', $eligibleList) . ".");
            }
        }

        if ((int)$upp['kuota_tersisa'] <= 0) {
            throw new \Exception('Maaf, kuota untuk unit pelaksana ini sudah habis atau sedang direservasi orang lain.');
        }

        // 3. Kurangi kuota (dijaga tidak boleh kurang dari 0)
        $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = GREATEST(0, kuota_tersisa - 1), updated_at = NOW() WHERE id = :upp_id")->execute([':upp_id' => $uppId]);

        // 4. Buat reservasi baru (baca durasi dari .env, default 10 menit jika belum diset)
        $reservationMinutes = max(1, (int)($_ENV['RESERVATION_MINUTES'] ?? 10));
        $expiredTimestamp = time() + ($reservationMinutes * 60);
        $expiredAt = date('Y-m-d H:i:s', $expiredTimestamp);

        $stmtInsert = $pdo->prepare("INSERT INTO reservasi (mahasiswa_id, unit_pelaksana_periode_id, status, expired_at, created_at) VALUES (:mid, :upp_id, 'ditahan', :expired_at, NOW())");
        $stmtInsert->execute([':mid' => $mahasiswaId, ':upp_id' => $uppId, ':expired_at' => $expiredAt]);
        $reservasiId = (int)$pdo->lastInsertId();

        return [
            'reservasi_id'   => $reservasiId,
            'expired_at'     => date('c', $expiredTimestamp), // Format ISO 8601 (e.g. 2026-09-01T23:25:00+07:00)
            'expired_at_ms'  => $expiredTimestamp * 1000,
            'expired_at_raw' => $expiredAt
        ];
    });

    echo json_encode([
        'ok' => true,
        'data' => $result
    ]);
} catch (\Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Terjadi kesalahan sistem.']);
}

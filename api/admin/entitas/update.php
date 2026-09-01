<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;

Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');
Auth::requireAdminApi();
Auth::requireCsrfApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method tidak diizinkan.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

$id        = isset($body['id']) ? (int)$body['id'] : null;
$nama      = trim($body['nama'] ?? '');
$singkatan = trim($body['singkatan'] ?? '') ?: null;
$alamat    = trim($body['alamat'] ?? '') ?: null;
$lat       = isset($body['latitude'])  && $body['latitude']  !== '' ? (float)$body['latitude']  : null;
$lng       = isset($body['longitude']) && $body['longitude'] !== '' ? (float)$body['longitude'] : null;
$aktif     = isset($body['aktif']) ? (int)(bool)$body['aktif'] : 1;

if (!$id || empty($nama)) {
    http_response_code(400);
    echo json_encode(['error' => 'id dan nama wajib diisi.']);
    exit;
}

try {
    $pdo = Database::getInstance();

    // Ambil data existing
    $stmtGet = $pdo->prepare("SELECT id, tipe, menerima_magang FROM entitas_perusahaan WHERE id = ?");
    $stmtGet->execute([$id]);
    $existing = $stmtGet->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        http_response_code(404);
        echo json_encode(['error' => 'Entitas tidak ditemukan.']);
        exit;
    }

    $menerimaMagang = isset($body['menerima_magang']) 
        ? (int)(bool)$body['menerima_magang'] 
        : (int)$existing['menerima_magang'];

    // Ambil peminatan_ids jika dikirim
    $hasPeminatanPayload = isset($body['peminatan_ids']);
    $peminatanIds = $hasPeminatanPayload && is_array($body['peminatan_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $body['peminatan_ids']))))
        : [];

    // Ambil prodi_ids jika dikirim
    $hasProdiPayload = isset($body['prodi_ids']);
    $prodiIds = $hasProdiPayload && is_array($body['prodi_ids'])
        ? array_values(array_unique(array_filter(array_map('intval', $body['prodi_ids']))))
        : [];

    // Jika menerima magang, pastikan ada minimal 1 peminatan
    if ($menerimaMagang === 1) {
        if ($hasPeminatanPayload && empty($peminatanIds)) {
            http_response_code(400);
            echo json_encode(['error' => 'Entitas yang menerima magang wajib memiliki minimal 1 peminatan.']);
            exit;
        } elseif (!$hasPeminatanPayload) {
            // Cek apakah di DB sudah punya peminatan
            $stmtCekPem = $pdo->prepare("SELECT COUNT(*) FROM unit_peminatan WHERE entitas_id = ?");
            $stmtCekPem->execute([$id]);
            if ((int)$stmtCekPem->fetchColumn() === 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Entitas yang menerima magang wajib memiliki minimal 1 peminatan. Pilih peminatan terlebih dahulu.']);
                exit;
            }
        }
    }

    Database::transaction(function (PDO $pdo) use (
        $id, $nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang, 
        $hasPeminatanPayload, $peminatanIds, $hasProdiPayload, $prodiIds
    ) {
        // Update entitas — tipe dan parent_id tetap dipertahankan
        $stmt = $pdo->prepare("
            UPDATE entitas_perusahaan
            SET nama = ?, singkatan = ?, alamat = ?, latitude = ?, longitude = ?, aktif = ?, menerima_magang = ?
            WHERE id = ?
        ");
        $stmt->execute([$nama, $singkatan, $alamat, $lat, $lng, $aktif, $menerimaMagang, $id]);

        // Update relasi peminatan jika payload dikirimkan
        if ($hasPeminatanPayload) {
            $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDel->execute([$id]);

            if ($menerimaMagang === 1 && !empty($peminatanIds)) {
                $stmtPem = $pdo->prepare(
                    "INSERT INTO unit_peminatan (entitas_id, peminatan_id) VALUES (?, ?)"
                );
                foreach ($peminatanIds as $pid) {
                    $stmtPem->execute([$id, $pid]);
                }
            }
        } elseif ($menerimaMagang === 0) {
            // Jika menerima_magang dimatikan, bersihkan relasi peminatan
            $stmtDel = $pdo->prepare("DELETE FROM unit_peminatan WHERE entitas_id = ?");
            $stmtDel->execute([$id]);
        }

        // Update relasi default prodi jika payload dikirimkan
        if ($hasProdiPayload) {
            $stmtDelJur = $pdo->prepare("DELETE FROM unit_jurusan WHERE entitas_id = ?");
            $stmtDelJur->execute([$id]);

            if ($menerimaMagang === 1 && !empty($prodiIds)) {
                $stmtJur = $pdo->prepare(
                    "INSERT INTO unit_jurusan (entitas_id, jurusan_id) VALUES (?, ?)"
                );
                foreach ($prodiIds as $jid) {
                    $stmtJur->execute([$id, $jid]);
                }
            }
        } elseif ($menerimaMagang === 0) {
            $stmtDelJur = $pdo->prepare("DELETE FROM unit_jurusan WHERE entitas_id = ?");
            $stmtDelJur->execute([$id]);
        }

        // Sinkronkan ke periode aktif/persiapan jika ada unit_pelaksana_periode
        $stmtActiveUpp = $pdo->prepare("
            SELECT upp.id 
            FROM unit_pelaksana_periode upp
            JOIN periode pr ON upp.periode_id = pr.id
            WHERE upp.entitas_id = ? AND pr.status IN ('dibuka', 'persiapan')
        ");
        $stmtActiveUpp->execute([$id]);
        $activeUppIds = $stmtActiveUpp->fetchAll(PDO::FETCH_COLUMN);

        foreach ($activeUppIds as $auppId) {
            $auppId = (int)$auppId;
            if ($hasProdiPayload) {
                $pdo->prepare("DELETE FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = ?")->execute([$auppId]);
                if ($menerimaMagang === 1 && !empty($prodiIds)) {
                    $stmtInsUpj = $pdo->prepare("INSERT INTO unit_periode_jurusan (unit_pelaksana_periode_id, jurusan_id) VALUES (?, ?)");
                    foreach ($prodiIds as $jid) {
                        $stmtInsUpj->execute([$auppId, $jid]);
                    }
                }
            }
            if ($hasPeminatanPayload) {
                $pdo->prepare("DELETE FROM unit_periode_peminatan WHERE unit_pelaksana_periode_id = ?")->execute([$auppId]);
                if ($menerimaMagang === 1 && !empty($peminatanIds)) {
                    $stmtInsUppem = $pdo->prepare("INSERT INTO unit_periode_peminatan (unit_pelaksana_periode_id, peminatan_id) VALUES (?, ?)");
                    foreach ($peminatanIds as $pid) {
                        $stmtInsUppem->execute([$auppId, $pid]);
                    }
                }
            }
        }
    });

    echo json_encode([
        'ok'      => true,
        'message' => 'Entitas berhasil diperbarui.',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memperbarui data entitas.')]);
}


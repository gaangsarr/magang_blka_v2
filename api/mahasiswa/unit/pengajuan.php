<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Database;
use App\Auth;
use App\PengajuanUnitHelper;

$root = dirname(__DIR__, 3);
Dotenv::createImmutable($root)->safeLoad();

header('Content-Type: application/json; charset=utf-8');

// Guard auth mahasiswa
Auth::requireMahasiswaApi();
$pdo = Database::getInstance();
$mhs = Auth::getMahasiswa();
$mhsId = Auth::getMahasiswaId();

$method = $_SERVER['REQUEST_METHOD'];

// =========================================================================
// 1. GET: Ambil daftar pengajuan milik mahasiswa yang sedang login
// =========================================================================
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.jenis,
                p.status,
                p.alasan_admin,
                p.created_at,
                p.updated_at,
                p.entitas_id,
                e.nama AS entitas_nama,
                e.singkatan AS entitas_singkatan,
                e.tipe AS entitas_tipe,
                e.alamat AS entitas_alamat,
                p.usulan_induk_id,
                p.nama_unit_baru,
                p.alamat_unit_baru,
                p.pic_nama,
                p.pic_kontak,
                p.catatan,
                
                -- Info usulan induk jika jenis = dukungan_usulan
                induk.nama_unit_baru AS induk_nama_unit,
                induk.alamat_unit_baru AS induk_alamat_unit,
                
                -- Jumlah pendukung jika usulan unit baru
                (SELECT COUNT(*) FROM pengajuan_unit_mahasiswa p2 
                 WHERE p2.usulan_induk_id = p.id AND p2.status IN ('menunggu', 'diproses')) AS jumlah_pendukung

            FROM pengajuan_unit_mahasiswa p
            LEFT JOIN entitas_perusahaan e ON e.id = p.entitas_id
            LEFT JOIN pengajuan_unit_mahasiswa induk ON induk.id = p.usulan_induk_id
            WHERE p.mahasiswa_id = :mid
            ORDER BY 
                CASE p.status 
                    WHEN 'menunggu' THEN 1 
                    WHEN 'diproses' THEN 2 
                    WHEN 'disetujui' THEN 3 
                    WHEN 'ditolak' THEN 4 
                    ELSE 5 
                END ASC,
                p.id DESC
        ");
        $stmt->execute([':mid' => $mhsId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $activeCount = 0;
        $items = [];
        foreach ($rows as $r) {
            $status = $r['status'];
            if (in_array($status, ['menunggu', 'diproses'], true)) {
                $activeCount++;
            }

            $item = [
                'id'           => (int)$r['id'],
                'jenis'        => $r['jenis'],
                'status'       => $status,
                'alasan_admin' => $r['alasan_admin'],
                'created_at'   => $r['created_at'],
                'can_cancel'   => ($status === 'menunggu'),
            ];

            if ($r['jenis'] === 'request_kuota') {
                $item['unit'] = [
                    'id'        => (int)$r['entitas_id'],
                    'nama'      => $r['entitas_nama'],
                    'singkatan' => $r['entitas_singkatan'],
                    'tipe'      => $r['entitas_tipe'],
                    'alamat'    => $r['entitas_alamat'],
                ];
            } elseif ($r['jenis'] === 'usulan_unit_baru') {
                $item['usulan'] = [
                    'nama_unit'        => $r['nama_unit_baru'],
                    'alamat'           => $r['alamat_unit_baru'],
                    'pic_nama'         => $r['pic_nama'],
                    'pic_kontak'       => $r['pic_kontak'],
                    'catatan'          => $r['catatan'],
                    'jumlah_pendukung' => (int)$r['jumlah_pendukung'] + 1,
                ];
            } elseif ($r['jenis'] === 'dukungan_usulan') {
                $item['dukungan'] = [
                    'usulan_induk_id' => (int)$r['usulan_induk_id'],
                    'nama_unit'       => $r['induk_nama_unit'] ?: $r['nama_unit_baru'],
                    'alamat'          => $r['induk_alamat_unit'] ?: $r['alamat_unit_baru'],
                ];
            }

            $items[] = $item;
        }

        echo json_encode([
            'ok'           => true,
            'active_count' => $activeCount,
            'max_limit'    => 3,
            'data'         => $items
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memuat daftar pengajuan.')]);
        exit;
    }
}

// =========================================================================
// 2. POST: Kirim pengajuan baru
// =========================================================================
if ($method === 'POST') {
    Auth::requireCsrfApi();
    Auth::rateLimitByIdentity((string)$mhsId, 'mhs_submit_pengajuan', 5, 60);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['error' => 'Format data tidak valid.']);
        exit;
    }

    $jenis = trim((string)($input['jenis'] ?? ''));
    if (!in_array($jenis, ['request_kuota', 'usulan_unit_baru', 'dukungan_usulan'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Jenis pengajuan tidak dikenal.']);
        exit;
    }

    $periodeAcuan = PengajuanUnitHelper::getPeriodeAcuan($pdo);
    $periodeId = $periodeAcuan ? (int)$periodeAcuan['id'] : null;

    try {
        $pdo->beginTransaction();

        // Lock baris mahasiswa untuk mencegah race condition / double submit bersamaan
        $stmtLock = $pdo->prepare("SELECT id FROM mahasiswa WHERE id = :mid FOR UPDATE");
        $stmtLock->execute([':mid' => $mhsId]);

        // Cek batas maksimal 3 pengajuan aktif
        $activeCount = PengajuanUnitHelper::countActive($pdo, $mhsId);
        if ($activeCount >= 3) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode([
                'error' => 'Batas maksimal 3 pengajuan aktif tercapai. Tunggu pengajuan sebelumnya diproses atau batalkan salah satu pengajuan untuk mengajukan unit lain.'
            ]);
            exit;
        }

        // -----------------------------------------------------------------
        // CASE A: Request Kuota untuk Unit Terdaftar
        // -----------------------------------------------------------------
        if ($jenis === 'request_kuota') {
            $entitasId = (int)($input['entitas_id'] ?? 0);
            if ($entitasId <= 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'ID unit PLN wajib dipilih.']);
                exit;
            }

            // Validasi entitas aktif
            $stmtEnt = $pdo->prepare("SELECT id, nama, aktif FROM entitas_perusahaan WHERE id = :id");
            $stmtEnt->execute([':id' => $entitasId]);
            $ent = $stmtEnt->fetch(PDO::FETCH_ASSOC);
            if (!$ent || empty($ent['aktif'])) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Unit PLN tidak ditemukan atau sedang nonaktif.']);
                exit;
            }

            // Cek apakah unit sebenarnya sudah memiliki kuota buka di periode acuan
            if ($periodeId) {
                $stmtUpp = $pdo->prepare("
                    SELECT id, kuota_tersisa 
                    FROM unit_pelaksana_periode 
                    WHERE entitas_id = :eid AND periode_id = :pid AND aktif = 1
                ");
                $stmtUpp->execute([':eid' => $entitasId, ':pid' => $periodeId]);
                $upp = $stmtUpp->fetch(PDO::FETCH_ASSOC);
                if ($upp && (int)$upp['kuota_tersisa'] > 0) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error' => "Unit {$ent['nama']} telah memiliki kuota yang dibuka untuk periode aktif saat ini. Anda dapat langsung mendaftar melalui halaman Pendaftaran."
                    ]);
                    exit;
                }
            }

            // Cek anti-duplikasi pengajuan unit yang sama
            if (PengajuanUnitHelper::hasActiveRequestForUnit($pdo, $mhsId, $entitasId)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode([
                    'error' => "Anda sudah memiliki pengajuan aktif untuk unit {$ent['nama']}."
                ]);
                exit;
            }

            // Insert pengajuan request_kuota
            $stmtIns = $pdo->prepare("
                INSERT INTO pengajuan_unit_mahasiswa (
                    mahasiswa_id, periode_id, jenis, entitas_id, status, created_at, updated_at
                ) VALUES (
                    :mid, :pid, 'request_kuota', :eid, 'menunggu', NOW(), NOW()
                )
            ");
            $stmtIns->execute([
                ':mid' => $mhsId,
                ':pid' => $periodeId,
                ':eid' => $entitasId
            ]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();

            echo json_encode([
                'ok'      => true,
                'id'      => $newId,
                'message' => "Permintaan kuota untuk unit {$ent['nama']} berhasil diajukan ke BLKA."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // -----------------------------------------------------------------
        // CASE B: Usulan Unit Baru (Belum Terdaftar)
        // -----------------------------------------------------------------
        if ($jenis === 'usulan_unit_baru') {
            $namaUnit = trim((string)($input['nama_unit'] ?? ''));
            $alamat   = trim((string)($input['alamat'] ?? ''));
            $picNama  = trim((string)($input['pic_nama'] ?? ''));
            $picWaRaw = trim((string)($input['pic_kontak'] ?? ''));
            $picEmail = trim((string)($input['pic_email'] ?? ''));
            $catatan  = trim((string)($input['catatan'] ?? ''));
            $konfirmasiBeda = !empty($input['konfirmasi_beda']);

            // Validasi field wajib
            if ($namaUnit === '' || mb_strlen($namaUnit, 'UTF-8') < 3) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Nama unit wajib diisi minimal 3 karakter.']);
                exit;
            }
            if ($alamat === '' || mb_strlen($alamat, 'UTF-8') < 5) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Alamat atau wilayah unit wajib diisi minimal 5 karakter.']);
                exit;
            }
            if ($picNama === '' || mb_strlen($picNama, 'UTF-8') < 2) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Nama PIC Narahubung Unit wajib diisi.']);
                exit;
            }
            if ($catatan === '' || mb_strlen($catatan, 'UTF-8') < 5) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Catatan/alasan pengusulan wajib diisi (misal: sudah koordinasi lisan dengan PIC).']);
                exit;
            }

            // Normalisasi & validasi No WA PIC
            $picKontak = PengajuanUnitHelper::normalizeWa($picWaRaw);
            if (!$picKontak) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Nomor WhatsApp PIC tidak valid. Masukkan nomor HP/WA Indonesia yang benar (contoh: 08123456789).']);
                exit;
            }

            // Validasi email jika diisi
            if ($picEmail !== '' && !filter_var($picEmail, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'Format email PIC tidak valid.']);
                exit;
            }

            // Batasi panjang karakter
            $namaUnit  = mb_substr($namaUnit, 0, 150, 'UTF-8');
            $alamat    = mb_substr($alamat, 0, 500, 'UTF-8');
            $picNama   = mb_substr($picNama, 0, 150, 'UTF-8');
            $picEmail  = $picEmail !== '' ? mb_substr($picEmail, 0, 150, 'UTF-8') : null;
            $catatan   = mb_substr($catatan, 0, 1000, 'UTF-8');

            $namaNormal = PengajuanUnitHelper::normalizeNama($namaUnit);

            // Cek proteksi server-side: kemiripan tinggi dengan entitas/usulan yang sudah ada
            if (!$konfirmasiBeda) {
                $similarList = PengajuanUnitHelper::findSimilar($pdo, $namaUnit, 3);
                if (!empty($similarList) && $similarList[0]['skor'] >= 0.88) {
                    $pdo->rollBack();
                    http_response_code(409);
                    echo json_encode([
                        'error'           => 'Unit yang Anda usulkan terdeteksi mirip dengan data yang sudah ada di sistem.',
                        'need_confirm'    => true,
                        'kandidat_mirip'  => $similarList
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            // Insert usulan_unit_baru
            $stmtIns = $pdo->prepare("
                INSERT INTO pengajuan_unit_mahasiswa (
                    mahasiswa_id, periode_id, jenis,
                    nama_unit_baru, nama_unit_normal, alamat_unit_baru,
                    pic_nama, pic_kontak, pic_email, catatan,
                    status, created_at, updated_at
                ) VALUES (
                    :mid, :pid, 'usulan_unit_baru',
                    :nama, :normal, :alamat,
                    :pic_nama, :pic_kontak, :pic_email, :catatan,
                    'menunggu', NOW(), NOW()
                )
            ");
            $stmtIns->execute([
                ':mid'        => $mhsId,
                ':pid'        => $periodeId,
                ':nama'       => $namaUnit,
                ':normal'     => $namaNormal,
                ':alamat'     => $alamat,
                ':pic_nama'   => $picNama,
                ':pic_kontak' => $picKontak,
                ':pic_email'  => $picEmail,
                ':catatan'    => $catatan,
            ]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();

            echo json_encode([
                'ok'      => true,
                'id'      => $newId,
                'message' => "Usulan unit baru '{$namaUnit}' berhasil dikirimkan ke Admin BLKA untuk ditindaklanjuti."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // -----------------------------------------------------------------
        // CASE C: Dukungan Usulan Unit Baru yang Sudah Ada
        // -----------------------------------------------------------------
        if ($jenis === 'dukungan_usulan') {
            $usulanIndukId = (int)($input['usulan_induk_id'] ?? 0);
            if ($usulanIndukId <= 0) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['error' => 'ID usulan unit wajib disertakan.']);
                exit;
            }

            // Validasi usulan induk aktif
            $stmtInduk = $pdo->prepare("
                SELECT id, nama_unit_baru, status 
                FROM pengajuan_unit_mahasiswa 
                WHERE id = :id AND jenis = 'usulan_unit_baru'
            ");
            $stmtInduk->execute([':id' => $usulanIndukId]);
            $induk = $stmtInduk->fetch(PDO::FETCH_ASSOC);

            if (!$induk || !in_array($induk['status'], ['menunggu', 'diproses'], true)) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Usulan unit tidak ditemukan atau sudah selesai diproses.']);
                exit;
            }

            // Cek anti-duplikasi: apakah mahasiswa sudah mengusulkan atau mendukung usulan ini
            if (PengajuanUnitHelper::hasActiveRequestForProposal($pdo, $mhsId, $usulanIndukId)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['error' => "Anda sudah tercatat sebagai pengusul atau pendukung untuk unit {$induk['nama_unit_baru']}."]);
                exit;
            }

            // Insert dukungan_usulan
            $stmtIns = $pdo->prepare("
                INSERT INTO pengajuan_unit_mahasiswa (
                    mahasiswa_id, periode_id, jenis, usulan_induk_id,
                    nama_unit_baru, status, created_at, updated_at
                ) VALUES (
                    :mid, :pid, 'dukungan_usulan', :uid,
                    :nama, 'menunggu', NOW(), NOW()
                )
            ");
            $stmtIns->execute([
                ':mid'  => $mhsId,
                ':pid'  => $periodeId,
                ':uid'  => $usulanIndukId,
                ':nama' => $induk['nama_unit_baru']
            ]);
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();

            echo json_encode([
                'ok'      => true,
                'id'      => $newId,
                'message' => "Dukungan Anda untuk unit '{$induk['nama_unit_baru']}' berhasil dicatat. BLKA dapat melihat tingginya minat mahasiswa pada unit ini."
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Permintaan tidak dapat diproses.']);

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal memproses pengajuan.')]);
    }
}

// =========================================================================
// 3. DELETE: Batalkan pengajuan sendiri (hanya status 'menunggu')
// =========================================================================
if ($method === 'DELETE') {
    Auth::requireCsrfApi();

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID pengajuan tidak valid.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE pengajuan_unit_mahasiswa 
            SET status = 'dibatalkan', updated_at = NOW()
            WHERE id = :id AND mahasiswa_id = :mid AND status = 'menunggu'
        ");
        $stmt->execute([':id' => $id, ':mid' => $mhsId]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Pengajuan tidak ditemukan atau sudah tidak dapat dibatalkan karena sedang diproses BLKA.']);
            exit;
        }

        echo json_encode([
            'ok'      => true,
            'message' => 'Pengajuan berhasil dibatalkan. Kuota pengajuan Anda telah dikembalikan.'
        ]);
        exit;

    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => Auth::safeErrorMessage($e, 'Gagal membatalkan pengajuan.')]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method tidak diizinkan.']);

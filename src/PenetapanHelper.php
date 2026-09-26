<?php
declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class PenetapanHelper
{
    /**
     * Setujui pendaftaran mahasiswa (status: diterima)
     */
    public static function terimaPeserta(
        PDO $pdo,
        int $pendaftaranId,
        string $catatan,
        int $adminId,
        string $role = 'admin_blka',
        ?int $companyEntitasId = null
    ): void {
        // Lock pendaftaran
        $stmt = $pdo->prepare("
            SELECT p.id, p.mahasiswa_id, p.periode_id, p.unit_pelaksana_periode_id, p.unit_pelaksana_periode_asal_id,
                   p.status, p.is_dipindahkan, m.jurusan_id, upp.entitas_id, upp.tipe_kuota
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            WHERE p.id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $pdft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pdft) {
            throw new RuntimeException("Data pendaftaran #{$pendaftaranId} tidak ditemukan.");
        }

        if ($companyEntitasId !== null && (int)$pdft['entitas_id'] !== $companyEntitasId) {
            throw new RuntimeException("Akses ditolak: mahasiswa bukan pendaftar di unit Anda.");
        }

        $currentUppId = (int)$pdft['unit_pelaksana_periode_id'];
        $jurusanId = (int)$pdft['jurusan_id'];

        // Jika sebelumnya berstatus 'ditolak', kurangi kembali 1 kuota
        if ($pdft['status'] === 'ditolak') {
            $stmtDeduct = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = GREATEST(0, kuota_tersisa - 1) WHERE id = :id");
            $stmtDeduct->execute([':id' => $currentUppId]);

            if ($pdft['tipe_kuota'] === 'breakdown') {
                $stmtDeductJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = GREATEST(0, kuota_tersisa - 1) WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                $stmtDeductJ->execute([':upp_id' => $currentUppId, ':jid' => $jurusanId]);
            }
        }

        // Update status pendaftaran
        $stmtUpd = $pdo->prepare("
            UPDATE pendaftaran
            SET status = 'diterima',
                catatan_admin = :catatan,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':catatan' => $catatan ?: 'Ditetapkan diterima.',
            ':id'      => $pendaftaranId
        ]);
    }

    /**
     * Tolak pendaftaran mahasiswa (status: ditolak)
     * Mengembalikan 1 kuota unit dan 1 kuota prodi (jika breakdown)
     */
    public static function tolakPeserta(
        PDO $pdo,
        int $pendaftaranId,
        string $catatan,
        int $adminId,
        string $role = 'admin_blka',
        ?int $companyEntitasId = null
    ): void {
        // Lock pendaftaran
        $stmt = $pdo->prepare("
            SELECT p.id, p.mahasiswa_id, p.periode_id, p.unit_pelaksana_periode_id, p.unit_pelaksana_periode_asal_id,
                   p.status, p.is_dipindahkan, m.jurusan_id, upp.entitas_id, upp.tipe_kuota
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            WHERE p.id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $pdft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pdft) {
            throw new RuntimeException("Data pendaftaran #{$pendaftaranId} tidak ditemukan.");
        }

        if ($companyEntitasId !== null && (int)$pdft['entitas_id'] !== $companyEntitasId) {
            throw new RuntimeException("Akses ditolak: mahasiswa bukan pendaftar di unit Anda.");
        }

        $currentUppId = (int)$pdft['unit_pelaksana_periode_id'];
        $jurusanId = (int)$pdft['jurusan_id'];

        // Jika sebelumnya bukan 'ditolak', kembalikan 1 kuota
        if ($pdft['status'] !== 'ditolak') {
            $stmtFree = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
            $stmtFree->execute([':id' => $currentUppId]);

            if ($pdft['tipe_kuota'] === 'breakdown') {
                $stmtFreeJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = kuota_tersisa + 1 WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                $stmtFreeJ->execute([':upp_id' => $currentUppId, ':jid' => $jurusanId]);
            }
        }

        // Update status pendaftaran
        $stmtUpd = $pdo->prepare("
            UPDATE pendaftaran
            SET status = 'ditolak',
                is_dipindahkan = 0,
                catatan_admin = :catatan,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpd->execute([
            ':catatan' => $catatan ?: 'Formasi Anda belum memenuhi kebutuhan kami',
            ':id'      => $pendaftaranId
        ]);

        // Antrekan email notifikasi penolakan ke antrean pengiriman
        try {
            EmailQueue::pushPenolakan($pdo, $pendaftaranId, $catatan ?: 'Formasi Anda belum memenuhi kebutuhan kami');
        } catch (\Throwable $e) {
            error_log('[tolakPeserta] Gagal antrekan email penolakan: ' . $e->getMessage());
        }
    }

    /**
     * Ajukan pemindahan mahasiswa ke unit baru
     */
    public static function ajukanPemindahan(
        PDO $pdo,
        int $pendaftaranId,
        int $newUppId,
        string $alasan,
        int $adminId,
        string $role = 'admin_perusahaan',
        ?int $companyEntitasId = null
    ): void {
        // Lock pendaftaran
        $stmt = $pdo->prepare("
            SELECT p.id, p.mahasiswa_id, p.periode_id, p.unit_pelaksana_periode_id, p.unit_pelaksana_periode_asal_id,
                   p.status, p.is_dipindahkan, m.jurusan_id, upp.entitas_id, upp.tipe_kuota
            FROM pendaftaran p
            JOIN mahasiswa m ON p.mahasiswa_id = m.id
            JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
            WHERE p.id = :id
            FOR UPDATE
        ");
        $stmt->execute([':id' => $pendaftaranId]);
        $pdft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pdft) {
            throw new RuntimeException("Data pendaftaran #{$pendaftaranId} tidak ditemukan.");
        }

        if ($companyEntitasId !== null && (int)$pdft['entitas_id'] !== $companyEntitasId) {
            throw new RuntimeException("Akses ditolak: mahasiswa bukan pendaftar di unit Anda.");
        }

        $currentUppId = (int)$pdft['unit_pelaksana_periode_id'];
        $periodeId = (int)$pdft['periode_id'];
        $mhsId = (int)$pdft['mahasiswa_id'];
        $jurusanId = (int)$pdft['jurusan_id'];

        if ($newUppId === $currentUppId) {
            throw new RuntimeException("Unit tujuan tidak boleh sama dengan unit saat ini.");
        }

        // Lock & Cek unit tujuan
        $stmtNew = $pdo->prepare("SELECT id, kuota_tersisa, tipe_kuota, entitas_id FROM unit_pelaksana_periode WHERE id = :id AND periode_id = :pid FOR UPDATE");
        $stmtNew->execute([':id' => $newUppId, ':pid' => $periodeId]);
        $newUpp = $stmtNew->fetch(PDO::FETCH_ASSOC);

        if (!$newUpp) {
            throw new RuntimeException("Unit pelaksana tujuan tidak ditemukan pada periode ini.");
        }

        if ((int)$newUpp['kuota_tersisa'] <= 0 && $role !== 'admin_blka') {
            throw new RuntimeException("Kuota keseluruhan pada unit tujuan sudah penuh.");
        }

        // Cek kuota prodi jika unit tujuan adalah breakdown
        if ($newUpp['tipe_kuota'] === 'breakdown') {
            $stmtUpj = $pdo->prepare("SELECT kuota_tersisa FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid FOR UPDATE");
            $stmtUpj->execute([':upp_id' => $newUppId, ':jid' => $jurusanId]);
            $upjRow = $stmtUpj->fetch(PDO::FETCH_ASSOC);

            if ($role !== 'admin_blka') {
                if (!$upjRow || (int)$upjRow['kuota_tersisa'] <= 0) {
                    throw new RuntimeException("Unit tujuan tidak memiliki sisa kuota untuk Program Studi mahasiswa ini.");
                }
            } else {
                // Untuk Admin BLKA (Pindahkan Paksa):
                // Jika snapshot prodi belum ada di unit tujuan, tambahkan baris prodi
                if (!$upjRow) {
                    $stmtInsUpj = $pdo->prepare("INSERT INTO unit_periode_jurusan (unit_pelaksana_periode_id, jurusan_id, kuota_total, kuota_tersisa) VALUES (:upp_id, :jid, 1, 1)");
                    $stmtInsUpj->execute([':upp_id' => $newUppId, ':jid' => $jurusanId]);
                }
            }
        } else {
            // Cek apakah unit tujuan (mode keseluruhan) membuka kuota untuk Program Studi mahasiswa ini
            $stmtUpj = $pdo->prepare("SELECT 1 FROM unit_periode_jurusan WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
            $stmtUpj->execute([':upp_id' => $newUppId, ':jid' => $jurusanId]);
            $upjExists = $stmtUpj->fetchColumn();

            if ($role !== 'admin_blka') {
                if (!$upjExists) {
                    throw new RuntimeException("Unit tujuan tidak membuka kuota untuk Program Studi mahasiswa ini.");
                }
            }
        }

        // Batalkan pending pemindahan lama jika ada untuk mencegah kebocoran kuota
        $stmtPending = $pdo->prepare("
            SELECT id, unit_tujuan_id 
            FROM pemindahan_peserta 
            WHERE pendaftaran_id = :pid AND status_approval = 'menunggu_approval'
            FOR UPDATE
        ");
        $stmtPending->execute([':pid' => $pendaftaranId]);
        $pendings = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendings as $pend) {
            $pTujuanId = (int)$pend['unit_tujuan_id'];
            $stmtUppTuj = $pdo->prepare("SELECT tipe_kuota FROM unit_pelaksana_periode WHERE id = ?");
            $stmtUppTuj->execute([$pTujuanId]);
            $tujTipe = $stmtUppTuj->fetchColumn();

            $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = ?")->execute([$pTujuanId]);
            if ($tujTipe === 'breakdown') {
                $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = kuota_tersisa + 1 WHERE unit_pelaksana_periode_id = ? AND jurusan_id = ?")->execute([$pTujuanId, $jurusanId]);
            }

            $pdo->prepare("
                UPDATE pemindahan_peserta 
                SET status_approval = 'ditolak',
                    approval_catatan = 'Dibatalkan oleh pemindahan baru.',
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([(int)$pend['id']]);
        }

        // Cadangkan 1 kuota di unit tujuan
        $stmtDeductNew = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = GREATEST(0, kuota_tersisa - 1) WHERE id = :id");
        $stmtDeductNew->execute([':id' => $newUppId]);

        if ($newUpp['tipe_kuota'] === 'breakdown') {
            $stmtDeductNewJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = GREATEST(0, kuota_tersisa - 1) WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
            $stmtDeductNewJ->execute([':upp_id' => $newUppId, ':jid' => $jurusanId]);
        }

        $asalToSave = $pdft['unit_pelaksana_periode_asal_id'] ? (int)$pdft['unit_pelaksana_periode_asal_id'] : $currentUppId;

        // Tentukan status approval pemindahan
        $isForceBlka = ($role === 'admin_blka');
        $statusApproval = $isForceBlka ? 'force_blka' : 'menunggu_approval';

        // Jika diajukan langsung oleh BLKA, pemindahan bersifat mutlak -> bebaskan kuota unit asal langsung
        if ($role === 'admin_blka' && $pdft['status'] !== 'ditolak') {
            $stmtFreeOld = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
            $stmtFreeOld->execute([':id' => $currentUppId]);

            if ($pdft['tipe_kuota'] === 'breakdown') {
                $stmtFreeOldJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = kuota_tersisa + 1 WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                $stmtFreeOldJ->execute([':upp_id' => $currentUppId, ':jid' => $jurusanId]);
            }
        }

        // Buat record audit pemindahan_peserta
        $stmtAudit = $pdo->prepare("
            INSERT INTO pemindahan_peserta (
                pendaftaran_id, periode_id, mahasiswa_id, unit_asal_id, unit_tujuan_id,
                diajukan_oleh_role, diajukan_oleh_admin_id, alasan_pemindahan, status_approval,
                approval_oleh_admin_id, approval_catatan, approved_at,
                created_at, updated_at
            ) VALUES (
                :p_id, :pid, :mid, :asal_id, :tujuan_id,
                :role, :admin_id, :alasan, :status_app,
                :app_admin_id, :app_catatan, :app_at,
                NOW(), NOW()
            )
        ");
        $stmtAudit->execute([
            ':p_id'         => $pendaftaranId,
            ':pid'          => $periodeId,
            ':mid'          => $mhsId,
            ':asal_id'      => $currentUppId,
            ':tujuan_id'    => $newUppId,
            ':role'         => $role,
            ':admin_id'     => $adminId,
            ':alasan'       => $alasan ?: 'Dipindahkan ke unit lain.',
            ':status_app'   => $statusApproval,
            ':app_admin_id' => $isForceBlka ? $adminId : null,
            ':app_catatan'  => $isForceBlka ? ($alasan ?: 'Ditetapkan langsung oleh Admin BLKA.') : null,
            ':app_at'       => $isForceBlka ? date('Y-m-d H:i:s') : null,
        ]);

        // Update record pendaftaran
        $stmtUpdP = $pdo->prepare("
            UPDATE pendaftaran
            SET status = 'dipindahkan',
                unit_pelaksana_periode_id = :new_upp,
                unit_pelaksana_periode_asal_id = :asal_upp,
                is_dipindahkan = 1,
                catatan_admin = :catatan,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUpdP->execute([
            ':new_upp'  => $newUppId,
            ':asal_upp' => $asalToSave,
            ':catatan'  => $alasan ?: 'Pemindahan unit dalam proses verifikasi.',
            ':id'       => $pendaftaranId
        ]);
    }

    /**
     * Respon approval pemindahan oleh perusahaan penerima (Setuju / Tolak)
     */
    public static function responPemindahan(
        PDO $pdo,
        int $pemindahanId,
        bool $isApprove,
        string $catatan,
        int $adminId,
        int $companyEntitasId,
        ?string $overrideStatus = null
    ): void {
        // Lock record pemindahan
        $stmtPem = $pdo->prepare("
            SELECT pem.id, pem.pendaftaran_id, pem.periode_id, pem.mahasiswa_id,
                   pem.unit_asal_id, pem.unit_tujuan_id, pem.status_approval,
                   upp_tujuan.entitas_id AS tujuan_entitas_id, upp_tujuan.tipe_kuota AS tujuan_tipe_kuota,
                   upp_asal.entitas_id AS asal_entitas_id, upp_asal.tipe_kuota AS asal_tipe_kuota,
                   m.jurusan_id
            FROM pemindahan_peserta pem
            JOIN unit_pelaksana_periode upp_tujuan ON pem.unit_tujuan_id = upp_tujuan.id
            JOIN unit_pelaksana_periode upp_asal ON pem.unit_asal_id = upp_asal.id
            JOIN mahasiswa m ON pem.mahasiswa_id = m.id
            WHERE pem.id = :id
            FOR UPDATE
        ");
        $stmtPem->execute([':id' => $pemindahanId]);
        $pem = $stmtPem->fetch(PDO::FETCH_ASSOC);

        if (!$pem) {
            throw new RuntimeException("Data permohonan pemindahan #{$pemindahanId} tidak ditemukan.");
        }

        if ((int)$pem['tujuan_entitas_id'] !== $companyEntitasId) {
            throw new RuntimeException("Akses ditolak: unit Anda bukan tujuan pemindahan untuk peserta ini.");
        }

        if ($pem['status_approval'] !== 'menunggu_approval') {
            throw new RuntimeException("Permohonan pemindahan ini sudah pernah diproses sebelumnya.");
        }

        $pendaftaranId = (int)$pem['pendaftaran_id'];
        $unitAsalId = (int)$pem['unit_asal_id'];
        $unitTujuanId = (int)$pem['unit_tujuan_id'];
        $jurusanId = (int)$pem['jurusan_id'];

        if ($isApprove) {
            $approvalStatus = $overrideStatus ?? 'disetujui';
            // 1. SETUJUI PEMINDAHAN
            // Update status audit
            $stmtUpdPem = $pdo->prepare("
                UPDATE pemindahan_peserta
                SET status_approval = :stat_app,
                    approval_oleh_admin_id = :admin_id,
                    approval_catatan = :catatan,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdPem->execute([
                ':stat_app' => $approvalStatus,
                ':admin_id' => $adminId,
                ':catatan'  => $catatan ?: 'Pemindahan disetujui oleh unit penerima.',
                ':id'       => $pemindahanId
            ]);

            // Bebaskan kuota unit asal
            $stmtFreeAsal = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
            $stmtFreeAsal->execute([':id' => $unitAsalId]);

            if ($pem['asal_tipe_kuota'] === 'breakdown') {
                $stmtFreeAsalJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = kuota_tersisa + 1 WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                $stmtFreeAsalJ->execute([':upp_id' => $unitAsalId, ':jid' => $jurusanId]);
            }

            // Update catatan pendaftaran
            $stmtUpdP = $pdo->prepare("
                UPDATE pendaftaran
                SET catatan_admin = :catatan,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdP->execute([
                ':catatan' => $catatan ?: 'Pemindahan resmi disetujui oleh unit penerima.',
                ':id'      => $pendaftaranId
            ]);

        } else {
            // 2. TOLAK PEMINDAHAN (Kembalikan mahasiswa ke unit asal)
            // Update status audit
            $stmtUpdPem = $pdo->prepare("
                UPDATE pemindahan_peserta
                SET status_approval = 'ditolak',
                    approval_oleh_admin_id = :admin_id,
                    approval_catatan = :catatan,
                    approved_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmtUpdPem->execute([
                ':admin_id' => $adminId,
                ':catatan'  => $catatan ?: 'Pemindahan ditolak oleh unit penerima.',
                ':id'       => $pemindahanId
            ]);

            // Kembalikan kuota yang tadi dicadangkan di unit tujuan
            $stmtFreeTujuan = $pdo->prepare("UPDATE unit_pelaksana_periode SET kuota_tersisa = kuota_tersisa + 1 WHERE id = :id");
            $stmtFreeTujuan->execute([':id' => $unitTujuanId]);

            if ($pem['tujuan_tipe_kuota'] === 'breakdown') {
                $stmtFreeTujuanJ = $pdo->prepare("UPDATE unit_periode_jurusan SET kuota_tersisa = kuota_tersisa + 1 WHERE unit_pelaksana_periode_id = :upp_id AND jurusan_id = :jid");
                $stmtFreeTujuanJ->execute([':upp_id' => $unitTujuanId, ':jid' => $jurusanId]);
            }

            // Kembalikan pendaftaran ke unit asal
            $stmtRestore = $pdo->prepare("
                UPDATE pendaftaran
                SET unit_pelaksana_periode_id = :asal_id,
                    is_dipindahkan = 0,
                    status = 'diajukan',
                    catatan_admin = :catatan,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $alasanTolak = $catatan ? "Pemindahan ditolak oleh unit tujuan: {$catatan}" : "Pemindahan ditolak oleh unit tujuan. Mahasiswa dikembalikan ke unit asal.";
            $stmtRestore->execute([
                ':asal_id' => $unitAsalId,
                ':catatan' => $alasanTolak,
                ':id'      => $pendaftaranId
            ]);
        }
    }

    /**
     * Hitung jumlah permohonan pemindahan masuk yang berstatus 'menunggu_approval' untuk sebuah entitas
     */
    public static function getPendingTransferCount(PDO $pdo, int $entitasId, ?int $periodeId = null): int
    {
        $sql = "
            SELECT COUNT(*)
            FROM pemindahan_peserta pem
            JOIN unit_pelaksana_periode upp ON pem.unit_tujuan_id = upp.id
            WHERE upp.entitas_id = :eid
              AND pem.status_approval = 'menunggu_approval'
        ";
        $params = [':eid' => $entitasId];

        if ($periodeId !== null && $periodeId > 0) {
            $sql .= " AND pem.periode_id = :pid";
            $params[':pid'] = $periodeId;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}

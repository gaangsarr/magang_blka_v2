<?php

declare(strict_types=1);

namespace App;

use PDO;
use Throwable;
use App\Models\PicNarahubung;

class EmailQueue
{
    /**
     * Memasukkan email baru ke antrean database
     */
    public static function push(
        PDO $pdo,
        string $toEmail,
        string $toName,
        string $subject,
        string $bodyHtml,
        string $bodyText = '',
        ?int $periodeId = null,
        ?string $tipe = null
    ): int {
        $toEmail = trim($toEmail);
        if (empty($toEmail)) {
            return 0;
        }

        if (empty($bodyText)) {
            $bodyText = strip_tags(str_replace(['<br>', '<br/>', '</p>'], ["\n", "\n", "\n\n"], $bodyHtml));
        }

        $stmt = $pdo->prepare("
            INSERT INTO email_queue (to_email, to_name, periode_id, tipe, subject, body_html, body_text, status, attempts, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), NOW())
        ");
        $stmt->execute([$toEmail, trim($toName), $periodeId, $tipe, $subject, $bodyHtml, $bodyText]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Memeriksa apakah tipe notifikasi email tertentu diaktifkan di tabel pengaturan
     */
    public static function isNotificationEnabled(PDO $pdo, string $key, bool $default = false): bool
    {
        try {
            $stmt = $pdo->prepare("SELECT nilai FROM pengaturan WHERE kunci = ?");
            $stmt->execute([$key]);
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null) {
                return $default;
            }
            return in_array(strtolower((string)$val), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Membatalkan semua email yang masih berstatus pending untuk suatu periode
     * Digunakan saat Admin menutup pengumuman agar pengiriman data lama langsung berhenti.
     */
    public static function cancelPendingForPeriode(PDO $pdo, int $periodeId): int
    {
        $stmt = $pdo->prepare("DELETE FROM email_queue WHERE periode_id = ? AND status = 'pending'");
        $stmt->execute([$periodeId]);
        return $stmt->rowCount();
    }

    /**
     * Memasukkan Bukti Tanda Terima Pendaftaran ke antrean
     */
    public static function pushBuktiPendaftaran(PDO $pdo, int $pendaftaranId): bool
    {
        try {
            if (!self::isNotificationEnabled($pdo, 'email_notifikasi_submit', false)) {
                return true;
            }

            $stmt = $pdo->prepare("
                SELECT p.id, p.periode_id, p.program, p.submitted_at, p.nama_snapshot AS nama,
                       m.nim, m.email, e.nama AS nama_unit, pr.nama AS nama_periode
                FROM pendaftaran p
                JOIN mahasiswa m ON p.mahasiswa_id = m.id
                JOIN periode pr ON p.periode_id = pr.id
                JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
                JOIN entitas_perusahaan e ON upp.entitas_id = e.id
                WHERE p.id = ?
            ");
            $stmt->execute([$pendaftaranId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['email'])) {
                return false;
            }

            $nama        = htmlspecialchars((string)($row['nama'] ?? 'Mahasiswa'));
            $nim         = htmlspecialchars((string)($row['nim'] ?? '-'));
            $regNumber   = 'REG-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
            $namaUnit    = htmlspecialchars((string)($row['nama_unit'] ?? '-'));
            $namaPeriode = htmlspecialchars((string)($row['nama_periode'] ?? 'Periode Aktif'));
            $submittedAt = htmlspecialchars((string)($row['submitted_at'] ?? date('d F Y, H:i') . ' WIB'));
            $periodeId   = (int)$row['periode_id'];

            $programMap = [
                '1_bulan' => 'Magang 1 Bulan',
                '3_bulan' => 'Magang 3 Bulan',
                '4_bulan' => 'Magang 4 Bulan',
                '5_bulan' => 'Magang 5 Bulan (KRS)'
            ];
            $programLabel = $programMap[$row['program'] ?? ''] ?? htmlspecialchars((string)($row['program'] ?? '-'));

            $subject = "[REMATE ITPLN] Bukti Pendaftaran Magang - {$regNumber}";

            $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 24px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="background: linear-gradient(135deg, #005596 0%, #0284c7 100%); padding: 32px 28px; text-align: left; color: #ffffff;">
                            <div style="font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #93c5fd; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 style="margin: 0; font-size: 22px; font-weight: 800; line-height: 1.3;">
                                Bukti Tanda Terima Pendaftaran Magang
                            </h1>
                            <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px;">
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                Pendaftaran magang Anda di portal <strong>REMATE ITPLN</strong> telah berhasil tersimpan di sistem dengan rincian sebagai berikut:
                            </p>
                            <table role="presentation" width="100%" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 24px;" cellspacing="0" cellpadding="12" border="0">
                                <tr>
                                    <td width="38%" style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Nomor Registrasi</td>
                                    <td style="font-size: 14px; color: #005596; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-family: monospace;">{$regNumber}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">NIM Mahasiswa</td>
                                    <td style="font-size: 13px; color: #0f172a; border-bottom: 1px solid #e2e8f0;">{$nim}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Unit Magang Dipilih</td>
                                    <td style="font-size: 14px; color: #0f172a; border-bottom: 1px solid #e2e8f0; font-weight: 700;">{$namaUnit}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Program Magang</td>
                                    <td style="font-size: 13px; color: #0f172a; border-bottom: 1px solid #e2e8f0;">{$programLabel}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; font-weight: 600;">Waktu Submit</td>
                                    <td style="font-size: 13px; color: #0f172a;">{$submittedAt}</td>
                                </tr>
                            </table>
                            <div style="background-color: #eff6ff; border-left: 4px solid #0284c7; padding: 14px 16px; border-radius: 4px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #0369a1; margin-bottom: 4px;">Informasi Seleksi & Penempatan:</div>
                                <div style="font-size: 13px; color: #0c4a6e; line-height: 1.5;">
                                    Berkas dan formasi pilihan Anda akan diverifikasi oleh Biro Layanan Karir dan Alumni (BLKA) ITPLN serta unit tujuan. Perkembangan status dapat dipantau berkala melalui menu <strong>Status Pendaftaran</strong> di portal magang.
                                </div>
                            </div>
                            <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                                Simpan email ini sebagai tanda bukti resmi pendaftaran Anda.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 12px; line-height: 1.6;">
                            <div><strong>Biro Layanan Karir dan Alumni (BLKA)</strong></div>
                            <div>Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 8px; font-size: 11px;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

            self::push($pdo, (string)$row['email'], (string)$row['nama'], $subject, $bodyHtml, '', $periodeId, 'bukti_pendaftaran');
            return true;
        } catch (Throwable $e) {
            error_log('[EmailQueue pushBuktiPendaftaran Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Memasukkan notifikasi Penolakan Berkas / Formasi ke antrean
     */
    public static function pushPenolakan(PDO $pdo, int $pendaftaranId, string $alasan = ''): bool
    {
        try {
            if (!self::isNotificationEnabled($pdo, 'email_notifikasi_penolakan', true)) {
                return true;
            }

            $stmt = $pdo->prepare("
                SELECT p.id, p.periode_id, p.nama_snapshot AS nama, m.nim, m.email, e.nama AS nama_unit, pr.nama AS nama_periode, pr.status AS periode_status
                FROM pendaftaran p
                JOIN mahasiswa m ON p.mahasiswa_id = m.id
                JOIN periode pr ON p.periode_id = pr.id
                JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
                JOIN entitas_perusahaan e ON upp.entitas_id = e.id
                WHERE p.id = ?
            ");
            $stmt->execute([$pendaftaranId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row || empty($row['email'])) {
                return false;
            }

            $nama        = htmlspecialchars((string)($row['nama'] ?? 'Mahasiswa'));
            $namaUnit    = htmlspecialchars((string)($row['nama_unit'] ?? '-'));
            $namaPeriode = htmlspecialchars((string)($row['nama_periode'] ?? 'Periode Aktif'));
            $periodeId   = (int)$row['periode_id'];
            $isPeriodeOpen = ($row['periode_status'] ?? '') === 'dibuka';
            $catatanText = !empty($alasan) ? htmlspecialchars($alasan) : 'Formasi Anda belum memenuhi kebutuhan kami';

            if ($isPeriodeOpen) {
                $statusNoticeHtml = '
                    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                        <div style="font-size: 14px; font-weight: 700; color: #166534; margin-bottom: 6px;">Kesempatan Mendaftar Ulang:</div>
                        <div style="font-size: 13px; color: #14532d; line-height: 1.5;">
                            Anda <strong>dapat memilih kembali unit pelaksana lain</strong> yang masih memiliki kuota tersedia selama periode pendaftaran masih dibuka. Silakan login ke portal magang dan lakukan pemilihan unit baru melalui menu pendaftaran.
                        </div>
                    </div>
                ';
            } else {
                $statusNoticeHtml = '
                    <div style="background-color: #eff6ff; border: 1px solid #bfdbfe; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                        <div style="font-size: 14px; font-weight: 700; color: #1e40af; margin-bottom: 6px;">Informasi Pendaftaran:</div>
                        <div style="font-size: 13px; color: #1e3a8a; line-height: 1.5;">
                            Masa pendaftaran pada periode ini telah resmi ditutup dan sistem sedang memproses tahapan penetapan akhir. Perkembangan status seleksi dapat Anda pantau berkala melalui menu Status Pendaftaran di portal magang.
                        </div>
                    </div>
                ';
            }

            $subject = "[REMATE ITPLN] Pembaruan Status Pendaftaran Magang - Formasi Belum Sesuai";

            $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 24px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="background: linear-gradient(135deg, #b91c1c 0%, #dc2626 100%); padding: 32px 28px; text-align: left; color: #ffffff;">
                            <div style="font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #fecaca; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 style="margin: 0; font-size: 22px; font-weight: 800; line-height: 1.3;">
                                Pemberitahuan Status Pendaftaran Magang
                            </h1>
                            <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px;">
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                Terima kasih atas partisipasi Anda dalam program magang {$namaPeriode}. Berdasarkan hasil seleksi berkas dan penyesuaian formasi kuota pada unit <strong>{$namaUnit}</strong>, kami informasikan bahwa pendaftaran Anda pada unit tersebut <strong>belum dapat disetujui</strong>.
                            </p>

                            <div style="background-color: #fff1f2; border-left: 4px solid #e11d48; padding: 16px; border-radius: 4px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #9f1239; margin-bottom: 4px;">Catatan Tim Verifikator / Admin:</div>
                                <div style="font-size: 14px; color: #881337; line-height: 1.5; font-style: italic;">
                                    "{$catatanText}"
                                </div>
                            </div>

                            {$statusNoticeHtml}

                            <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                                Kunjungi portal magang: <a href="https://magang.itpln.ac.id" style="color: #005596; font-weight: 600;">magang.itpln.ac.id</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 12px; line-height: 1.6;">
                            <div><strong>Biro Layanan Karir dan Alumni (BLKA)</strong></div>
                            <div>Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 8px; font-size: 11px;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

            self::push($pdo, (string)$row['email'], (string)$row['nama'], $subject, $bodyHtml, '', $periodeId, 'penolakan');
            return true;
        } catch (Throwable $e) {
            error_log('[EmailQueue pushPenolakan Error] ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Memasukkan Pengumuman Kelulusan / Penempatan secara Selektif & Cerdas:
     * - Mahasiswa yang belum pernah terima email -> kirim pengumuman baru.
     * - Mahasiswa yang unit / statusnya berubah -> kirim pembaruan pengumuman.
     * - Mahasiswa yang datanya tidak berubah -> DI-SKIP (tidak dikirimi email duplikat).
     *
     * @return array{new_count: int, update_count: int, skipped_count: int, total_queued: int}
     */
    public static function pushPengumumanPeriode(PDO $pdo, int $periodeId): array
    {
        $result = [
            'new_count'     => 0,
            'update_count'  => 0,
            'skipped_count' => 0,
            'total_queued'  => 0
        ];

        try {
            if (!self::isNotificationEnabled($pdo, 'email_notifikasi_pengumuman', false)) {
                return $result;
            }

            $stmt = $pdo->prepare("
                SELECT p.id, p.status, p.is_dipindahkan, p.nama_snapshot AS nama,
                       p.unit_pelaksana_periode_id,
                       p.email_pengumuman_sent_at,
                       p.email_pengumuman_status_snapshot,
                       p.email_pengumuman_upp_snapshot,
                       m.nim, m.email,
                       e.nama AS nama_unit, e.id AS entitas_id,
                       e_asal.nama AS nama_unit_asal,
                       pr.nama AS nama_periode
                FROM pendaftaran p
                JOIN mahasiswa m ON p.mahasiswa_id = m.id
                JOIN periode pr ON p.periode_id = pr.id
                JOIN unit_pelaksana_periode upp ON p.unit_pelaksana_periode_id = upp.id
                JOIN entitas_perusahaan e ON upp.entitas_id = e.id
                LEFT JOIN unit_pelaksana_periode upp_asal ON p.unit_pelaksana_periode_asal_id = upp_asal.id
                LEFT JOIN entitas_perusahaan e_asal ON upp_asal.entitas_id = e_asal.id
                WHERE p.periode_id = ? AND p.status IN ('diterima', 'dipindahkan', 'ditolak')
            ");
            $stmt->execute([$periodeId]);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($list)) {
                return $result;
            }

            $stmtUpdateSnapshot = $pdo->prepare("
                UPDATE pendaftaran 
                SET email_pengumuman_sent_at = NOW(),
                    email_pengumuman_status_snapshot = :status,
                    email_pengumuman_upp_snapshot = :upp_id
                WHERE id = :id
            ");

            foreach ($list as $row) {
                if (empty($row['email'])) {
                    continue;
                }

                $pendaftaranId = (int)$row['id'];
                $status        = $row['status'];
                $currentUppId  = (int)$row['unit_pelaksana_periode_id'];
                $nama          = htmlspecialchars((string)($row['nama'] ?? 'Mahasiswa'));
                $namaPeriode   = htmlspecialchars((string)($row['nama_periode'] ?? 'Periode Aktif'));
                $isDipindah    = (bool)$row['is_dipindahkan'] || $status === 'dipindahkan';
                $namaUnit      = htmlspecialchars((string)($row['nama_unit'] ?? '-'));
                $namaAsal      = htmlspecialchars((string)($row['nama_unit_asal'] ?? ''));

                // Logika Seleksi Cerdas
                $neverSent   = empty($row['email_pengumuman_sent_at']);
                $prevStatus  = $row['email_pengumuman_status_snapshot'] ?? null;
                $prevUpp     = $row['email_pengumuman_upp_snapshot'] !== null ? (int)$row['email_pengumuman_upp_snapshot'] : null;

                $isChanged   = (!$neverSent && ($status !== $prevStatus || $currentUppId !== $prevUpp));
                $isIdentical = (!$neverSent && !$isChanged);

                if ($isIdentical) {
                    // Data sama persis dengan yang sudah dikirim sebelumnya -> skip agar mahasiswa tidak bingung
                    $result['skipped_count']++;
                    continue;
                }

                $isUpdateEmail = $isChanged;

                // Cari narahubung PIC jika diterima/dipindahkan
                $picInfo = '';
                if ($status === 'diterima' || $isDipindah) {
                    $picData = PicNarahubung::resolveForUnit((int)$row['entitas_id']);
                    if ($picData) {
                        $picNama = htmlspecialchars((string)($picData['nama_pic'] ?? '-'));
                        $picArea = htmlspecialchars((string)($picData['area_hcbp'] ?? ''));
                        $picWa   = htmlspecialchars((string)($picData['no_wa'] ?? '-'));
                        $picMail = !empty($picData['email']) ? "<br>Email: " . htmlspecialchars((string)$picData['email']) : "";

                        $picInfo = "
                            <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; margin: 20px 0;'>
                                <div style='font-size: 13px; font-weight: 700; color: #005596; margin-bottom: 6px;'>Kontak PIC Narahubung Unit:</div>
                                <div style='font-size: 13px; color: #334155; line-height: 1.6;'>
                                    <strong>{$picNama}</strong>" . ($picArea ? " ({$picArea})" : "") . "<br>
                                    WhatsApp: {$picWa}{$picMail}
                                </div>
                            </div>
                        ";
                    }
                }

                // Banner Pemberitahuan Pembaruan
                $updateNotice = '';
                if ($isUpdateEmail) {
                    $updateNotice = "
                        <div style='background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 4px; margin-bottom: 20px;'>
                            <div style='font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 4px;'>Pemberitahuan Pembaruan Pengumuman:</div>
                            <div style='font-size: 13px; color: #78350f; line-height: 1.5;'>
                                Email ini merupakan pembaruan resmi atas pengumuman sebelumnya setelah dilakukan evaluasi & penyesuaian formasi kuota oleh BLKA ITPLN. Rincian di bawah adalah penetapan final yang berlaku.
                            </div>
                        </div>
                    ";
                }

                if ($status === 'diterima' || $isDipindah) {
                    $headline = $isUpdateEmail ? "Pembaruan Hasil Penempatan Magang" : ($isDipindah ? "Penetapan Penempatan Unit Magang" : "Selamat! Anda Diterima Magang");
                    $subjectPrefix = $isUpdateEmail ? "[Pembaruan Resmi] " : "";
                    $subject = "{$subjectPrefix}[REMATE ITPLN] Pengumuman Hasil Seleksi Magang - Diterima";
                    $pembuka = $isDipindah
                        ? "Selamat, pendaftaran magang Anda telah diproses. Berdasarkan koordinasi dan penyesuaian formasi, Anda ditempatkan pada <strong>{$namaUnit}</strong> (dialihkan dari formasi awal {$namaAsal})."
                        : "Selamat! Berdasarkan hasil seleksi berkas dan kuota unit, Anda dinyatakan <strong>DITERIMA</strong> untuk melaksanakan magang di <strong>{$namaUnit}</strong>.";
                } else {
                    $headline = $isUpdateEmail ? "Pembaruan Hasil Seleksi Magang" : "Pengumuman Hasil Seleksi Magang";
                    $subjectPrefix = $isUpdateEmail ? "[Pembaruan Resmi] " : "";
                    $subject = "{$subjectPrefix}[REMATE ITPLN] Pengumuman Hasil Seleksi Magang";
                    $pembuka = "Terima kasih atas partisipasi Anda dalam program magang {$namaPeriode}. Berdasarkan hasil seleksi berkas dan kuota penempatan, formasi Anda belum memenuhi kebutuhan kami.";
                }

                $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$subject}</title>
</head>
<body style="margin: 0; padding: 24px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1e293b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td style="background: linear-gradient(135deg, #005596 0%, #0284c7 100%); padding: 32px 28px; text-align: left; color: #ffffff;">
                            <div style="font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #93c5fd; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 style="margin: 0; font-size: 22px; font-weight: 800; line-height: 1.3;">
                                {$headline}
                            </h1>
                            <div style="margin-top: 10px; font-size: 14px; opacity: 0.9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px;">
                            {$updateNotice}
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                {$pembuka}
                            </p>
                            {$picInfo}
                            <div style="background-color: #eff6ff; border-left: 4px solid #0284c7; padding: 14px 16px; border-radius: 4px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #0369a1; margin-bottom: 4px;">Instruksi Lebih Lanjut:</div>
                                <div style="font-size: 13px; color: #0c4a6e; line-height: 1.5;">
                                    Silakan login ke portal resmi <a href="https://magang.itpln.ac.id" style="color: #0284c7; font-weight: 700;">magang.itpln.ac.id</a> untuk mengunduh Surat Pengantar Magang dan melihat detail jadwal pelaksanaan.
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 12px; line-height: 1.6;">
                            <div><strong>Biro Layanan Karir dan Alumni (BLKA)</strong></div>
                            <div>Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 8px; font-size: 11px;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

                $tipeJob = $isUpdateEmail ? 'pembaruan_pengumuman' : 'pengumuman';
                self::push($pdo, (string)$row['email'], (string)$row['nama'], $subject, $bodyHtml, '', $periodeId, $tipeJob);

                // Catat snapshot pada pendaftaran
                $stmtUpdateSnapshot->execute([
                    ':status' => $status,
                    ':upp_id' => $currentUppId,
                    ':id'     => $pendaftaranId
                ]);

                if ($isUpdateEmail) {
                    $result['update_count']++;
                } else {
                    $result['new_count']++;
                }
                $result['total_queued']++;
            }

            return $result;
        } catch (Throwable $e) {
            error_log('[EmailQueue pushPengumumanPeriode Error] ' . $e->getMessage());
            return $result;
        }
    }
}

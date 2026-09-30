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
     * Mendapatkan URL basis aplikasi dari .env (in-memory lookup, 0% disk overhead)
     */
    public static function getAppUrl(): string
    {
        $url = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if (!$url && isset($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        return rtrim((string)($url ?: 'https://magang.itpln.ac.id'), '/');
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
            $appUrl      = self::getAppUrl();
            $statusUrl   = htmlspecialchars($appUrl . '/status.html');

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
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$subject}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, p, a, span { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        :root {
            color-scheme: light;
            supported-color-schemes: light;
        }
        body, table, td, p, a, span {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        @media (prefers-color-scheme: dark) {
            body, .email-canvas {
                background-color: #f1f5f9 !important;
            }
            .email-card {
                background-color: #ffffff !important;
                border-color: #e2e8f0 !important;
            }
            .email-header {
                background-color: #0b192c !important;
            }
            .email-header-top {
                color: #94a3b8 !important;
            }
            .email-header-title {
                color: #ffffff !important;
            }
            .email-header-sub {
                color: #00a2b9 !important;
            }
            .email-body-text {
                color: #0f172a !important;
            }
            .email-muted-text {
                color: #475569 !important;
            }
            .email-table-bg {
                background-color: #f8fafc !important;
                border-color: #e2e8f0 !important;
            }
            .email-btn {
                background-color: #005082 !important;
                color: #ffffff !important;
            }
        }
    </style>
</head>
<body class="email-canvas" style="margin: 0; padding: 32px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" class="email-card" style="max-width: 580px; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td class="email-header" style="background-color: #0b192c; padding: 28px 28px 24px 28px; text-align: left; border-bottom: 3px solid #00a2b9;">
                            <div class="email-header-top" style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 class="email-header-title" style="margin: 0; font-size: 20px; font-weight: 800; line-height: 1.3; color: #ffffff;">
                                Bukti Tanda Terima Pendaftaran
                            </h1>
                            <div class="email-header-sub" style="margin-top: 6px; font-size: 13px; font-weight: 600; color: #00a2b9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px; background-color: #ffffff;">
                            <p class="email-body-text" style="margin: 0 0 14px 0; font-size: 15px; line-height: 1.6; color: #0f172a;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p class="email-muted-text" style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                Pendaftaran magang Anda di portal <strong>REMATE ITPLN</strong> telah berhasil tersimpan di sistem dengan rincian data sebagai berikut:
                            </p>
                            <table role="presentation" width="100%" class="email-table-bg" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 22px;" cellspacing="0" cellpadding="10" border="0">
                                <tr>
                                    <td width="38%" style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Nomor Registrasi</td>
                                    <td style="font-size: 13px; color: #005082; border-bottom: 1px solid #e2e8f0; font-weight: 700; font-family: monospace;">{$regNumber}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">NIM Mahasiswa</td>
                                    <td style="font-size: 13px; color: #0f172a; border-bottom: 1px solid #e2e8f0;">{$nim}</td>
                                </tr>
                                <tr>
                                    <td style="font-size: 13px; color: #64748b; border-bottom: 1px solid #e2e8f0; font-weight: 600;">Unit Magang Dipilih</td>
                                    <td style="font-size: 13px; color: #0f172a; border-bottom: 1px solid #e2e8f0; font-weight: 700;">{$namaUnit}</td>
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
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #00a2b9; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                                <div style="font-size: 13px; font-weight: 700; color: #005082; margin-bottom: 4px;">Informasi Seleksi &amp; Penempatan:</div>
                                <div style="font-size: 13px; color: #334155; line-height: 1.5;">
                                    Berkas dan formasi pilihan Anda akan diverifikasi oleh Badan Layanan Karir Alumni (BLKA) ITPLN serta unit tujuan. Perkembangan status seleksi dapat Anda pantau secara berkala melalui halaman <strong>Riwayat &amp; Pengumuman</strong> di portal magang.
                                </div>
                            </div>
                            <div style="text-align: center; margin: 24px 0 16px 0;">
                                <a href="{$statusUrl}" class="email-btn" style="display: inline-block; background-color: #005082; color: #ffffff; text-decoration: none; padding: 11px 22px; font-size: 13px; font-weight: 700; border-radius: 6px;">
                                    Pantau Status di Riwayat &amp; Pengumuman &rarr;
                                </a>
                            </div>
                            <p style="margin: 0; font-size: 12px; color: #94a3b8; text-align: center; line-height: 1.5;">
                                Simpan email ini sebagai tanda bukti resmi pendaftaran Anda.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 12px; line-height: 1.6;">
                            <div style="font-weight: 700; color: #334155;">Badan Layanan Karir Alumni (BLKA)</div>
                            <div style="color: #64748b;">Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 6px; font-size: 11px; color: #94a3b8;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
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

            $nama          = htmlspecialchars((string)($row['nama'] ?? 'Mahasiswa'));
            $namaUnit      = htmlspecialchars((string)($row['nama_unit'] ?? '-'));
            $namaPeriode   = htmlspecialchars((string)($row['nama_periode'] ?? 'Periode Aktif'));
            $periodeId     = (int)$row['periode_id'];
            $isPeriodeOpen = ($row['periode_status'] ?? '') === 'dibuka';
            $catatanText   = !empty($alasan) ? htmlspecialchars($alasan) : 'Formasi Anda belum memenuhi kebutuhan kami';
            $appUrl        = self::getAppUrl();
            $daftarUrl     = htmlspecialchars($appUrl . '/daftar.html');
            $statusUrl     = htmlspecialchars($appUrl . '/status.html');

            if ($isPeriodeOpen) {
                $statusNoticeHtml = <<<HTML
                    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #10b981; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                        <div style="font-size: 13px; font-weight: 700; color: #166534; margin-bottom: 4px;">Kesempatan Mendaftar Ulang:</div>
                        <div style="font-size: 13px; color: #14532d; line-height: 1.5;">
                            Anda <strong>dapat memilih kembali unit pelaksana lain</strong> yang masih memiliki kuota formasi selama masa pendaftaran masih dibuka. Silakan masuk ke portal magang untuk memilih unit baru.
                        </div>
                    </div>
                    <div style="text-align: center; margin: 24px 0 16px 0;">
                        <a href="{$daftarUrl}" class="email-btn" style="display: inline-block; background-color: #0b3d6b; color: #ffffff; text-decoration: none; padding: 11px 22px; font-size: 13px; font-weight: 700; border-radius: 6px;">
                            Pilih Unit Lain &amp; Daftar Ulang &rarr;
                        </a>
                    </div>
HTML;
            } else {
                $statusNoticeHtml = <<<HTML
                    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #64748b; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                        <div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">Informasi Pendaftaran:</div>
                        <div style="font-size: 13px; color: #475569; line-height: 1.5;">
                            Masa pendaftaran pada periode ini telah resmi ditutup. Terima kasih atas partisipasi dan dedikasi Anda. Perkembangan status dapat dipantau di halaman Riwayat &amp; Pengumuman.
                        </div>
                    </div>
                    <div style="text-align: center; margin: 24px 0 16px 0;">
                        <a href="{$statusUrl}" class="email-btn" style="display: inline-block; background-color: #005082; color: #ffffff; text-decoration: none; padding: 11px 22px; font-size: 13px; font-weight: 700; border-radius: 6px;">
                            Buka Riwayat &amp; Pengumuman &rarr;
                        </a>
                    </div>
HTML;
            }

            $subject = "[REMATE ITPLN] Pembaruan Status Pendaftaran Magang - Formasi Belum Sesuai";

            $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$subject}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, p, a, span { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        :root {
            color-scheme: light;
            supported-color-schemes: light;
        }
        body, table, td, p, a, span {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        @media (prefers-color-scheme: dark) {
            body, .email-canvas {
                background-color: #f1f5f9 !important;
            }
            .email-card {
                background-color: #ffffff !important;
                border-color: #e2e8f0 !important;
            }
            .email-header {
                background-color: #0b192c !important;
            }
            .email-header-top {
                color: #94a3b8 !important;
            }
            .email-header-title {
                color: #ffffff !important;
            }
            .email-header-sub {
                color: #00a2b9 !important;
            }
            .email-body-text {
                color: #0f172a !important;
            }
            .email-muted-text {
                color: #475569 !important;
            }
            .email-table-bg {
                background-color: #f8fafc !important;
                border-color: #e2e8f0 !important;
            }
            .email-btn {
                background-color: #005082 !important;
                color: #ffffff !important;
            }
        }
    </style>
</head>
<body class="email-canvas" style="margin: 0; padding: 32px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" class="email-card" style="max-width: 580px; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td class="email-header" style="background-color: #0b192c; padding: 28px 28px 24px 28px; text-align: left; border-bottom: 3px solid #00a2b9;">
                            <div class="email-header-top" style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 class="email-header-title" style="margin: 0; font-size: 20px; font-weight: 800; line-height: 1.3; color: #ffffff;">
                                Pemberitahuan Status Pendaftaran
                            </h1>
                            <div class="email-header-sub" style="margin-top: 6px; font-size: 13px; font-weight: 600; color: #00a2b9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px; background-color: #ffffff;">
                            <p class="email-body-text" style="margin: 0 0 14px 0; font-size: 15px; line-height: 1.6; color: #0f172a;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p class="email-muted-text" style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                Terima kasih atas partisipasi Anda dalam program magang {$namaPeriode}. Berdasarkan hasil verifikasi berkas dan penyesuaian kuota formasi pada unit <strong>{$namaUnit}</strong>, kami informasikan bahwa pengajuan Anda pada unit tersebut <strong>belum dapat disetujui</strong>.
                            </p>

                            <div style="background-color: #fff5f5; border: 1px solid #fecaca; border-left: 4px solid #ef4444; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                                <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #991b1b; margin-bottom: 4px;">Catatan Verifikator:</div>
                                <div style="font-size: 14px; font-weight: 600; color: #7f1d1d; line-height: 1.5;">
                                    "{$catatanText}"
                                </div>
                            </div>

                            {$statusNoticeHtml}

                            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9; text-align: center;">
                                <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                    Portal Resmi: <a href="{$appUrl}" style="color: #005082; font-weight: 600; text-decoration: none;">{$appUrl}</a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 12px; line-height: 1.6;">
                            <div style="font-weight: 700; color: #334155;">Badan Layanan Karir Alumni (BLKA)</div>
                            <div style="color: #64748b;">Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 6px; font-size: 11px; color: #94a3b8;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
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

            $picCache = [];
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

                $appUrl   = self::getAppUrl();
                $statusUrl = htmlspecialchars($appUrl . '/status.html');

                // Cari narahubung PIC jika diterima/dipindahkan (dengan memoization cache)
                $picInfo = '';
                if ($status === 'diterima' || $isDipindah) {
                    $entitasId = (int)$row['entitas_id'];
                    if (!array_key_exists($entitasId, $picCache)) {
                        $picCache[$entitasId] = PicNarahubung::resolveForUnit($entitasId);
                    }
                    $picData = $picCache[$entitasId];
                    if ($picData) {
                        $picNama = htmlspecialchars((string)($picData['nama_pic'] ?? '-'));
                        $picArea = htmlspecialchars((string)($picData['area_hcbp'] ?? ''));
                        $picAreaStr = $picArea ? " ({$picArea})" : "";
                        $picWa   = htmlspecialchars((string)($picData['no_wa'] ?? '-'));

                        $picInfo = "
                            <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; margin: 20px 0;'>
                                <div style='font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #005082; margin-bottom: 4px;'>Kontak PIC Narahubung Unit:</div>
                                <div style='font-size: 13px; color: #334155; line-height: 1.5;'>
                                    <strong>{$picNama}</strong>{$picAreaStr}<br>
                                    WhatsApp: {$picWa}
                                </div>
                            </div>
                        ";
                    }
                }

                // Banner Pemberitahuan Pembaruan
                $updateNotice = '';
                if ($isUpdateEmail) {
                    $updateNotice = "
                        <div style='background-color: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; padding: 14px 16px; border-radius: 6px; margin-bottom: 20px;'>
                            <div style='font-size: 13px; font-weight: 700; color: #92400e; margin-bottom: 4px;'>Pemberitahuan Pembaruan Pengumuman:</div>
                            <div style='font-size: 13px; color: #78350f; line-height: 1.5;'>
                                Email ini merupakan pembaruan resmi atas hasil seleksi sebelumnya setelah evaluasi penyesuaian kuota oleh Badan Layanan Karir Alumni (BLKA) ITPLN. Rincian di bawah adalah penetapan final yang berlaku.
                            </div>
                        </div>
                    ";
                }

                if ($status === 'diterima' || $isDipindah) {
                    $headline = $isUpdateEmail ? "Pembaruan Hasil Penempatan Magang" : ($isDipindah ? "Penetapan Penempatan Unit Magang" : "Selamat! Anda Diterima Magang");
                    $subjectPrefix = $isUpdateEmail ? "[Pembaruan Resmi] " : "";
                    $subject = "{$subjectPrefix}[REMATE ITPLN] Pengumuman Hasil Seleksi Magang - Diterima";
                    $pembuka = $isDipindah
                        ? "Selamat, pendaftaran magang Anda telah diproses. Berdasarkan koordinasi dan penyesuaian formasi kuota, Anda ditetapkan pada unit <strong>{$namaUnit}</strong> (dialihkan dari formasi awal {$namaAsal})."
                        : "Selamat! Berdasarkan hasil seleksi berkas dan ketersediaan kuota, Anda dinyatakan <strong>DITERIMA</strong> untuk melaksanakan magang di <strong>{$namaUnit}</strong>.";

                    $actionSection = <<<HTML
                        <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #10b981; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                            <div style="font-size: 13px; font-weight: 700; color: #166534; margin-bottom: 4px;">Instruksi Pengunduhan Berkas:</div>
                            <div style="font-size: 13px; color: #14532d; line-height: 1.5;">
                                Silakan login ke portal resmi REMATE ITPLN untuk mengunduh <strong>Surat Pengantar Magang</strong> resmi Anda pada halaman <strong>Riwayat &amp; Pengumuman</strong>.
                            </div>
                        </div>
                        <div style="text-align: center; margin: 24px 0 16px 0;">
                            <a href="{$statusUrl}" class="email-btn" style="display: inline-block; background-color: #005082; color: #ffffff; text-decoration: none; padding: 12px 24px; font-size: 13px; font-weight: 700; border-radius: 6px;">
                                Login &amp; Unduh Surat Pengantar &rarr;
                            </a>
                        </div>
HTML;
                } else {
                    $headline = $isUpdateEmail ? "Pembaruan Hasil Seleksi Magang" : "Pengumuman Hasil Seleksi Magang";
                    $subjectPrefix = $isUpdateEmail ? "[Pembaruan Resmi] " : "";
                    $subject = "{$subjectPrefix}[REMATE ITPLN] Pengumuman Hasil Seleksi Magang";
                    $pembuka = "Terima kasih atas partisipasi Anda dalam program magang {$namaPeriode}. Berdasarkan hasil seleksi berkas dan kuota penempatan, formasi Anda belum memenuhi kebutuhan kami.";

                    $actionSection = <<<HTML
                        <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #64748b; padding: 14px 16px; border-radius: 6px; margin-bottom: 22px;">
                            <div style="font-size: 13px; font-weight: 700; color: #334155; margin-bottom: 4px;">Informasi Pendaftaran:</div>
                            <div style="font-size: 13px; color: #475569; line-height: 1.5;">
                                Terima kasih atas partisipasi dan dedikasi Anda. Seluruh riwayat pendaftaran dapat dipantau di halaman Riwayat &amp; Pengumuman.
                            </div>
                        </div>
                        <div style="text-align: center; margin: 24px 0 16px 0;">
                            <a href="{$statusUrl}" class="email-btn" style="display: inline-block; background-color: #005082; color: #ffffff; text-decoration: none; padding: 11px 22px; font-size: 13px; font-weight: 700; border-radius: 6px;">
                                Buka Riwayat &amp; Pengumuman &rarr;
                            </a>
                        </div>
HTML;
                }

                $bodyHtml = <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{$subject}</title>
    <!--[if mso]>
    <style type="text/css">
        body, table, td, p, a, span { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        :root {
            color-scheme: light;
            supported-color-schemes: light;
        }
        body, table, td, p, a, span {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        @media (prefers-color-scheme: dark) {
            body, .email-canvas {
                background-color: #f1f5f9 !important;
            }
            .email-card {
                background-color: #ffffff !important;
                border-color: #e2e8f0 !important;
            }
            .email-header {
                background-color: #0b192c !important;
            }
            .email-header-top {
                color: #94a3b8 !important;
            }
            .email-header-title {
                color: #ffffff !important;
            }
            .email-header-sub {
                color: #00a2b9 !important;
            }
            .email-body-text {
                color: #0f172a !important;
            }
            .email-muted-text {
                color: #475569 !important;
            }
            .email-table-bg {
                background-color: #f8fafc !important;
                border-color: #e2e8f0 !important;
            }
            .email-btn {
                background-color: #005082 !important;
                color: #ffffff !important;
            }
        }
    </style>
</head>
<body class="email-canvas" style="margin: 0; padding: 32px 0; background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center" style="padding: 0 16px;">
                <table role="presentation" width="100%" class="email-card" style="max-width: 580px; background-color: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    <tr>
                        <td class="email-header" style="background-color: #0b192c; padding: 28px 28px 24px 28px; text-align: left; border-bottom: 3px solid #00a2b9;">
                            <div class="email-header-top" style="font-size: 11px; font-weight: 700; letter-spacing: 1.2px; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px;">
                                Institut Teknologi PLN • BLKA
                            </div>
                            <h1 class="email-header-title" style="margin: 0; font-size: 20px; font-weight: 800; line-height: 1.3; color: #ffffff;">
                                {$headline}
                            </h1>
                            <div class="email-header-sub" style="margin-top: 6px; font-size: 13px; font-weight: 600; color: #00a2b9;">
                                {$namaPeriode}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px; background-color: #ffffff;">
                            {$updateNotice}
                            <p class="email-body-text" style="margin: 0 0 14px 0; font-size: 15px; line-height: 1.6; color: #0f172a;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p class="email-muted-text" style="margin: 0 0 20px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                {$pembuka}
                            </p>
                            {$picInfo}
                            {$actionSection}

                            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9; text-align: center;">
                                <p style="margin: 0; font-size: 12px; color: #94a3b8; line-height: 1.5;">
                                    Portal Resmi: <a href="{$appUrl}" style="color: #005082; font-weight: 600; text-decoration: none;">{$appUrl}</a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8fafc; padding: 20px 28px; text-align: center; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 12px; line-height: 1.6;">
                            <div style="font-weight: 700; color: #334155;">Badan Layanan Karir Alumni (BLKA)</div>
                            <div style="color: #64748b;">Institut Teknologi PLN • Menara PLN, Jl. Lingkar Luar Barat, Cengkareng, Jakarta Barat</div>
                            <div style="margin-top: 6px; font-size: 11px; color: #94a3b8;">Pesan ini dikirim secara otomatis oleh sistem REMATE ITPLN.</div>
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

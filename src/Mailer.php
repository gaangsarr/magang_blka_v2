<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use Throwable;

class Mailer
{
    /**
     * Memeriksa apakah konfigurasi SMTP sudah diset di .env
     */
    public static function isConfigured(): bool
    {
        $user = trim($_ENV['SMTP_USER'] ?? '');
        $pass = trim($_ENV['SMTP_PASS'] ?? '');
        return !empty($user) && !empty($pass);
    }

    /**
     * Instansiasi objek PHPMailer yang terkonfigurasi dengan .env
     */
    public static function createMailer(bool $debug = false): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        if ($debug) {
            $mail->SMTPDebug = 2; // Output debug verbose
        }

        $mail->isSMTP();
        $mail->Host       = trim($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($_ENV['SMTP_USER'] ?? '');
        
        // Google App Password sering dicopy dengan spasi: bersihkan spasi secara otomatis
        $password         = str_replace(' ', '', trim($_ENV['SMTP_PASS'] ?? ''));
        $mail->Password   = $password;

        $encryption = strtolower(trim($_ENV['SMTP_ENCRYPTION'] ?? 'tls'));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 465);
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int)($_ENV['SMTP_PORT'] ?? 587);
        }

        $mail->Timeout = 15;

        // Toleransi SSL lingkungan lokal jika diperlukan
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ];

        $fromEmail = trim($_ENV['SMTP_FROM_EMAIL'] ?? $mail->Username);
        $fromName  = trim($_ENV['SMTP_FROM_NAME'] ?? 'REMATE ITPLN - Sistem Magang');
        $mail->setFrom($fromEmail, $fromName);

        return $mail;
    }

    /**
     * Kirim email generik HTML
     *
     * @return array{ok: bool, message?: string, error?: string}
     */
    public static function send(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $altBody = ''
    ): array {
        if (!self::isConfigured()) {
            return [
                'ok'    => false,
                'error' => 'Konfigurasi SMTP belum diisi di file .env (SMTP_USER / SMTP_PASS kosong).'
            ];
        }

        try {
            $mail = self::createMailer();
            $mail->addAddress(trim($toEmail), trim($toName));
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = !empty($altBody) ? $altBody : strip_tags($htmlBody);

            $mail->send();

            return [
                'ok'      => true,
                'message' => 'Email berhasil dikirimkan.'
            ];
        } catch (Exception $e) {
            error_log('[Mailer Error] PHPMailer: ' . $e->getMessage());
            return [
                'ok'    => false,
                'error' => $e->getMessage()
            ];
        } catch (Throwable $e) {
            error_log('[Mailer Error] System: ' . $e->getMessage());
            return [
                'ok'    => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Mengirim email Bukti Tanda Terima Pendaftaran Magang ke mahasiswa
     *
     * @param array $data ['email', 'nama', 'nim', 'id', 'nama_unit', 'program', 'nama_periode', 'submitted_at']
     * @return array{ok: bool, error?: string}
     */
    public static function sendBuktiPendaftaran(array $data): array
    {
        $toEmail = $data['email'] ?? '';
        if (empty($toEmail)) {
            return ['ok' => false, 'error' => 'Alamat email mahasiswa tidak ditemukan.'];
        }

        $nama        = htmlspecialchars((string)($data['nama'] ?? 'Mahasiswa'));
        $nim         = htmlspecialchars((string)($data['nim'] ?? '-'));
        $regId       = (int)($data['id'] ?? 0);
        $regNumber   = 'REG-' . str_pad((string)$regId, 6, '0', STR_PAD_LEFT);
        $namaUnit    = htmlspecialchars((string)($data['nama_unit'] ?? '-'));
        $namaPeriode = htmlspecialchars((string)($data['nama_periode'] ?? 'Periode Aktif'));
        $submittedAt = htmlspecialchars((string)($data['submitted_at'] ?? date('d F Y, H:i') . ' WIB'));

        $programMap = [
            '1_bulan' => 'Magang 1 Bulan',
            '3_bulan' => 'Magang 3 Bulan',
            '4_bulan' => 'Magang 4 Bulan',
            '5_bulan' => 'Magang 5 Bulan (KRS)'
        ];
        $programLabel = $programMap[$data['program'] ?? ''] ?? htmlspecialchars((string)($data['program'] ?? '-'));

        $subject = "[REMATE ITPLN] Bukti Pendaftaran Magang - {$regNumber}";

        $htmlBody = <<<HTML
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
                <table role="presentation" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0;" cellspacing="0" cellpadding="0" border="0">
                    
                    <!-- Header -->
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

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 28px;">
                            <p style="margin: 0 0 16px 0; font-size: 15px; line-height: 1.6;">
                                Halo <strong>{$nama}</strong>,
                            </p>
                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #475569;">
                                Pendaftaran magang Anda di portal <strong>REMATE (Rekrutmen & Magang Terpadu ITPLN)</strong> telah berhasil tersimpan di sistem dengan rincian sebagai berikut:
                            </p>

                            <!-- Detail Card -->
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

                            <!-- Informasi Tahapan Selanjutnya -->
                            <div style="background-color: #eff6ff; border-left: 4px solid #0284c7; padding: 14px 16px; border-radius: 4px; margin-bottom: 24px;">
                                <div style="font-size: 13px; font-weight: 700; color: #0369a1; margin-bottom: 4px;">Informasi Seleksi & Penempatan:</div>
                                <div style="font-size: 13px; color: #0c4a6e; line-height: 1.5;">
                                    Berkas dan formasi pilihan Anda akan diverifikasi oleh Biro Layanan Karir dan Alumni (BLKA) ITPLN serta unit tujuan. Anda dapat memantau perkembangan status secara berkala melalui menu <strong>Status Pendaftaran</strong> di portal magang.
                                </div>
                            </div>

                            <p style="margin: 0; font-size: 13px; color: #64748b; line-height: 1.5;">
                                Simpan email ini sebagai tanda bukti resmi pendaftaran Anda. Jika terdapat kendala, silakan hubungi narahubung BLKA ITPLN.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
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

        return self::send($toEmail, (string)($data['nama'] ?? 'Mahasiswa'), $subject, $htmlBody);
    }
}

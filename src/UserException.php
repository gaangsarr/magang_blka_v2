<?php

namespace App;

/**
 * UserException — Exception dengan pesan yang AMAN ditampilkan ke end-user.
 *
 * Gunakan class ini untuk melempar error bisnis/validasi yang pesannya
 * memang dimaksudkan untuk dibaca oleh pengguna (bukan detail teknis).
 *
 * Contoh penggunaan:
 *   throw new UserException('Anda sudah terdaftar pada periode ini.');
 *   throw new UserException('Waktu reservasi telah habis.', 400);
 *
 * Di catch block:
 *   catch (UserException $e) → tampilkan $e->getMessage() ke client
 *   catch (\Throwable $e)    → sembunyikan via Auth::safeErrorMessage()
 */
class UserException extends \RuntimeException
{
    public function __construct(string $message = '', int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

<?php

declare(strict_types=1);

namespace App;

class ProdiCredentialsHelper
{
    /**
     * Peta singkatan/akronim resmi prodi ITPLN untuk generate password default
     */
    private static array $abbreviationMap = [
        '11' => 'te',    // S1 Teknik Elektro
        '12' => 'tm',    // S1 Teknik Mesin
        '14' => 'ttl',   // S1 Teknik Tenaga Listrik
        '15' => 'tse',   // S1 Teknik Sistem Energi
        '21' => 'ts',    // S1 Teknik Sipil
        '22' => 'tg',    // S1 Teknik Geografi
        '23' => 'tl',    // S1 Teknik Lingkungan
        '31' => 'ti',    // S1 Teknik Informatika
        '32' => 'si',    // S1 Sistem Informasi
        '33' => 'ds',    // S1 Data Sains
        '41' => 'be',    // S1 Bisnis Energi
        '42' => 'ti',    // S1 Teknik Industri
        '71' => 'tl',    // D3 Teknologi Listrik
        '72' => 'tm',    // D3 Teknik Mesin
    ];

    /**
     * Generate default username untuk Program Studi
     * Contoh:
     * - S1 Teknik Informatika -> teknikinformatika
     * - D3 Teknik Mesin -> d3teknikmesin
     */
    public static function generateDefaultUsername(string $namaJurusan, string $jenjang = 'S1'): string
    {
        // Bersihkan nama dari simbol dan spasi
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($namaJurusan));
        $cleanJenjang = strtoupper(trim($jenjang));

        if ($cleanJenjang === 'D3') {
            return 'd3' . $cleanName;
        }

        return $cleanName;
    }

    /**
     * Generate default password untuk Program Studi
     * Format: [d3]{singkatan}{kode}
     * Contoh:
     * - S1 Teknik Informatika (31) -> ti31
     * - D3 Teknik Mesin (72) -> d3tm72
     */
    public static function generateDefaultPassword(string $namaJurusan, string $kode, string $jenjang = 'S1'): string
    {
        $kodeClean = trim($kode);
        $cleanJenjang = strtoupper(trim($jenjang));

        // Ambil akronim terdaftar, atau buat dari inisial setiap kata jika ada prodi baru
        if (isset(self::$abbreviationMap[$kodeClean])) {
            $abbr = self::$abbreviationMap[$kodeClean];
        } else {
            $words = explode(' ', trim($namaJurusan));
            $abbr = '';
            foreach ($words as $w) {
                if (!empty($w)) {
                    $abbr .= strtolower($w[0]);
                }
            }
        }

        if ($cleanJenjang === 'D3') {
            return 'd3' . $abbr . $kodeClean;
        }

        return $abbr . $kodeClean;
    }
}

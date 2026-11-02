<?php

namespace App\Support;

/**
 * Satu tempat untuk mengubah angka uang menjadi teks yang dibaca manusia.
 *
 * Penyimpanan tetap apa adanya (desimal di ledger, integer rupiah di kontrak API);
 * pemformatan hanya urusan tampilan. Aturannya ditaruh di kelas ini, bukan di
 * komponen Blade, karena pesan kilat di controller juga menyebut nominal — kalau
 * aturannya hidup di view, salinan kedua akan muncul di sana dan tidak ikut berubah.
 */
final class Rupiah
{
    public static function format(int|float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return 'Rp 0';
        }

        $value = (int) round((float) $amount);
        $sign = $value < 0 ? '-' : '';
        $digits = (string) abs($value);

        $formatted = '';
        $length = strlen($digits);

        for ($index = 0; $index < $length; $index++) {
            if ($index > 0 && ($length - $index) % 3 === 0) {
                $formatted .= '.';
            }
            $formatted .= $digits[$index];
        }

        return $sign.'Rp '.$formatted;
    }
}

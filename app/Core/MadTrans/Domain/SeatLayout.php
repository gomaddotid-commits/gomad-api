<?php

namespace App\Core\MadTrans\Domain;

use InvalidArgumentException;

/**
 * Formasi kursi kendaraan.
 *
 * Owner tidak mengetik kode kursi satu per satu; ia memilih berapa kursi di tiap
 * baris (mis. 2-3-3 untuk Hiace, 2-2-2 untuk minibus). Dari formasi itu dibentuk
 * kode kursi `A1, A2, B1, …` yang menjadi satu-satunya sumber kebenaran penjualan.
 *
 * Kursi supir **tidak** ikut di sini: ia bukan kursi yang dijual, hanya ditandai
 * lewat `vehicles.has_driver_seat` supaya denah tetap jujur saat ditampilkan.
 */
final class SeatLayout
{
    public const MAX_ROWS = 8;

    public const MAX_SEATS_PER_ROW = 4;

    public const MAX_SEATS = 40;

    /**
     * @param  list<int|string>  $counts  jumlah kursi per baris, baris pertama = A
     * @return list<string>
     */
    public static function fromRows(array $counts): array
    {
        $seats = [];

        foreach (array_values($counts) as $index => $count) {
            if ($index >= self::MAX_ROWS) {
                throw new InvalidArgumentException('Maksimal '.self::MAX_ROWS.' baris kursi.');
            }

            $count = (int) $count;

            if ($count < 0 || $count > self::MAX_SEATS_PER_ROW) {
                throw new InvalidArgumentException('Jumlah kursi per baris harus antara 0 dan '.self::MAX_SEATS_PER_ROW.'.');
            }

            if ($count === 0) {
                continue;
            }

            $label = self::rowLabel($index);

            for ($seat = 1; $seat <= $count; $seat++) {
                $seats[] = $label.$seat;
            }
        }

        if ($seats === []) {
            throw new InvalidArgumentException('Pilih minimal satu baris kursi.');
        }

        if (count($seats) > self::MAX_SEATS) {
            throw new InvalidArgumentException('Maksimal '.self::MAX_SEATS.' kursi per kendaraan.');
        }

        return $seats;
    }

    /**
     * Jumlah kursi per baris dari daftar kode kursi.
     *
     * @param  list<string>  $seats
     * @return array<string, int>
     */
    public static function rows(array $seats): array
    {
        $rows = [];

        foreach ($seats as $seat) {
            $label = preg_replace('/\d+/', '', (string) $seat) ?: '?';
            $rows[$label] = ($rows[$label] ?? 0) + 1;
        }

        ksort($rows);

        return $rows;
    }

    /**
     * Ringkasan formasi, mis. "2-3-3", untuk ditampilkan di daftar armada.
     *
     * @param  list<string>  $seats
     */
    public static function summary(array $seats): string
    {
        return implode('-', array_values(self::rows($seats))) ?: '—';
    }

    public static function rowLabel(int $index): string
    {
        return chr(65 + $index);
    }
}

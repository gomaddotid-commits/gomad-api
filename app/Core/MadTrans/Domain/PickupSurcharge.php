<?php

namespace App\Core\MadTrans\Domain;

/**
 * Hasil hitung biaya jemput di luar titik kumpul.
 *
 * `fee` dalam rupiah bulat (integer) supaya bisa langsung dipakai LedgerMoney.
 * Biaya ini milik usaha (agency), bukan platform.
 */
final class PickupSurcharge
{
    public function __construct(
        public readonly ?float $distanceKm,
        public readonly int $freeRadiusKm,
        public readonly int $billableKm,
        public readonly int $fee,
    ) {}

    public static function none(): self
    {
        return new self(null, 0, 0, 0);
    }

    public function isChargeable(): bool
    {
        return $this->fee > 0;
    }

    /**
     * Penjelasan singkat untuk ditampilkan ke pelanggan.
     */
    public function explanation(): string
    {
        if ($this->distanceKm === null) {
            return 'Titik jemput tidak dihitung.';
        }

        if (! $this->isChargeable()) {
            return number_format($this->distanceKm, 1, ',', '.').' km dari titik kumpul — masih dalam radius gratis '
                .$this->freeRadiusKm.' km.';
        }

        return number_format($this->distanceKm, 1, ',', '.').' km dari titik kumpul — '
            .$this->billableKm.' km di luar radius gratis '.$this->freeRadiusKm.' km.';
    }
}

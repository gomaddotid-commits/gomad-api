<?php

namespace App\Core\Rental\Application;

use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Rental\Domain\RentalCheckoutNotConfigured;

final class RentalFare
{
    /**
     * Rental memakai service fee tetap per transaksi, platform fee persentase
     * dari harga sewa, dan PPN dihitung dari kedua fee tersebut.
     *
     * @return array{base: LedgerMoney, driver: LedgerMoney, service: LedgerMoney, platform: LedgerMoney, tax: LedgerMoney, total: LedgerMoney}
     */
    public function calculate(LedgerMoney $dailyPrice, LedgerMoney $dailyDriverFee, int $days): array
    {
        $serviceFee = (int) config('services.rental.service_fee', 10000);
        $platformBasisPoints = (int) config('services.rental.platform_fee_basis_points', 300);
        $taxBasisPoints = config('services.rental.ppn_basis_points');

        if ($serviceFee < 0 || $platformBasisPoints < 0 || $platformBasisPoints > 10000) {
            throw new \LogicException('Rental fee configuration is outside the supported range.');
        }
        if (! is_int($taxBasisPoints) || $taxBasisPoints < 0 || $taxBasisPoints > 10000) {
            throw new RentalCheckoutNotConfigured('Tarif PPN rental harus dikonfigurasi sebelum pemesanan.');
        }
        if ($days < 1 || $days > 365) {
            throw new \InvalidArgumentException('Masa sewa harus antara 1 hingga 365 hari.');
        }
        if ($dailyPrice->toCents() % 100 !== 0 || $dailyDriverFee->toCents() % 100 !== 0) {
            throw new \InvalidArgumentException('Tarif sewa harus berupa rupiah bulat.');
        }

        $dailyBase = intdiv($dailyPrice->toCents(), 100);
        $dailyDriver = intdiv($dailyDriverFee->toCents(), 100);
        $rentalTotal = $this->multiply($dailyBase + $dailyDriver, $days);
        $platform = $this->basisPoints($this->multiply($dailyBase, $days), $platformBasisPoints);
        $tax = $this->basisPoints($serviceFee + $platform, $taxBasisPoints);

        $base = LedgerMoney::from((string) $this->multiply($dailyBase, $days));
        $driver = LedgerMoney::from((string) $this->multiply($dailyDriver, $days));
        $service = LedgerMoney::from((string) $serviceFee);
        $platformMoney = LedgerMoney::from((string) $platform);
        $taxMoney = LedgerMoney::from((string) $tax);

        return [
            'base' => $base,
            'driver' => $driver,
            'service' => $service,
            'platform' => $platformMoney,
            'tax' => $taxMoney,
            'total' => LedgerMoney::from((string) $rentalTotal)
                ->plus($service)
                ->plus($platformMoney)
                ->plus($taxMoney),
        ];
    }

    private function multiply(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity < 1 || $amount > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \InvalidArgumentException('Nilai sewa berada di luar rentang yang didukung.');
        }

        return $amount * $quantity;
    }

    private function basisPoints(int $amountRupiah, int $basisPoints): int
    {
        $whole = intdiv($amountRupiah, 10000) * $basisPoints;
        $fraction = intdiv(($amountRupiah % 10000) * $basisPoints + 5000, 10000);

        return $whole + $fraction;
    }
}

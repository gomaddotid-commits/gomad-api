<?php

namespace App\Core\MadTrans\Application;

use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\MadTrans\Domain\TravelCheckoutNotConfigured;

final class TravelBookingFare
{
    /**
     * @return array{base: LedgerMoney, pickup: LedgerMoney, service: LedgerMoney, platform: LedgerMoney, tax: LedgerMoney, total: LedgerMoney}
     */
    public function calculate(LedgerMoney $unitFare, int $passengers, ?LedgerMoney $pickupFee = null): array
    {
        $serviceFeePerPassenger = (int) config('services.mad_trans.service_fee', 5000);
        $platformBasisPoints = (int) config('services.mad_trans.platform_fee_basis_points', 300);
        $taxBasisPoints = config('services.mad_trans.ppn_basis_points');
        if ($serviceFeePerPassenger < 0 || $platformBasisPoints < 0 || $platformBasisPoints > 10000) {
            throw new \LogicException('Mad Trans fee configuration is outside the supported range.');
        }
        if (! is_int($taxBasisPoints) || $taxBasisPoints < 0 || $taxBasisPoints > 10000) {
            throw new TravelCheckoutNotConfigured('Tarif PPN Mad Trans harus dikonfigurasi sebelum pemesanan.');
        }
        if ($passengers < 1 || $passengers > 100) {
            throw new \InvalidArgumentException('Jumlah penumpang harus antara 1 hingga 100.');
        }
        if ($unitFare->toCents() % 100 !== 0) {
            throw new \InvalidArgumentException('Tarif perjalanan harus berupa rupiah bulat.');
        }

        $unitBase = intdiv($unitFare->toCents(), 100);
        $unitPlatform = $this->basisPoints($unitBase, $platformBasisPoints);
        $unitTax = $this->basisPoints($serviceFeePerPassenger + $unitPlatform, $taxBasisPoints);
        $service = LedgerMoney::from((string) $this->multiply($serviceFeePerPassenger, $passengers));
        $base = LedgerMoney::from((string) $this->multiply($unitBase, $passengers));
        $platform = LedgerMoney::from((string) $this->multiply($unitPlatform, $passengers));
        $tax = LedgerMoney::from((string) $this->multiply($unitTax, $passengers));

        // Biaya jemput milik usaha (biaya angkut), jadi tidak dihitung sebagai dasar
        // platform fee maupun PPN — hanya ditambahkan ke total dan ke hak agency.
        $pickup = $pickupFee ?? LedgerMoney::from('0');

        return [
            'base' => $base,
            'pickup' => $pickup,
            'service' => $service,
            'platform' => $platform,
            'tax' => $tax,
            'total' => $pickup->plus($base)->plus($service)->plus($platform)->plus($tax),
        ];
    }

    private function multiply(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity < 1 || $amount > intdiv(PHP_INT_MAX, $quantity)) {
            throw new \InvalidArgumentException('Nilai pemesanan berada di luar rentang yang didukung.');
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

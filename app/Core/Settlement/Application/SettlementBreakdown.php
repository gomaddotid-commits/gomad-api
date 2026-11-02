<?php

namespace App\Core\Settlement\Application;

use App\Models\Business;
use App\Models\Rental;
use App\Models\TravelBooking;
use Illuminate\Support\Collection;

/**
 * Rincian settlement per transaksi (temuan A12/F5 pada `docs/04-checklist-jangan-diulang.md`):
 * harga penjual + fee platform + PPN − diskon = hak bersih penjual.
 *
 * Diskon yang ditanggung platform tidak mengurangi hak bersih penjual; yang ditanggung
 * usaha sendiri mengurangi.
 */
final class SettlementBreakdown
{
    private const PAID_BOOKING_STATUSES = ['paid', 'on_going', 'completed'];

    private const PAID_RENTAL_STATUSES = ['paid', 'active', 'returned', 'completed'];

    /**
     * @return array{
     *     rows: list<array{code: string, kind: string, date: string|null, price: string, fee: string, ppn: string, discount: string, net: string, deposit: string}>,
     *     totals: array{price: string, fee: string, ppn: string, discount: string, net: string, deposit: string}
     * }
     */
    public function forBusiness(Business $business, int $limit = 20): array
    {
        $rows = [];

        $bookings = TravelBooking::query()
            ->whereHas('schedule', fn ($query) => $query->where('business_id', $business->getKey()))
            ->whereIn('status', self::PAID_BOOKING_STATUSES)
            ->latest('paid_at')
            ->limit($limit)
            ->get(['code', 'base_price', 'service_fee', 'platform_fee', 'ppn_amount', 'discount_amount', 'discount_bearer', 'paid_at']);

        foreach ($bookings as $booking) {
            $rows[] = $this->row(
                code: $booking->code,
                kind: 'Tiket travel',
                date: $booking->paid_at?->toDateTimeString(),
                price: (float) $booking->base_price,
                fee: (float) $booking->service_fee + (float) $booking->platform_fee,
                ppn: (float) $booking->ppn_amount,
                discount: (float) $booking->discount_amount,
                bearer: (string) $booking->discount_bearer,
                deposit: 0.0,
            );
        }

        $rentals = Rental::query()
            ->where('business_id', $business->getKey())
            ->whereIn('status', self::PAID_RENTAL_STATUSES)
            ->latest('paid_at')
            ->limit($limit)
            ->get(['code', 'price', 'driver_fee', 'service_fee', 'platform_fee', 'ppn_amount', 'discount_amount', 'discount_bearer', 'deposit_amount', 'paid_at']);

        foreach ($rentals as $rental) {
            $rows[] = $this->row(
                code: $rental->code,
                kind: 'Sewa kendaraan',
                date: $rental->paid_at?->toDateTimeString(),
                price: (float) $rental->price + (float) $rental->driver_fee,
                fee: (float) $rental->service_fee + (float) $rental->platform_fee,
                ppn: (float) $rental->ppn_amount,
                discount: (float) $rental->discount_amount,
                bearer: (string) $rental->discount_bearer,
                deposit: (float) $rental->deposit_amount,
            );
        }

        $collection = Collection::make($rows)->sortByDesc('date')->values();

        return [
            'rows' => $collection->all(),
            'totals' => [
                'price' => $this->sum($collection, 'price'),
                'fee' => $this->sum($collection, 'fee'),
                'ppn' => $this->sum($collection, 'ppn'),
                'discount' => $this->sum($collection, 'discount'),
                'net' => $this->sum($collection, 'net'),
                'deposit' => $this->sum($collection, 'deposit'),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function sum(Collection $rows, string $key): string
    {
        return number_format((float) $rows->sum(fn (array $row): float => (float) $row[$key]), 2, '.', '');
    }

    /**
     * @return array{code: string, kind: string, date: string|null, price: string, fee: string, ppn: string, discount: string, net: string, deposit: string}
     */
    private function row(
        string $code,
        string $kind,
        ?string $date,
        float $price,
        float $fee,
        float $ppn,
        float $discount,
        string $bearer,
        float $deposit,
    ): array {
        $net = $bearer === 'business' ? $price - $discount : $price;

        return [
            'code' => $code,
            'kind' => $kind,
            'date' => $date,
            'price' => number_format($price, 2, '.', ''),
            'fee' => number_format($fee, 2, '.', ''),
            'ppn' => number_format($ppn, 2, '.', ''),
            'discount' => number_format($discount, 2, '.', ''),
            'net' => number_format($net, 2, '.', ''),
            'deposit' => number_format($deposit, 2, '.', ''),
        ];
    }
}

<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\CreateWalletHold;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\CodCollateralInsufficient;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Promo\Application\RedeemPromo;
use App\Core\Promo\Application\ResolvePromo;
use App\Models\BookingPassenger;
use App\Models\BusinessPickupPolicy;
use App\Models\RoutePricing;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateTravelBooking
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly TravelBookingFare $fareCalculator,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly CreateWalletHold $createHold,
        private readonly ResolvePromo $resolvePromo,
        private readonly RedeemPromo $redeemPromo,
        private readonly ComputePickupSurcharge $pickupSurcharge,
    ) {}

    /**
     * @param  list<array{name: string, phone?: string|null, seat_number: string}>  $passengers
     */
    public function execute(
        User $customer,
        TravelSchedule $schedule,
        int $originStopId,
        int $destinationStopId,
        array $passengers,
        string $paymentMethod,
        string $idempotencyKey,
        ?string $promoCode = null,
        ?string $pickupInput = null,
        ?string $pickupAddress = null,
    ): TravelBooking {
        $this->authorizer->authorize($customer, 'booking.create');
        if (! in_array($paymentMethod, ['midtrans', 'cod'], true)) {
            throw new \InvalidArgumentException('Metode pembayaran perjalanan tidak didukung.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }
        if ($passengers === [] || count($passengers) > 10) {
            throw new \InvalidArgumentException('Pemesanan harus berisi 1 sampai 10 penumpang.');
        }

        $passengers = collect($passengers)->map(fn (array $passenger): array => [
            'name' => trim($passenger['name']),
            'phone' => isset($passenger['phone']) ? trim($passenger['phone']) : null,
            'seat_number' => strtoupper(trim($passenger['seat_number'])),
        ])->values()->all();
        $seatNumbers = array_column($passengers, 'seat_number');
        if (in_array('', $seatNumbers, true) || count(array_unique($seatNumbers)) !== count($seatNumbers)) {
            throw new \InvalidArgumentException('Setiap penumpang harus memilih nomor kursi berbeda.');
        }

        $requestHash = hash('sha256', json_encode([
            'customer_id' => $customer->getKey(),
            'schedule_id' => $schedule->getKey(),
            'origin_stop_id' => $originStopId,
            'destination_stop_id' => $destinationStopId,
            'passengers' => $passengers,
            'payment_method' => $paymentMethod,
            'promo_code' => $promoCode !== null ? strtoupper(trim($promoCode)) : '',
            'pickup' => $pickupInput !== null ? trim($pickupInput) : '',
        ], JSON_THROW_ON_ERROR));
        $idempotencyKey = 'mt:'.hash('sha256', $customer->getKey().':'.$idempotencyKey);

        try {
            return DB::transaction(function () use (
                $customer,
                $schedule,
                $originStopId,
                $destinationStopId,
                $passengers,
                $seatNumbers,
                $paymentMethod,
                $requestHash,
                $idempotencyKey,
                $promoCode,
                $pickupInput,
                $pickupAddress,
            ): TravelBooking {
                $existing = TravelBooking::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertSameRequest($existing, $requestHash);

                    return $existing->load('passengers');
                }

                $lockedSchedule = TravelSchedule::query()
                    ->whereKey($schedule->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedSchedule->status !== ScheduleStatus::Published->value
                    || Carbon::parse($lockedSchedule->departure_date->format('Y-m-d').' '.$lockedSchedule->departure_time)->isPast()) {
                    throw new PaymentConflict('Jadwal tidak tersedia untuk dipesan.');
                }
                if ($paymentMethod === 'cod'
                    && (! $lockedSchedule->allow_cod
                        || ! in_array('cod', $lockedSchedule->payment_methods ?? [], true))) {
                    throw new PaymentConflict('COD belum diaktifkan untuk jadwal ini.');
                }
                $layout = $lockedSchedule->vehicle->seat_layout;
                if (! is_array($layout) || array_diff($seatNumbers, $layout) !== []) {
                    throw new \InvalidArgumentException('Satu atau beberapa kursi tidak tersedia pada armada ini.');
                }
                $origin = $lockedSchedule->route->stops()->whereKey($originStopId)->first();
                $destination = $lockedSchedule->route->stops()->whereKey($destinationStopId)->first();
                if ($origin === null || $destination === null || $origin->stop_order >= $destination->stop_order) {
                    throw new \InvalidArgumentException('Segmen rute yang dipilih tidak valid.');
                }
                $pricing = RoutePricing::query()
                    ->where('route_id', $lockedSchedule->route_id)
                    ->where('origin_stop_id', $originStopId)
                    ->where('destination_stop_id', $destinationStopId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if ($pricing === null) {
                    throw new \InvalidArgumentException('Harga untuk segmen rute yang dipilih tidak tersedia.');
                }

                $expiredPassengers = BookingPassenger::query()
                    ->where('schedule_id', $lockedSchedule->getKey())
                    ->whereIn('seat_number', $seatNumbers)
                    ->whereHas('booking', fn ($query) => $query
                        ->where('status', BookingStatus::Pending->value)
                        ->where('expires_at', '<=', now()))
                    ->lockForUpdate()
                    ->get();
                foreach ($expiredPassengers as $expiredPassenger) {
                    $expiredPassenger->forceFill([
                        'seat_number' => null,
                        'seat_released_at' => now(),
                    ])->save();
                }
                $occupied = BookingPassenger::query()
                    ->where('schedule_id', $lockedSchedule->getKey())
                    ->whereIn('seat_number', $seatNumbers)
                    ->whereHas('booking', fn ($query) => $query
                        ->where(fn ($active) => $active
                            ->whereIn('status', [
                                BookingStatus::Confirmed->value,
                                BookingStatus::Paid->value,
                                BookingStatus::OnGoing->value,
                            ])
                            ->orWhere(fn ($pending) => $pending
                                ->where('status', BookingStatus::Pending->value)
                                ->where('expires_at', '>', now())))
                        // Kursi hanya bentrok bila segmennya bertumpang tindih.
                        ->whereHas('originStop', fn ($stop) => $stop
                            ->where('stop_order', '<', (int) $destination->stop_order))
                        ->whereHas('destinationStop', fn ($stop) => $stop
                            ->where('stop_order', '>', (int) $origin->stop_order)))
                    ->pluck('seat_number')
                    ->all();
                if ($occupied !== []) {
                    throw new PaymentConflict('Satu atau beberapa kursi sudah dipesan.');
                }
                $fare = $this->fareCalculator->calculate(
                    LedgerMoney::from((string) $pricing->price),
                    count($passengers),
                );

                // Titik jemput di luar titik kumpul: jarak & biaya dihitung server,
                // bukan dari angka yang dikirim klien.
                $customerPickup = $pickupInput !== null && trim($pickupInput) !== ''
                    ? GeoPoint::fromInput($pickupInput)
                    : null;
                // `$origin` di sini adalah RouteStop (titik kumpul), bukan schedule stop.
                $stopPoint = $origin->point();
                $pickupCharge = $this->pickupSurcharge->execute(
                    BusinessPickupPolicy::query()->where('business_id', $lockedSchedule->business_id)->first(),
                    $stopPoint,
                    $customerPickup,
                );
                $fare = $this->fareCalculator->calculate(
                    LedgerMoney::from((string) $pricing->price),
                    count($passengers),
                    LedgerMoney::from((string) $pickupCharge->fee),
                );

                if ($promoCode !== null && trim($promoCode) !== '' && $paymentMethod !== 'midtrans') {
                    throw new \InvalidArgumentException('Kode promo hanya berlaku untuk pembayaran online.');
                }

                $promoQuote = $this->resolvePromo->execute(
                    $promoCode,
                    'travel',
                    $lockedSchedule->business,
                    $customer,
                    $fare['base'],
                );
                $discount = $promoQuote?->discount ?? LedgerMoney::from('0');
                $total = $discount->isPositive() ? $fare['total']->minus($discount) : $fare['total'];
                $expiresAt = $paymentMethod === 'cod'
                    ? Carbon::parse($lockedSchedule->departure_date->format('Y-m-d').' '.$lockedSchedule->departure_time)
                    : now()->addMinutes((int) config('services.midtrans.payment_timeout', 30));
                $booking = TravelBooking::query()->create([
                    'code' => 'MT-TR-'.now()->format('ym').'-'.Str::upper(Str::random(8)),
                    'schedule_id' => $lockedSchedule->getKey(),
                    'customer_id' => $customer->getKey(),
                    'origin_stop_id' => $originStopId,
                    'destination_stop_id' => $destinationStopId,
                    'route_pricing_id' => $pricing->getKey(),
                    'pickup_address' => $pickupAddress !== null && trim($pickupAddress) !== '' ? trim($pickupAddress) : null,
                    'pickup_latitude' => $customerPickup?->latitude,
                    'pickup_longitude' => $customerPickup?->longitude,
                    'pickup_distance_km' => $pickupCharge->distanceKm,
                    'pickup_fee' => LedgerMoney::from((string) $pickupCharge->fee)->toDecimal(),
                    'total_passengers' => count($passengers),
                    'base_price' => $fare['base']->toDecimal(),
                    'service_fee' => $fare['service']->toDecimal(),
                    'platform_fee' => $fare['platform']->toDecimal(),
                    'discount_amount' => $discount->toDecimal(),
                    'promo_id' => $promoQuote?->promo->getKey(),
                    'promo_code' => $promoQuote?->promo->code,
                    'discount_bearer' => $promoQuote?->bearer->value,
                    'ppn_amount' => $fare['tax']->toDecimal(),
                    'total_price' => $total->toDecimal(),
                    'payment_method' => $paymentMethod,
                    'status' => $paymentMethod === 'cod'
                        ? BookingStatus::Confirmed->value
                        : BookingStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'expires_at' => $expiresAt,
                    'confirmed_at' => $paymentMethod === 'cod' ? now() : null,
                ]);
                $seatHolders = [];
                foreach ($passengers as $passenger) {
                    $seatHolders[] = $booking->passengers()->create([
                        'schedule_id' => $lockedSchedule->getKey(),
                        'name' => $passenger['name'],
                        'phone' => $passenger['phone'],
                        'seat_number' => $passenger['seat_number'],
                        'seat_snapshot' => $passenger['seat_number'],
                    ]);
                }
                if ($promoQuote !== null) {
                    $this->redeemPromo->execute($promoQuote, $customer, 'travel_booking', (int) $booking->getKey());
                }
                if ($paymentMethod === 'cod') {
                    $agency = $lockedSchedule->business;
                    $this->accountSeeder->ensureBusinessAccounts($agency);
                    $feesPerPassenger = $this->fareCalculator->calculate(
                        LedgerMoney::from((string) $pricing->price),
                        1,
                    );
                    $holdAmount = $feesPerPassenger['service']
                        ->plus($feesPerPassenger['platform'])
                        ->plus($feesPerPassenger['tax'])
                        ->toDecimal();
                    foreach ($seatHolders as $seatHolder) {
                        $this->createHold->execute(
                            'BUSINESS.COLLATERAL:'.$agency->getKey(),
                            'travel_cod_passenger',
                            (int) $seatHolder->getKey(),
                            'cod_collateral',
                            $holdAmount,
                        );
                    }
                }

                return $booking->load('passengers');
            }, attempts: 3);
        } catch (LedgerException $exception) {
            if ($paymentMethod !== 'cod') {
                throw $exception;
            }

            throw new CodCollateralInsufficient(
                'Saldo jaminan tersedia tidak cukup untuk membuat booking COD.',
                previous: $exception,
            );
        } catch (QueryException $exception) {
            $existing = TravelBooking::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertSameRequest($existing, $requestHash);

                return $existing->load('passengers');
            }
            $databaseError = (string) ($exception->errorInfo[2] ?? $exception->getMessage());
            if (str_contains($databaseError, 'booking_passenger_schedule_seat_unique')
                || str_contains($databaseError, 'booking_passengers.schedule_id, booking_passengers.seat_number')) {
                throw new PaymentConflict('Satu atau beberapa kursi sudah dipesan.');
            }

            throw $exception;
        }
    }

    private function assertSameRequest(TravelBooking $booking, string $requestHash): void
    {
        if (! hash_equals($booking->request_hash, $requestHash)) {
            throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pemesanan berbeda.');
        }
    }
}

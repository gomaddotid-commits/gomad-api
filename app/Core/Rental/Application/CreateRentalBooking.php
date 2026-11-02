<?php

namespace App\Core\Rental\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\CreateWalletHold;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Promo\Application\RedeemPromo;
use App\Core\Promo\Application\ResolvePromo;
use App\Core\Rental\Domain\RentalStatus;
use App\Core\Rental\Domain\RentalType;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Rental;
use App\Models\RentalDocument;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CreateRentalBooking
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly RentalFare $fareCalculator,
        private readonly CreateWalletHold $createHold,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly ResolvePromo $resolvePromo,
        private readonly RedeemPromo $redeemPromo,
    ) {}

    public function execute(
        User $customer,
        Business $agency,
        Vehicle $vehicle,
        RentalType $type,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        string $idempotencyKey,
        ?BusinessMember $driver = null,
        ?string $itinerary = null,
        string $paymentMode = 'online',
        ?string $promoCode = null,
    ): Rental {
        if (! in_array($paymentMode, ['online', 'ots'], true)) {
            throw new InvalidArgumentException('Cara pembayaran sewa tidak dikenal.');
        }
        $this->authorizer->authorize($customer, 'booking.create');
        if ($agency->type !== 'agency' || $agency->status !== 'active') {
            throw new InvalidArgumentException('Agency tidak aktif sehingga tidak bisa menerima sewa.');
        }
        if ((int) $vehicle->business_id !== (int) $agency->getKey() || $vehicle->status !== 'active') {
            throw new InvalidArgumentException('Kendaraan tidak tersedia pada agency ini.');
        }
        if ($vehicle->rental_daily_price === null) {
            throw new InvalidArgumentException('Kendaraan ini belum dipasang tarif sewa.');
        }
        if ($type === RentalType::SelfDrive) {
            $verifiedTypes = RentalDocument::query()
                ->where('user_id', $customer->getKey())
                ->whereIn('type', RentalDocument::TYPES)
                ->where('status', 'verified')
                ->pluck('type')
                ->unique()
                ->all();

            if (count(array_intersect(RentalDocument::TYPES, $verifiedTypes)) < count(RentalDocument::TYPES)) {
                throw new InvalidArgumentException(
                    'Sewa lepas kunci membutuhkan KTP, SIM, dan selfie yang sudah diverifikasi admin GoMad.',
                );
            }
        }
        if ($startAt->lt(now()->addHours(2))) {
            throw new InvalidArgumentException('Sewa harus dimulai minimal dua jam dari sekarang.');
        }
        if ($endAt->lte($startAt)) {
            throw new InvalidArgumentException('Waktu selesai sewa harus setelah waktu mulai.');
        }

        $days = max(1, (int) ceil($startAt->diffInMinutes($endAt) / (24 * 60)));
        if ($days > 365) {
            throw new InvalidArgumentException('Masa sewa maksimal 365 hari.');
        }

        $itinerary = $itinerary !== null ? trim($itinerary) : null;

        if ($type === RentalType::Charter && ($itinerary === null || mb_strlen($itinerary) < 5)) {
            throw new InvalidArgumentException('Charter wajib menyebutkan tujuan atau itinerary perjalanan.');
        }

        if (in_array($type, [RentalType::WithDriver, RentalType::Charter], true)) {
            if ($driver === null
                || (int) $driver->business_id !== (int) $agency->getKey()
                || $driver->role !== 'driver'
                || $driver->status !== 'active') {
                throw new InvalidArgumentException('Sewa dengan supir membutuhkan supir aktif dari agency ini.');
            }
        }

        $dailyPrice = LedgerMoney::from((string) $vehicle->rental_daily_price);
        $dailyDriverFee = LedgerMoney::from((string) ($vehicle->rental_driver_daily_fee ?? '0'));
        if ($type === RentalType::SelfDrive) {
            $dailyDriverFee = LedgerMoney::from('0');
        }
        $fare = $this->fareCalculator->calculate($dailyPrice, $dailyDriverFee, $days);

        $deposit = LedgerMoney::from((string) ($vehicle->rental_deposit ?? '0'));
        if ($deposit->toCents() % 100 !== 0) {
            throw new InvalidArgumentException('Deposit sewa harus berupa rupiah bulat.');
        }

        if ($promoCode !== null && trim($promoCode) !== '' && $paymentMode !== 'online') {
            throw new InvalidArgumentException('Kode promo hanya berlaku untuk pembayaran online.');
        }

        $promoBase = $fare['base']->plus($fare['driver']);
        $promoQuote = $this->resolvePromo->execute($promoCode, 'rental', $agency, $customer, $promoBase);
        $discount = $promoQuote?->discount ?? LedgerMoney::from('0');
        $fareTotal = $discount->isPositive() ? $fare['total']->minus($discount) : $fare['total'];
        $totalWithDeposit = $fareTotal->plus($deposit);

        $requestHash = hash('sha256', json_encode([
            'business_id' => $agency->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'customer_id' => $customer->getKey(),
            'driver_id' => $driver?->getKey(),
            'type' => $type->value,
            'itinerary' => $itinerary,
            'start_at' => $startAt->toDateTimeString(),
            'end_at' => $endAt->toDateTimeString(),
            'promo_code' => $promoCode !== null ? strtoupper(trim($promoCode)) : '',
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use (
                $agency,
                $vehicle,
                $customer,
                $driver,
                $type,
                $startAt,
                $endAt,
                $days,
                $fare,
                $deposit,
                $totalWithDeposit,
                $itinerary,
                $paymentMode,
                $idempotencyKey,
                $requestHash,
                $promoQuote,
                $discount,
            ): Rental {
                $existing = Rental::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                        throw new InvalidArgumentException('Kunci idempotensi sudah dipakai untuk permintaan sewa yang berbeda.');
                    }

                    return $existing;
                }

                $this->assertVehicleFree($vehicle, $startAt, $endAt);

                $rental = Rental::query()->create([
                    'code' => 'MT-RN-'.now()->format('ym').'-'.Str::upper(Str::random(8)),
                    'business_id' => $agency->getKey(),
                    'vehicle_id' => $vehicle->getKey(),
                    'customer_id' => $customer->getKey(),
                    'driver_id' => $driver?->getKey(),
                    'rental_type' => $type->value,
                    'payment_mode' => $paymentMode,
                    'itinerary' => $itinerary,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'days' => $days,
                    'price' => $fare['base']->toDecimal(),
                    'driver_fee' => $fare['driver']->toDecimal(),
                    'service_fee' => $fare['service']->toDecimal(),
                    'platform_fee' => $fare['platform']->toDecimal(),
                    'discount_amount' => $discount->toDecimal(),
                    'promo_id' => $promoQuote?->promo->getKey(),
                    'promo_code' => $promoQuote?->promo->code,
                    'discount_bearer' => $promoQuote?->bearer->value,
                    'ppn_amount' => $fare['tax']->toDecimal(),
                    'total_price' => $totalWithDeposit->toDecimal(),
                    'deposit_amount' => $deposit->toDecimal(),
                    'deposit_status' => $deposit->isPositive() ? 'pending' : 'none',
                    'status' => RentalStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                ]);

                if ($promoQuote !== null) {
                    $this->redeemPromo->execute($promoQuote, $customer, 'rental', (int) $rental->getKey());
                }

                if ($paymentMode === 'ots') {
                    // OTS berisiko seperti COD: fee platform ditahan dari jaminan agency
                    // sampai agency mencatat pembayaran tunai pada saat serah terima.
                    $this->accountSeeder->ensureBusinessAccounts($agency);
                    $feeAmount = $fare['service']->plus($fare['platform'])->plus($fare['tax']);
                    $this->createHold->execute(
                        'BUSINESS.COLLATERAL:'.$agency->getKey(),
                        'rental',
                        (int) $rental->getKey(),
                        'ots_deposit',
                        $feeAmount->toDecimal(),
                    );
                }

                return $rental;
            }, attempts: 3);
        } catch (LedgerException $exception) {
            throw new InvalidArgumentException(
                'Saldo jaminan agency tidak cukup untuk sewa bayar di tempat (OTS).',
                previous: $exception,
            );
        } catch (QueryException $exception) {
            $existing = Rental::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * Kendaraan tidak boleh dipakai jadwal travel maupun sewa lain pada rentang waktu yang sama.
     */
    private function assertVehicleFree(Vehicle $vehicle, CarbonInterface $startAt, CarbonInterface $endAt): void
    {
        $conflictingRental = Rental::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('status', [
                RentalStatus::Pending->value,
                RentalStatus::Paid->value,
                RentalStatus::Active->value,
            ])
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->lockForUpdate()
            ->exists();

        if ($conflictingRental) {
            throw new InvalidArgumentException('Kendaraan sudah disewa pada rentang waktu tersebut.');
        }

        $conflictingSchedule = TravelSchedule::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('status', ['published', 'in_progress'])
            ->whereDate('departure_date', '>=', $startAt->toDateString())
            ->whereDate('departure_date', '<=', $endAt->toDateString())
            ->get(['id', 'departure_date', 'departure_time'])
            ->contains(fn (TravelSchedule $schedule): bool => Carbon::parse(
                $schedule->departure_date->format('Y-m-d').' '.$schedule->departure_time,
            )->between($startAt, $endAt));

        if ($conflictingSchedule) {
            throw new InvalidArgumentException('Kendaraan dipakai jadwal travel pada rentang waktu tersebut.');
        }
    }
}

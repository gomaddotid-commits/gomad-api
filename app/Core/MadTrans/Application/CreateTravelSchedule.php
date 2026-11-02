<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\ScheduleStop;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateTravelSchedule
{
    public function __construct(private readonly ActionAuthorizer $authorizer) {}

    public function execute(
        User $owner,
        Business $agency,
        TravelRoute $route,
        Vehicle $vehicle,
        ?BusinessMember $driver,
        Carbon $departureAt,
        string $travelClass,
        string|int $pricePerSeat,
    ): TravelSchedule {
        $this->authorizer->authorize($owner, 'schedule.manage', $agency);
        if ($agency->type !== 'agency'
            || $agency->status !== 'active'
            || (int) $agency->owner_user_id !== (int) $owner->getKey()) {
            throw new AuthorizationException('Hanya pemilik agency aktif dapat menjadwalkan perjalanan.');
        }
        if ((int) $route->business_id !== (int) $agency->getKey()
            || $route->status !== 'active'
            || (int) $vehicle->business_id !== (int) $agency->getKey()
            || $vehicle->status !== 'active') {
            throw new InvalidArgumentException('Rute dan kendaraan harus aktif serta milik agency yang sama.');
        }
        if ($departureAt->isPast() || trim($travelClass) === '') {
            throw new InvalidArgumentException('Waktu keberangkatan harus di masa depan dan kelas perjalanan wajib diisi.');
        }
        if ($driver !== null
            && ((int) $driver->business_id !== (int) $agency->getKey()
                || $driver->role !== 'driver'
                || $driver->status !== 'active')) {
            throw new InvalidArgumentException('Pengemudi harus merupakan driver aktif agency ini.');
        }
        $price = LedgerMoney::from($pricePerSeat);
        if (! $price->isPositive() || $price->toCents() % 100 !== 0) {
            throw new InvalidArgumentException('Harga per kursi harus berupa rupiah bulat yang lebih besar dari nol.');
        }

        return DB::transaction(function () use (
            $owner,
            $agency,
            $route,
            $vehicle,
            $driver,
            $departureAt,
            $travelClass,
            $price,
        ): TravelSchedule {
            $schedule = TravelSchedule::query()->create([
                'business_id' => $agency->getKey(),
                'vehicle_id' => $vehicle->getKey(),
                'route_id' => $route->getKey(),
                'driver_id' => $driver?->getKey(),
                'departure_date' => $departureAt->toDateString(),
                'departure_time' => $departureAt->format('H:i:s'),
                'travel_class' => trim($travelClass),
                'price_per_seat' => $price->toDecimal(),
                'status' => 'pending_approval',
                'proposed_by' => $owner->getKey(),
                'allow_cod' => false,
                'payment_methods' => ['midtrans'],
            ]);
            $route->stops()->orderBy('stop_order')->get()->each(
                fn ($stop) => ScheduleStop::query()->create([
                    'schedule_id' => $schedule->getKey(),
                    'route_stop_id' => $stop->getKey(),
                    'stop_order' => $stop->stop_order,
                    'pickup_zone' => $stop->pickup_zone,
                ]),
            );

            return $schedule->load(['business', 'route', 'vehicle', 'stops']);
        }, attempts: 3);
    }
}

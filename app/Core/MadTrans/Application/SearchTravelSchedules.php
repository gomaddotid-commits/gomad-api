<?php

namespace App\Core\MadTrans\Application;

use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Models\TravelSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class SearchTravelSchedules
{
    public function __construct(private readonly SegmentSeatMap $seatMap) {}

    /**
     * @return Collection<int, TravelSchedule>
     */
    public function execute(string $originCity, string $destinationCity, Carbon $departureDate, int $passengers): Collection
    {
        $originCity = trim($originCity);
        $destinationCity = trim($destinationCity);
        if ($originCity === '' || $destinationCity === '' || mb_strtolower($originCity) === mb_strtolower($destinationCity)) {
            throw new \InvalidArgumentException('Kota asal dan tujuan harus berbeda dan wajib diisi.');
        }
        if ($departureDate->copy()->startOfDay()->isBefore(today())) {
            throw new \InvalidArgumentException('Tanggal keberangkatan harus hari ini atau setelahnya.');
        }
        if ($passengers < 1 || $passengers > 10) {
            throw new \InvalidArgumentException('Jumlah penumpang harus antara 1 hingga 10.');
        }

        return TravelSchedule::query()
            ->with([
                'business:id,name,code',
                'route:id,business_id,origin_city,destination_city',
                'vehicle:id,business_id,name,seat_layout,capacity',
                'stops.routeStop:id,route_id,city_name,name',
                'route.pricing' => fn ($pricing) => $pricing->where('is_active', true),
            ])
            ->where('status', ScheduleStatus::Published->value)
            ->whereDate('departure_date', $departureDate->toDateString())
            ->whereHas('business', fn (Builder $business) => $business
                ->where('type', 'agency')
                ->where('status', 'active'))
            ->whereHas('stops.routeStop', fn (Builder $stop) => $stop
                ->whereRaw('LOWER(city_name) = ?', [mb_strtolower($originCity)]))
            ->whereHas('stops.routeStop', fn (Builder $stop) => $stop
                ->whereRaw('LOWER(city_name) = ?', [mb_strtolower($destinationCity)]))
            ->withSum([
                'bookings as reserved_seats' => fn (Builder $bookings) => $bookings
                    ->where(function (Builder $active): void {
                        $active->whereIn('status', ['confirmed', 'paid', 'on_going'])
                            ->orWhere(fn (Builder $pending) => $pending
                                ->where('status', 'pending')
                                ->where('expires_at', '>', now()));
                    }),
            ], 'total_passengers')
            ->orderBy('departure_time')
            ->limit(50)
            ->get()
            ->filter(function (TravelSchedule $schedule) use ($originCity, $destinationCity, $passengers): bool {
                if (Carbon::parse($schedule->departure_date->format('Y-m-d').' '.$schedule->departure_time)->isPast()) {
                    return false;
                }
                $stops = $schedule->stops->sortBy('stop_order')->values();
                $origin = $stops->first(fn ($stop): bool => mb_strtolower($stop->routeStop->city_name) === mb_strtolower($originCity));
                $destination = $stops->first(fn ($stop): bool => mb_strtolower($stop->routeStop->city_name) === mb_strtolower($destinationCity));
                if ($origin === null || $destination === null || $origin->stop_order >= $destination->stop_order) {
                    return false;
                }
                $hasPrice = $schedule->route->pricing->contains(fn ($price): bool => (int) $price->origin_stop_id === (int) $origin->route_stop_id
                    && (int) $price->destination_stop_id === (int) $destination->route_stop_id
                );
                if (! $hasPrice) {
                    return false;
                }

                // Kapasitas dihitung per segmen: kursi yang kosong setelah penumpang
                // turun di titik tengah boleh dijual lagi.
                $available = $this->seatMap->availableSeats(
                    $schedule,
                    (int) $origin->stop_order,
                    (int) $destination->stop_order,
                );
                $schedule->setAttribute('segment_available', $available);

                return $available >= $passengers;
            })
            ->values();
    }
}

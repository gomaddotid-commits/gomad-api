<?php

namespace App\Core\Rental\Application;

use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Core\Rental\Domain\RentalStatus;
use App\Models\Rental;
use App\Models\TravelSchedule;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;

/**
 * Ketersediaan armada per bulan untuk kalender sewa.
 *
 * Satu hari dianggap terpakai bila ada sewa yang berjalan/menunggu pada hari itu
 * atau ada jadwal travel yang berangkat dengan armada yang sama.
 */
final class VehicleAvailability
{
    /**
     * Status sewa yang masih mengunci armada.
     *
     * @var list<string>
     */
    private const BLOCKING_RENTAL = [
        RentalStatus::Pending->value,
        RentalStatus::Paid->value,
        RentalStatus::Active->value,
        RentalStatus::Returned->value,
    ];

    /**
     * Status jadwal travel yang masih memakai armada.
     *
     * @var list<string>
     */
    private const BLOCKING_SCHEDULE = [
        ScheduleStatus::PendingApproval->value,
        ScheduleStatus::Published->value,
        ScheduleStatus::InProgress->value,
        ScheduleStatus::Completed->value,
    ];

    /**
     * @return array{from: Carbon, to: Carbon, days: array<string, list<array{kind: string, label: string}>>}
     */
    public function month(Vehicle $vehicle, Carbon $monthStart): array
    {
        $from = $monthStart->copy()->startOfMonth()->startOfDay();
        $to = $monthStart->copy()->endOfMonth()->endOfDay();

        $days = [];

        $rentals = Rental::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('status', self::BLOCKING_RENTAL)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->orderBy('start_at')
            ->get(['code', 'rental_type', 'status', 'start_at', 'end_at']);

        foreach ($rentals as $rental) {
            $cursor = $rental->start_at->copy()->max($from)->startOfDay();
            $last = $rental->end_at->copy()->min($to)->startOfDay();

            while ($cursor->lessThanOrEqualTo($last)) {
                $days[$cursor->toDateString()][] = [
                    'kind' => 'rental',
                    'label' => $rental->code.' · '.$this->rentalTypeLabel((string) $rental->rental_type),
                ];
                $cursor->addDay();
            }
        }

        $schedules = TravelSchedule::query()
            ->with('route:id,origin_city,destination_city')
            ->where('vehicle_id', $vehicle->getKey())
            ->whereIn('status', self::BLOCKING_SCHEDULE)
            ->whereBetween('departure_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('departure_date')
            ->get(['departure_date', 'departure_time', 'route_id']);

        foreach ($schedules as $schedule) {
            $day = $schedule->departure_date->toDateString();
            $route = $schedule->route;
            $days[$day][] = [
                'kind' => 'schedule',
                'label' => 'Travel '.substr((string) $schedule->departure_time, 0, 5)
                    .($route !== null ? ' '.$route->origin_city.'→'.$route->destination_city : ''),
            ];
        }

        ksort($days);

        return ['from' => $from, 'to' => $to, 'days' => $days];
    }

    private function rentalTypeLabel(string $type): string
    {
        return match ($type) {
            'self_drive' => 'lepas kunci',
            'charter' => 'charter',
            default => 'dengan supir',
        };
    }
}

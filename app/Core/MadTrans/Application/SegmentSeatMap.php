<?php

namespace App\Core\MadTrans\Application;

use App\Core\MadTrans\Domain\BookingStatus;
use App\Models\BookingPassenger;
use App\Models\TravelSchedule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menentukan kursi yang sudah terpakai pada satu jadwal.
 *
 * Sejak penjualan ulang per segmen diizinkan, satu kursi bisa dipakai penumpang
 * berbeda pada segmen yang tidak bertumpang tindih. Pemanggil karena itu wajib
 * menyebut segmen yang diminati agar hasilnya akurat.
 */
final class SegmentSeatMap
{
    /**
     * @return list<string>
     */
    public function takenSeats(TravelSchedule $schedule, ?int $originOrder = null, ?int $destinationOrder = null): array
    {
        $segment = $originOrder !== null && $destinationOrder !== null;

        return BookingPassenger::query()
            ->where('schedule_id', $schedule->getKey())
            ->whereNotNull('seat_number')
            ->whereHas('booking', fn (Builder $booking) => $booking
                ->where(fn (Builder $active) => $active
                    ->whereIn('status', [
                        BookingStatus::Confirmed->value,
                        BookingStatus::Paid->value,
                        BookingStatus::OnGoing->value,
                    ])
                    ->orWhere(fn (Builder $pending) => $pending
                        ->where('status', BookingStatus::Pending->value)
                        ->where('expires_at', '>', now())))
                ->when($segment, fn (Builder $booking) => $booking
                    ->whereHas('originStop', fn (Builder $stop) => $stop
                        ->where('stop_order', '<', (int) $destinationOrder))
                    ->whereHas('destinationStop', fn (Builder $stop) => $stop
                        ->where('stop_order', '>', (int) $originOrder))))
            ->pluck('seat_number')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Jumlah kursi tersedia pada segmen tertentu.
     */
    public function availableSeats(TravelSchedule $schedule, ?int $originOrder = null, ?int $destinationOrder = null): int
    {
        $capacity = (int) ($schedule->vehicle?->capacity ?? 0);

        return max(0, $capacity - count($this->takenSeats($schedule, $originOrder, $destinationOrder)));
    }

    /**
     * Jumlah kursi terpakai terbanyak pada satu segmen, dipakai untuk memeriksa
     * kapasitas tanpa menghukum jadwal yang memakai ulang kursi di segmen lain.
     */
    public function highestOccupancy(TravelSchedule $schedule): int
    {
        $rows = BookingPassenger::query()
            ->join('bookings', 'bookings.id', '=', 'booking_passengers.booking_id')
            ->join('route_stops as origin_stops', 'origin_stops.id', '=', 'bookings.origin_stop_id')
            ->join('route_stops as destination_stops', 'destination_stops.id', '=', 'bookings.destination_stop_id')
            ->where('booking_passengers.schedule_id', $schedule->getKey())
            ->whereNotNull('booking_passengers.seat_number')
            ->where(fn (Builder $active) => $active
                ->whereIn('bookings.status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::Paid->value,
                    BookingStatus::OnGoing->value,
                ])
                ->orWhere(fn (Builder $pending) => $pending
                    ->where('bookings.status', BookingStatus::Pending->value)
                    ->where('bookings.expires_at', '>', now())))
            ->get([
                'booking_passengers.seat_number',
                'origin_stops.stop_order as origin_order',
                'destination_stops.stop_order as destination_order',
            ]);

        if ($rows->isEmpty()) {
            return 0;
        }

        $maxStopOrder = (int) $rows->max('destination_order');
        $highest = 0;

        for ($boundary = 1; $boundary < $maxStopOrder; $boundary++) {
            $seats = $rows
                ->filter(fn ($row): bool => (int) $row->origin_order <= $boundary
                    && (int) $row->destination_order > $boundary)
                ->pluck('seat_number')
                ->unique()
                ->count();

            $highest = max($highest, $seats);
        }

        return $highest;
    }
}

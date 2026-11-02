<?php

namespace App\Http\Resources;

use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Models\BookingPassenger;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detail satu tugas perjalanan: jadwal + semua penumpang yang harus naik.
 *
 * Daftar penumpang adalah inti layar supir. Karena itu tiap penumpang membawa
 * `can_board` / `can_mark_no_show`, dihitung dari aturan yang sama dengan
 * `ConfirmCodPassengerBoarding` / `VoidCodPassengerHold`:
 *
 *   boarding  : jadwal berjalan, pesanan COD, status confirmed/on_going,
 *               punya kursi, belum naik, belum ditandai tidak naik
 *   no-show   : syarat yang sama, hanya saja yang dicek justru belum boarding
 *
 * Penjaga terakhir tetap server: bila jaminan COD tidak ada, endpoint aksi
 * menjawab 422 dan aplikasi menampilkan pesannya apa adanya.
 *
 * @mixin TravelSchedule
 */
class DriverTripResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inProgress = $this->status === ScheduleStatus::InProgress->value;

        $bookings = $this->relationLoaded('bookings') ? $this->bookings : collect();
        $passengers = $bookings->flatMap(fn (TravelBooking $booking) => $booking->passengers);

        return [
            'id' => (int) $this->getKey(),
            'status' => $this->status,
            'business' => $this->whenLoaded('business', fn () => [
                'id' => (int) $this->business?->getKey(),
                'name' => $this->business?->name,
            ]),
            'route' => $this->whenLoaded('route', fn () => [
                'id' => (int) $this->route?->getKey(),
                'origin_city' => $this->route?->origin_city,
                'destination_city' => $this->route?->destination_city,
            ]),
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => (int) $this->vehicle?->getKey(),
                'name' => $this->vehicle?->name,
                'plate_number' => $this->vehicle?->plate_number,
                'capacity' => $this->vehicle?->capacity,
            ]),
            'departure_date' => $this->departure_date?->toDateString(),
            'departure_time' => $this->departure_time,
            'travel_class' => $this->travel_class,
            'price_per_seat' => $this->price_per_seat,
            'allow_cod' => (bool) $this->allow_cod,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'can_start' => $this->status === ScheduleStatus::Published->value,
            'can_finish' => $inProgress,
            'passenger_count' => $passengers->count(),
            'boarded_count' => $passengers->whereNotNull('boarded_at')->count(),
            'bookings' => $bookings->map(fn (TravelBooking $booking): array => [
                'id' => (int) $booking->getKey(),
                'code' => $booking->code,
                'status' => $booking->status,
                'payment_method' => $booking->payment_method,
                'total_price' => $booking->total_price,
                'customer_phone' => $booking->relationLoaded('customer') ? $booking->customer?->phone : null,
                'passengers' => $booking->passengers
                    ->map(fn (BookingPassenger $passenger): array => $this->passengerRow($passenger, $booking, $inProgress))
                    ->values(),
            ])->values(),
        ];
    }

    /**
     * Satu penumpang dalam bahasa yang dibutuhkan layar supir.
     *
     * Tipe parameter ditulis lengkap dan hasilnya di-cast ke `bool` supaya
     * generator kontrak membaca `can_board` sebagai boolean. Tanpa itu Scramble
     * menebaknya sebagai teks, dan klien Dart gagal membaca `true`.
     *
     * @return array<string, mixed>
     */
    private function passengerRow(BookingPassenger $passenger, TravelBooking $booking, bool $inProgress): array
    {
        $codBooking = $booking->payment_method === 'cod';
        $activeBooking = $booking->status === BookingStatus::Confirmed->value
            || $booking->status === BookingStatus::OnGoing->value;
        $hasSeat = $passenger->seat_number !== null;
        $untouched = $passenger->boarded_at === null && $passenger->no_show_at === null;
        $eligible = (bool) ($inProgress && $codBooking && $activeBooking && $hasSeat);
        $canAct = (bool) ($eligible && $untouched);

        return [
            'id' => (int) $passenger->getKey(),
            'name' => (string) $passenger->name,
            'phone' => $passenger->phone,
            'seat' => (string) $passenger->seat_snapshot,
            'boarded_at' => $passenger->boarded_at?->toIso8601String(),
            'no_show_at' => $passenger->no_show_at?->toIso8601String(),
            'can_board' => $canAct,
            'can_mark_no_show' => $canAct,
        ];
    }
}

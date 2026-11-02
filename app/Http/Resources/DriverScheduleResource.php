<?php

namespace App\Http\Resources;

use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Models\TravelSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Satu tugas perjalanan dalam daftar supir.
 *
 * Sama seperti `/mad-trans/driver` di web: supir hanya perlu tahu perjalanan
 * mana yang harus dijalankan, berapa penumpangnya, dan apakah ia boleh
 * menekan "mulai".
 *
 * `can_start` sengaja dikirim supaya aplikasi tidak menebak dari status saja —
 * aturan sebenarnya tetap di `StartTravelSchedule`; tanda ini hanya untuk
 * tampilan, dan server tetap menolak bila syaratnya tidak terpenuhi.
 *
 * @mixin TravelSchedule
 */
class DriverScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
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
            'booking_count' => (int) ($this->bookings_count ?? 0),
            'can_start' => $this->status === ScheduleStatus::Published->value,
            'can_finish' => $this->status === ScheduleStatus::InProgress->value,
        ];
    }
}

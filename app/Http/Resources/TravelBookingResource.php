<?php

namespace App\Http\Resources;

use App\Models\TravelBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TravelBooking
 */
class TravelBookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'schedule_id' => $this->schedule_id,
            'agency' => $this->schedule?->business?->name,
            'route' => [
                'origin' => $this->schedule?->route?->origin_city,
                'destination' => $this->schedule?->route?->destination_city,
            ],
            'departure_date' => $this->schedule?->departure_date?->toDateString(),
            'departure_time' => $this->schedule?->departure_time,
            'travel_class' => $this->schedule?->travel_class,
            'origin_stop_id' => $this->origin_stop_id,
            'destination_stop_id' => $this->destination_stop_id,
            'pickup' => [
                'address' => $this->pickup_address,
                'latitude' => $this->pickup_latitude,
                'longitude' => $this->pickup_longitude,
                'distance_km' => $this->pickup_distance_km,
                'fee' => $this->pickup_fee,
            ],
            'total_passengers' => $this->total_passengers,
            'base_price' => $this->base_price,
            'pickup_fee' => $this->pickup_fee,
            'service_fee' => $this->service_fee,
            'platform_fee' => $this->platform_fee,
            'ppn_amount' => $this->ppn_amount,
            'total_price' => $this->total_price,
            'payment_method' => $this->payment_method,
            'status' => $this->status,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'passengers' => $this->passengers->map(fn ($passenger): array => [
                'name' => $passenger->name,
                'phone' => $passenger->phone,
                'seat_number' => $passenger->seat_snapshot,
                'seat_reserved' => $passenger->seat_number !== null,
                'boarded_at' => $passenger->boarded_at?->toIso8601String(),
                'no_show_at' => $passenger->no_show_at?->toIso8601String(),
            ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

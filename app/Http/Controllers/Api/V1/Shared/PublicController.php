<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Core\MadTrans\Application\SearchTravelSchedules;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\TravelSchedule;
use App\Support\Rupiah;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Endpoint publik (tanpa token) untuk aplikasi dan halaman statis.
 *
 * Aturan yang sama dengan halaman publik web: hanya jadwal terbit milik agency aktif.
 * Pemesanan tetap memerlukan autentikasi — endpoint ini hanya untuk melihat.
 */
class PublicController extends Controller
{
    public function schedules(Request $request, SearchTravelSchedules $search): JsonResponse
    {
        $data = $request->validate([
            'origin_city' => ['required', 'string', 'max:120'],
            'destination_city' => ['required', 'string', 'max:120'],
            'departure_date' => ['required', 'date'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $schedules = $search->execute(
                $data['origin_city'],
                $data['destination_city'],
                Carbon::parse($data['departure_date'])->startOfDay(),
                (int) ($data['passengers'] ?? 1),
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'SEARCH_REJECTED',
                'errors' => (object) [],
            ], 422);
        }

        return response()->json([
            'data' => $schedules->map(fn (TravelSchedule $schedule): array => [
                'id' => $schedule->getKey(),
                'agency' => $schedule->business?->name,
                'origin_city' => $schedule->route?->origin_city,
                'destination_city' => $schedule->route?->destination_city,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_time' => $schedule->departure_time,
                'travel_class' => $schedule->travel_class,
                'vehicle' => $schedule->vehicle?->name,
                'price_per_seat' => (string) $schedule->price_per_seat,
                'price_per_seat_display' => Rupiah::format($schedule->price_per_seat),
                'seats_left' => $schedule->segment_available
                    ?? max(0, (int) $schedule->vehicle?->capacity - (int) ($schedule->reserved_seats ?? 0)),
                'allow_cod' => (bool) $schedule->allow_cod,
            ])->values()->all(),
        ]);
    }

    public function schedule(TravelSchedule $schedule): JsonResponse
    {
        abort_unless(in_array($schedule->status, ['published', 'boarding'], true), 404);
        abort_unless($schedule->business?->status === 'active', 404);

        $schedule->loadMissing([
            'business:id,name',
            'route:id,origin_city,destination_city',
            'route.pricing' => fn ($query) => $query->where('is_active', true),
            'vehicle:id,name,seat_layout,capacity',
            'stops.routeStop',
        ]);

        return response()->json([
            'data' => [
                'id' => $schedule->getKey(),
                'agency' => $schedule->business?->name,
                'origin_city' => $schedule->route?->origin_city,
                'destination_city' => $schedule->route?->destination_city,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_time' => $schedule->departure_time,
                'travel_class' => $schedule->travel_class,
                'allow_cod' => (bool) $schedule->allow_cod,
                'vehicle' => [
                    'name' => $schedule->vehicle?->name,
                    'capacity' => (int) $schedule->vehicle?->capacity,
                    'seats' => is_array($schedule->vehicle?->seat_layout)
                        ? array_values($schedule->vehicle->seat_layout)
                        : [],
                ],
                'stops' => $schedule->stops->sortBy('stop_order')->values()->map(fn ($stop): array => [
                    'stop_order' => (int) $stop->stop_order,
                    'city_name' => $stop->routeStop?->city_name,
                    'name' => $stop->routeStop?->name,
                ])->all(),
                'pricing' => ($schedule->route?->pricing ?? collect())->map(fn ($price): array => [
                    'origin_stop_id' => (int) $price->origin_stop_id,
                    'destination_stop_id' => (int) $price->destination_stop_id,
                    'price' => (string) $price->price,
                ])->values()->all(),
            ],
        ]);
    }

    public function partners(): JsonResponse
    {
        $agencies = Business::query()
            ->where('type', 'agency')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'verified_at']);

        $outlets = Business::query()
            ->whereIn('type', ['warung', 'grosir'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'verified_at']);

        return response()->json([
            'data' => [
                'agencies' => $agencies->map(fn (Business $business): array => [
                    'id' => $business->getKey(),
                    'name' => $business->name,
                    'verified' => $business->verified_at !== null,
                ])->all(),
                'outlets' => $outlets->map(fn (Business $business): array => [
                    'id' => $business->getKey(),
                    'type' => $business->type,
                    'name' => $business->name,
                    'verified' => $business->verified_at !== null,
                ])->all(),
            ],
        ]);
    }
}

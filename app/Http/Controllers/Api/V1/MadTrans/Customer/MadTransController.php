<?php

namespace App\Http\Controllers\Api\V1\MadTrans\Customer;

use App\Core\MadTrans\Application\CancelPendingTravelBooking;
use App\Core\MadTrans\Application\ComputePickupSurcharge;
use App\Core\MadTrans\Application\ConfirmCodPassengerBoarding;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Core\MadTrans\Application\SearchTravelSchedules;
use App\Core\MadTrans\Application\SegmentSeatMap;
use App\Core\MadTrans\Application\TravelBookingCancellationPolicy;
use App\Core\MadTrans\Application\VoidCodPassengerHold;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Core\Payment\Application\InitiateTravelBookingPayment;
use App\Http\Controllers\Controller;
use App\Http\Resources\TravelBookingResource;
use App\Models\BookingPassenger;
use App\Models\BusinessPickupPolicy;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MadTransController extends Controller
{
    public function schedules(Request $request, SearchTravelSchedules $search): JsonResponse
    {
        $data = $request->validate([
            'origin_city' => ['required', 'string', 'max:120'],
            'destination_city' => ['required', 'string', 'max:120'],
            // Klien mobile mengirim ISO 8601 (DateTime Dart), web mengirim Y-m-d.
            'departure_date' => ['required', 'date'],
            'passengers' => ['required', 'integer', 'min:1', 'max:10'],
        ]);
        $schedules = $search->execute(
            $data['origin_city'],
            $data['destination_city'],
            Carbon::parse($data['departure_date'])->startOfDay(),
            (int) $data['passengers'],
        );
        $rows = $schedules->map(function (TravelSchedule $schedule) use ($data): array {
            $origin = $schedule->stops->first(fn ($stop): bool => mb_strtolower($stop->routeStop->city_name) === mb_strtolower($data['origin_city'])
            );
            $destination = $schedule->stops->first(fn ($stop): bool => mb_strtolower($stop->routeStop->city_name) === mb_strtolower($data['destination_city'])
            );
            $fare = $schedule->route->pricing->first(fn ($price): bool => (int) $price->origin_stop_id === (int) $origin->route_stop_id
                && (int) $price->destination_stop_id === (int) $destination->route_stop_id
            );

            return [
                'id' => $schedule->getKey(),
                'agency' => ['id' => $schedule->business->getKey(), 'name' => $schedule->business->name],
                'route' => [
                    'id' => $schedule->route->getKey(),
                    'origin' => $schedule->route->origin_city,
                    'destination' => $schedule->route->destination_city,
                ],
                'departure_date' => $schedule->departure_date->toDateString(),
                'departure_time' => $schedule->departure_time,
                'travel_class' => $schedule->travel_class,
                'price_per_seat' => $fare->price,
                'vehicle' => $schedule->vehicle->name,
                'capacity' => $schedule->vehicle->capacity,
                'reserved_seats' => (int) ($schedule->reserved_seats ?? 0),
                'available_seats' => $schedule->segment_available
                    ?? max(0, $schedule->vehicle->capacity - (int) ($schedule->reserved_seats ?? 0)),
                'origin_stop' => ['id' => $origin->route_stop_id, 'name' => $origin->routeStop->name],
                'destination_stop' => [
                    'id' => $destination->route_stop_id,
                    'name' => $destination->routeStop->name,
                ],
                'allow_cod' => $schedule->allow_cod,
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function storeBooking(Request $request, CreateTravelBooking $create): JsonResponse
    {
        $data = $request->validate([
            'schedule_id' => ['required', 'integer', 'exists:schedules,id'],
            'origin_stop_id' => ['required', 'integer', 'exists:route_stops,id'],
            'destination_stop_id' => ['required', 'integer', 'exists:route_stops,id', 'different:origin_stop_id'],
            'payment_method' => ['required', Rule::in(['midtrans', 'cod'])],
            'passengers' => ['required', 'array', 'min:1', 'max:10'],
            'passengers.*.name' => ['required', 'string', 'min:2', 'max:160'],
            'passengers.*.phone' => ['nullable', 'string', 'max:30'],
            'passengers.*.seat_number' => ['required', 'string', 'max:12', 'distinct'],
            'pickup_point' => ['nullable', 'string', 'max:255'],
            'pickup_address' => ['nullable', 'string', 'max:1000'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }
        $booking = $create->execute(
            $request->user(),
            TravelSchedule::query()->findOrFail($data['schedule_id']),
            (int) $data['origin_stop_id'],
            (int) $data['destination_stop_id'],
            $data['passengers'],
            $data['payment_method'],
            trim($idempotencyKey),
            null,
            $data['pickup_point'] ?? null,
            $data['pickup_address'] ?? null,
        );

        return response()->json([
            'data' => $this->bookingData($booking->load([
                'schedule.business:id,name',
                'schedule.route:id,origin_city,destination_city',
                'passengers',
            ])),
        ], 201);
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = TravelBooking::query()
            ->with(['schedule.business:id,name', 'schedule.route:id,origin_city,destination_city', 'passengers'])
            ->where('customer_id', $request->user()->getKey())
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => TravelBookingResource::collection($bookings->getCollection()),
            'meta' => [
                'page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ],
        ]);
    }

    public function showBooking(Request $request, TravelBooking $booking): TravelBookingResource
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        return new TravelBookingResource($booking->load([
            'schedule.business:id,name',
            'schedule.route:id,origin_city,destination_city',
            'passengers',
        ]));
    }

    /**
     * Pratinjau pembatalan: aplikasi perlu tahu apakah tombolnya boleh muncul dan
     * apa akibatnya **sebelum** memanggil endpoint pembatalan.
     */
    public function cancellation(Request $request, TravelBooking $booking): JsonResponse
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        $booking->loadMissing(['schedule', 'passengers']);
        $outcome = app(TravelBookingCancellationPolicy::class)->decide($booking);

        return response()->json(['data' => $outcome->toArray()]);
    }

    /**
     * Pelacakan perjalanan versi jujur: hanya kejadian yang tercatat.
     *
     * @return array<string, mixed>
     */
    public function tracking(Request $request, TravelBooking $booking): JsonResponse
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        $booking->loadMissing([
            'schedule.vehicle:id,name,plate_number',
            'schedule.driverMembership.user:id,name,phone',
            'passengers',
        ]);

        $schedule = $booking->schedule;
        $passengers = $booking->passengers;

        return response()->json([
            'data' => [
                'booking_code' => $booking->code,
                'booking_status' => $booking->status,
                'schedule_status' => $schedule?->status,
                'departure_date' => $schedule?->departure_date?->toDateString(),
                'departure_time' => $schedule?->departure_time,
                'started_at' => $schedule?->started_at?->toIso8601String(),
                'finished_at' => $schedule?->finished_at?->toIso8601String(),
                'vehicle' => [
                    'name' => $schedule?->vehicle?->name,
                    'plate_number' => $schedule?->vehicle?->plate_number,
                ],
                'driver' => [
                    'name' => $schedule?->driverMembership?->user?->name,
                    'phone' => $schedule?->driverMembership?->user?->phone,
                ],
                'timeline' => [
                    ['label' => 'Pesanan dibuat', 'at' => $booking->created_at?->toIso8601String(), 'done' => true],
                    ['label' => 'Pembayaran diterima', 'at' => $booking->paid_at?->toIso8601String(), 'done' => $booking->paid_at !== null],
                    ['label' => 'Perjalanan dimulai', 'at' => $booking->started_at?->toIso8601String(), 'done' => $booking->started_at !== null],
                    ['label' => 'Perjalanan selesai', 'at' => $booking->completed_at?->toIso8601String(), 'done' => $booking->completed_at !== null],
                ],
                'passengers' => $passengers->map(fn (BookingPassenger $passenger): array => [
                    'id' => $passenger->getKey(),
                    'name' => $passenger->name,
                    'seat' => $passenger->seat_snapshot,
                    'boarded_at' => $passenger->boarded_at?->toIso8601String(),
                    'no_show_at' => $passenger->no_show_at?->toIso8601String(),
                ])->values(),
                'boarded_count' => $passengers->whereNotNull('boarded_at')->count(),
                'passenger_count' => $passengers->count(),
            ],
        ]);
    }

    public function cancelBooking(
        Request $request,
        TravelBooking $booking,
        CancelPendingTravelBooking $cancel,
    ): JsonResponse {
        return response()->json(['data' => $this->bookingData($cancel->execute($request->user(), $booking))]);
    }

    public function boardCodPassenger(
        Request $request,
        TravelBooking $booking,
        BookingPassenger $passenger,
        ConfirmCodPassengerBoarding $confirm,
    ): JsonResponse {
        $confirm->execute($request->user(), $booking, $passenger);

        return response()->json([
            'data' => $this->bookingData(
                $booking->fresh()->load([
                    'schedule.business:id,name',
                    'schedule.route:id,origin_city,destination_city',
                    'passengers',
                ]),
            ),
        ]);
    }

    public function markCodNoShow(
        Request $request,
        TravelBooking $booking,
        BookingPassenger $passenger,
        VoidCodPassengerHold $voidHold,
    ): JsonResponse {
        $voidHold->execute($request->user(), $booking, $passenger);

        return response()->json([
            'data' => $this->bookingData(
                $booking->fresh()->load([
                    'schedule.business:id,name',
                    'schedule.route:id,origin_city,destination_city',
                    'passengers',
                ]),
            ),
        ]);
    }

    public function payBooking(
        Request $request,
        TravelBooking $booking,
        InitiateTravelBookingPayment $initiate,
    ): JsonResponse {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            return response()->json([
                'message' => 'Header Idempotency-Key wajib diisi dengan panjang maksimal 128 karakter.',
                'code' => 'IDEMPOTENCY_KEY_REQUIRED',
                'errors' => (object) [],
            ], 400);
        }
        $payment = $initiate->execute($request->user(), $booking, trim($idempotencyKey));

        return response()->json([
            'data' => [
                'payment_code' => $payment->code,
                'booking_code' => $booking->code,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'provider' => 'midtrans_snap',
                'snap_token' => $payment->snap_token,
                'client_key' => config('services.midtrans.client_key'),
                'redirect_url' => $payment->redirect_url,
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function seats(
        Request $request,
        TravelSchedule $schedule,
        SegmentSeatMap $seatMap,
    ): JsonResponse {
        $schedule->loadMissing(['vehicle:id,name,seat_layout,capacity,has_driver_seat', 'stops']);
        $layout = is_array($schedule->vehicle?->seat_layout) ? array_values($schedule->vehicle->seat_layout) : [];
        $data = $request->validate([
            'origin_stop_id' => ['nullable', 'integer'],
            'destination_stop_id' => ['nullable', 'integer'],
        ]);
        $orders = $this->segmentOrders(
            $schedule,
            isset($data['origin_stop_id']) ? (int) $data['origin_stop_id'] : null,
            isset($data['destination_stop_id']) ? (int) $data['destination_stop_id'] : null,
        );
        $taken = $seatMap->takenSeats($schedule, $orders[0], $orders[1]);

        return response()->json(['data' => [
            'schedule_id' => $schedule->getKey(),
            'layout' => $layout,
            'has_driver_seat' => (bool) $schedule->vehicle?->has_driver_seat,
            'taken' => $taken,
            'available' => array_values(array_diff($layout, $taken)),
        ]]);
    }

    /**
     * Estimasi biaya jemput di luar titik kumpul (dipakai aplikasi sebelum bayar).
     */
    public function pickupQuote(
        Request $request,
        TravelSchedule $schedule,
        ComputePickupSurcharge $surcharge,
    ): JsonResponse {
        $data = $request->validate([
            'origin_stop_id' => ['required', 'integer'],
            'pickup_point' => ['nullable', 'string', 'max:255'],
        ]);

        $schedule->loadMissing('stops.routeStop');
        $origin = $schedule->stops->firstWhere('route_stop_id', (int) $data['origin_stop_id']);
        $point = null;

        if (! empty($data['pickup_point'])) {
            try {
                $point = GeoPoint::fromInput($data['pickup_point']);
            } catch (\InvalidArgumentException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'INVALID_PICKUP_POINT',
                    'errors' => (object) [],
                ], 422);
            }
        }

        $result = $surcharge->execute(
            BusinessPickupPolicy::query()->where('business_id', $schedule->business_id)->first(),
            $origin?->routeStop?->point(),
            $point,
        );

        return response()->json(['data' => [
            'distance_km' => $result->distanceKm,
            'free_radius_km' => $result->freeRadiusKm,
            'billable_km' => $result->billableKm,
            'fee' => $result->fee,
            'explanation' => $result->explanation(),
        ]]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function segmentOrders(TravelSchedule $schedule, ?int $originStopId, ?int $destinationStopId): array
    {
        if ($originStopId === null || $destinationStopId === null) {
            return [null, null];
        }

        $origin = $schedule->stops->firstWhere('route_stop_id', $originStopId);
        $destination = $schedule->stops->firstWhere('route_stop_id', $destinationStopId);

        if ($origin === null || $destination === null) {
            return [null, null];
        }

        return [(int) $origin->stop_order, (int) $destination->stop_order];
    }

    /**
     * Mengembalikan resource (bukan array) agar Scramble dapat menyimpulkan
     * bentuk respons dari `TravelBookingResource` — klien Dart butuh tipe konkret.
     */
    private function bookingData(TravelBooking $booking): TravelBookingResource
    {
        return new TravelBookingResource($booking);
    }
}

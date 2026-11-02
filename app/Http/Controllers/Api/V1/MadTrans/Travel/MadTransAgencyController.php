<?php

namespace App\Http\Controllers\Api\V1\MadTrans\Travel;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\BusinessWalletSummary;
use App\Core\MadTrans\Application\ApproveTravelSchedule;
use App\Core\MadTrans\Application\CreateAgencyVehicle;
use App\Core\MadTrans\Application\CreateTravelRoute;
use App\Core\MadTrans\Application\CreateTravelSchedule;
use App\Core\MadTrans\Application\EnableTravelScheduleCod;
use App\Core\MadTrans\Application\FinishTravelSchedule;
use App\Core\MadTrans\Application\StartTravelSchedule;
use App\Core\MadTrans\Domain\SeatLayout;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\LedgerAccount;
use App\Models\Rental;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\Vehicle;
use App\Models\Withdrawal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MadTransAgencyController extends Controller
{
    public function routes(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
        abort_unless($business->type === 'agency', 404);

        return response()->json([
            'data' => $business->travelRoutes()
                ->with(['stops', 'pricing'])
                ->orderByDesc('id')
                ->paginate(30)
                ->through(fn (TravelRoute $route): array => [
                    'id' => $route->getKey(),
                    'origin_city' => $route->origin_city,
                    'destination_city' => $route->destination_city,
                    'status' => $route->status,
                    'stops' => $route->stops->map(fn ($stop): array => [
                        'id' => $stop->getKey(),
                        'stop_order' => $stop->stop_order,
                        'city_name' => $stop->city_name,
                        'name' => $stop->name,
                    ])->values(),
                    'pricing' => $route->pricing->map(fn ($price): array => [
                        'origin_stop_id' => $price->origin_stop_id,
                        'destination_stop_id' => $price->destination_stop_id,
                        'price' => $price->price,
                        'is_active' => $price->is_active,
                    ])->values(),
                ]),
        ]);
    }

    public function storeRoute(
        Request $request,
        Business $business,
        CreateTravelRoute $create,
    ): JsonResponse {
        $data = $request->validate([
            'stops' => ['required', 'array', 'min:2', 'max:30'],
            'stops.*.city_name' => ['required', 'string', 'max:120'],
            'stops.*.name' => ['required', 'string', 'max:160'],
            'stops.*.pickup_zone' => ['nullable', 'string', 'max:500'],
            'pricing' => ['required', 'array', 'min:1', 'max:100'],
            'pricing.*.origin_order' => ['required', 'integer', 'min:1'],
            'pricing.*.destination_order' => ['required', 'integer', 'min:2'],
            'pricing.*.price' => ['required', 'string', 'regex:/^[1-9][0-9]{0,15}(?:\.00)?$/'],
        ]);
        $route = $create->execute(
            $request->user(),
            $business,
            $data['stops'],
            $data['pricing'],
        );

        return response()->json([
            'data' => [
                'id' => $route->getKey(),
                'origin_city' => $route->origin_city,
                'destination_city' => $route->destination_city,
                'stops' => $route->stops->map(fn ($stop): array => [
                    'id' => $stop->getKey(),
                    'stop_order' => $stop->stop_order,
                    'city_name' => $stop->city_name,
                    'name' => $stop->name,
                ])->values(),
                'pricing' => $route->pricing->map(fn ($price): array => [
                    'origin_stop_id' => $price->origin_stop_id,
                    'destination_stop_id' => $price->destination_stop_id,
                    'price' => $price->price,
                ])->values(),
            ],
        ], 201);
    }

    public function vehicles(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
        abort_unless($business->type === 'agency', 404);

        return response()->json([
            'data' => $business->vehicles()->orderBy('name')->get()
                ->map(fn (Vehicle $vehicle): array => [
                    'id' => $vehicle->getKey(),
                    'name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'capacity' => $vehicle->capacity,
                    'seat_layout' => $vehicle->seat_layout,
                    'status' => $vehicle->status,
                ])->values(),
        ]);
    }

    public function storeVehicle(
        Request $request,
        Business $business,
        CreateAgencyVehicle $create,
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'plate_number' => ['required', 'string', 'max:30', Rule::unique('vehicles', 'plate_number')->where('business_id', $business->getKey())],
            // Formasi per baris (mis. [2,3,3]) atau daftar kode kursi siap pakai.
            'seat_rows' => ['required_without:seat_layout', 'array', 'min:1', 'max:'.SeatLayout::MAX_ROWS],
            'seat_rows.*' => ['required', 'integer', 'min:0', 'max:'.SeatLayout::MAX_SEATS_PER_ROW],
            'seat_layout' => ['required_without:seat_rows', 'array', 'min:1', 'max:50'],
            'seat_layout.*' => ['required', 'string', 'max:12', 'distinct'],
            'has_driver_seat' => ['nullable', 'boolean'],
        ]);

        if (isset($data['seat_rows'])) {
            try {
                $seats = SeatLayout::fromRows($data['seat_rows']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['seat_rows' => $exception->getMessage()]);
            }
        } else {
            $seats = $data['seat_layout'];
        }

        $vehicle = $create->execute(
            $request->user(),
            $business,
            $data['name'],
            $data['plate_number'],
            $seats,
            (bool) ($data['has_driver_seat'] ?? false),
        );

        return response()->json(['data' => [
            'id' => $vehicle->getKey(),
            'name' => $vehicle->name,
            'plate_number' => $vehicle->plate_number,
            'capacity' => $vehicle->capacity,
            'seat_layout' => $vehicle->seat_layout,
            'status' => $vehicle->status,
        ]], 201);
    }

    public function schedules(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
        abort_unless($business->type === 'agency', 404);
        $schedules = $business->travelSchedules()
            ->with(['route:id,origin_city,destination_city', 'vehicle:id,name,plate_number', 'driverMembership.user:id,name'])
            ->latest('departure_date')
            ->paginate(30);

        return response()->json([
            'data' => $schedules->through(fn (TravelSchedule $schedule): array => [
                'id' => $schedule->getKey(),
                'route' => $schedule->route->origin_city.' - '.$schedule->route->destination_city,
                'vehicle' => $schedule->vehicle->name,
                'driver' => $schedule->driverMembership?->user?->name,
                'departure_date' => $schedule->departure_date->toDateString(),
                'departure_time' => $schedule->departure_time,
                'price_per_seat' => $schedule->price_per_seat,
                'status' => $schedule->status,
                'allow_cod' => $schedule->allow_cod,
                'risk_status' => $schedule->risk_status,
            ]),
        ]);
    }

    public function storeSchedule(
        Request $request,
        Business $business,
        CreateTravelSchedule $create,
    ): JsonResponse {
        $data = $request->validate([
            'route_id' => ['required', 'integer', 'exists:routes,id'],
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_membership_id' => ['nullable', 'integer', 'exists:business_members,id'],
            'departure_at' => ['required', 'date', 'after:now'],
            'travel_class' => ['required', 'string', 'max:40'],
            'price_per_seat' => ['required', 'string', 'regex:/^[1-9][0-9]{0,15}(?:\.00)?$/'],
        ]);
        $schedule = $create->execute(
            $request->user(),
            $business,
            TravelRoute::query()->findOrFail($data['route_id']),
            Vehicle::query()->findOrFail($data['vehicle_id']),
            isset($data['driver_membership_id'])
                ? BusinessMember::query()->findOrFail($data['driver_membership_id'])
                : null,
            Carbon::parse($data['departure_at']),
            $data['travel_class'],
            $data['price_per_seat'],
        );

        return response()->json(['data' => [
            'id' => $schedule->getKey(),
            'status' => $schedule->status,
            'departure_date' => $schedule->departure_date->toDateString(),
            'departure_time' => $schedule->departure_time,
            'route_id' => $schedule->route_id,
            'vehicle_id' => $schedule->vehicle_id,
            'price_per_seat' => $schedule->price_per_seat,
        ]], 201);
    }

    public function approveSchedule(
        Request $request,
        TravelSchedule $schedule,
        ApproveTravelSchedule $approve,
    ): JsonResponse {
        $schedule = $approve->execute($request->user(), $schedule);

        return response()->json(['data' => [
            'id' => $schedule->getKey(),
            'status' => $schedule->status,
            'approved_by' => $schedule->approved_by,
        ]]);
    }

    public function enableCod(
        Request $request,
        TravelSchedule $schedule,
        EnableTravelScheduleCod $enableCod,
    ): JsonResponse {
        $schedule = $enableCod->execute($request->user(), $schedule);

        return response()->json(['data' => [
            'id' => $schedule->getKey(),
            'allow_cod' => $schedule->allow_cod,
            'risk_status' => $schedule->risk_status,
            'payment_methods' => $schedule->payment_methods,
        ]]);
    }

    public function startSchedule(
        Request $request,
        TravelSchedule $schedule,
        StartTravelSchedule $start,
    ): JsonResponse {
        $schedule = $start->execute($request->user(), $schedule);

        return response()->json(['data' => [
            'id' => $schedule->getKey(),
            'status' => $schedule->status,
            'started_at' => $schedule->started_at?->toIso8601String(),
        ]]);
    }

    public function finishSchedule(
        Request $request,
        TravelSchedule $schedule,
        FinishTravelSchedule $finish,
    ): JsonResponse {
        $schedule = $finish->execute($request->user(), $schedule);

        return response()->json(['data' => [
            'id' => $schedule->getKey(),
            'status' => $schedule->status,
            'finished_at' => $schedule->finished_at?->toIso8601String(),
        ]]);
    }

    /**
     * Dompet agency: tiga saldo, mutasi terakhir, dan dana yang sedang ditahan.
     * Angkanya berasal dari BusinessWalletSummary yang sama dengan portal web.
     *
     * @return array<string, mixed>
     */
    public function wallet(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        BusinessWalletSummary $summary,
    ): JsonResponse {
        $this->guardAgency($request, $business, $authorizer);

        $balances = $summary->balances($business);
        $accounts = LedgerAccount::query()
            ->whereIn('code', $summary->accountCodes($business))
            ->pluck('code', 'id');
        $holds = $summary->activeHolds($business);

        return response()->json([
            'data' => [
                'balances' => [
                    'available' => $balances['available'],
                    'collateral' => $balances['collateral'],
                    'payable' => $balances['payable'],
                ],
                'holds_total' => number_format((float) $holds->sum(fn ($hold): float => (float) $hold->amount), 2, '.', ''),
                'holds' => $holds->map(fn ($hold): array => [
                    'id' => (int) $hold->id,
                    'amount' => (string) $hold->amount,
                    'reason' => $hold->reason,
                    'status' => $hold->status,
                    'expires_at' => $hold->expires_at,
                ])->values(),
                'mutations' => $summary->mutations($business)->map(fn ($entry): array => [
                    'id' => (int) $entry->getKey(),
                    'account_code' => $accounts[$entry->account_id] ?? null,
                    'direction' => $entry->direction,
                    'amount' => (string) $entry->amount,
                    'balance_after' => (string) $entry->balance_after,
                    'category' => $entry->transaction?->category,
                    'description' => $entry->transaction?->description,
                    'occurred_at' => $entry->transaction?->occurred_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Pesanan yang masuk ke jadwal agency ini, opsional disaring per status.
     *
     * @return array<string, mixed>
     */
    public function bookings(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->guardAgency($request, $business, $authorizer);

        $status = $request->string('status')->toString();
        $allowed = ['pending', 'paid', 'confirmed', 'on_going', 'completed', 'cancelled'];

        $bookings = TravelBooking::query()
            ->with([
                'schedule:id,route_id,departure_date,departure_time,business_id',
                'schedule.route:id,origin_city,destination_city',
                'passengers',
            ])
            ->whereHas('schedule', fn ($schedule) => $schedule->where('business_id', $business->getKey()))
            ->when(in_array($status, $allowed, true), fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(25);

        return response()->json([
            'data' => $bookings->through(fn (TravelBooking $booking): array => [
                'id' => $booking->getKey(),
                'code' => $booking->code,
                'status' => $booking->status,
                'payment_method' => $booking->payment_method,
                'total_price' => (string) $booking->total_price,
                'departure_date' => $booking->schedule?->departure_date?->toDateString(),
                'departure_time' => $booking->schedule?->departure_time,
                'origin_city' => $booking->schedule?->route?->origin_city,
                'destination_city' => $booking->schedule?->route?->destination_city,
                'passenger_count' => $booking->passengers->count(),
                'passengers' => $booking->passengers->map(fn ($passenger): array => [
                    'id' => $passenger->getKey(),
                    'name' => $passenger->name,
                    'seat' => $passenger->seat_snapshot,
                    'boarded_at' => $passenger->boarded_at?->toIso8601String(),
                ])->values(),
            ]),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    /**
     * Riwayat pencairan milik agency ini. Pengajuan tetap lewat POST /withdrawals.
     *
     * @return array<string, mixed>
     */
    public function withdrawals(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->guardAgency($request, $business, $authorizer);

        $withdrawals = Withdrawal::query()
            ->where('business_id', $business->getKey())
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $withdrawals->through(fn (Withdrawal $withdrawal): array => [
                'id' => $withdrawal->getKey(),
                'code' => $withdrawal->code,
                'status' => $withdrawal->status,
                'amount' => (string) $withdrawal->amount,
                'fee_amount' => (string) $withdrawal->fee_amount,
                'net_amount' => (string) $withdrawal->net_amount,
                'bank_code' => $withdrawal->bank_code,
                'bank_account_masked' => '····'.substr((string) $withdrawal->bank_account_number, -4),
                'requested_at' => $withdrawal->created_at?->toIso8601String(),
                'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $withdrawals->currentPage(),
                'last_page' => $withdrawals->lastPage(),
                'total' => $withdrawals->total(),
            ],
        ]);
    }

    /**
     * Pesanan sewa kendaraan agency ini (bukan pesanan sewa milik pelanggan).
     *
     * @return array<string, mixed>
     */
    public function rentalBookings(Request $request, Business $business, ActionAuthorizer $authorizer): JsonResponse
    {
        $this->guardAgency($request, $business, $authorizer);

        $rentals = Rental::query()
            ->with(['vehicle:id,name,plate_number', 'customer:id,name'])
            ->where('business_id', $business->getKey())
            ->latest()
            ->paginate(20);

        return response()->json([
            'data' => $rentals->through(fn (Rental $rental): array => [
                'id' => $rental->getKey(),
                'code' => $rental->code,
                'status' => $rental->status,
                'with_driver' => (bool) $rental->with_driver,
                'start_at' => $rental->start_at?->toIso8601String(),
                'end_at' => $rental->end_at?->toIso8601String(),
                'price' => (string) $rental->price,
                'deposit_amount' => (string) $rental->deposit_amount,
                'deposit_status' => $rental->deposit_status,
                'vehicle' => [
                    'id' => $rental->vehicle?->getKey(),
                    'name' => $rental->vehicle?->name,
                    'plate_number' => $rental->vehicle?->plate_number,
                ],
                'customer' => [
                    'id' => $rental->customer?->getKey(),
                    'name' => $rental->customer?->name,
                ],
            ]),
            'meta' => [
                'current_page' => $rentals->currentPage(),
                'last_page' => $rentals->lastPage(),
                'total' => $rentals->total(),
            ],
        ]);
    }

    private function guardAgency(Request $request, Business $business, ActionAuthorizer $authorizer): void
    {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
    }
}

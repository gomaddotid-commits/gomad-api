<?php

namespace App\Http\Controllers\Web\MadTrans\Customer;

use App\Core\MadTrans\Application\CancelPendingTravelBooking;
use App\Core\MadTrans\Application\ComputePickupSurcharge;
use App\Core\MadTrans\Application\CreateTravelBooking;
use App\Core\MadTrans\Application\SearchTravelSchedules;
use App\Core\MadTrans\Application\SegmentSeatMap;
use App\Core\MadTrans\Application\TravelBookingCancellationPolicy;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\CodCollateralInsufficient;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Core\Payment\Application\InitiateTravelBookingPayment;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\BusinessPickupPolicy;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use App\Support\Rupiah;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MadTransController extends Controller
{
    public function index(Request $request, SearchTravelSchedules $search): View
    {
        $filters = $request->validate([
            'origin_city' => ['nullable', 'string', 'max:120'],
            'destination_city' => ['nullable', 'string', 'max:120'],
            'departure_date' => ['nullable', 'date_format:Y-m-d'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $schedules = collect();
        $error = null;
        $hasQuery = ! empty($filters['origin_city'])
            && ! empty($filters['destination_city'])
            && ! empty($filters['departure_date']);

        if ($hasQuery) {
            try {
                $schedules = $search->execute(
                    $filters['origin_city'],
                    $filters['destination_city'],
                    Carbon::createFromFormat('Y-m-d', $filters['departure_date']),
                    (int) ($filters['passengers'] ?? 1),
                );
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('madtrans.customer.index', [
            'schedules' => $schedules,
            'filters' => $filters,
            'error' => $error,
            'hasQuery' => $hasQuery,
        ]);
    }

    public function show(TravelSchedule $schedule, SegmentSeatMap $seatMap): View
    {
        $schedule->loadMissing([
            'business:id,name',
            'route:id,origin_city,destination_city',
            'route.pricing' => fn ($pricing) => $pricing->where('is_active', true),
            'vehicle:id,name,seat_layout,capacity,has_driver_seat',
            'stops.routeStop',
        ]);

        $layout = is_array($schedule->vehicle?->seat_layout) ? array_values($schedule->vehicle->seat_layout) : [];
        $stops = $schedule->stops->sortBy('stop_order')->values();
        $taken = $seatMap->takenSeats(
            $schedule,
            (int) $stops->first()?->stop_order,
            (int) $stops->last()?->stop_order,
        );

        return view('madtrans.customer.show', [
            'schedule' => $schedule,
            'stops' => $schedule->stops,
            'vehicle' => $schedule->vehicle,
            'layout' => $layout,
            'taken' => array_flip($taken),
            'bookingToken' => (string) Str::uuid(),
            'segmentPrices' => $schedule->route->pricing->mapWithKeys(
                fn ($price): array => [
                    $price->origin_stop_id.'-'.$price->destination_stop_id => (string) $price->price,
                ],
            )->all(),
        ]);
    }

    public function seats(Request $request, TravelSchedule $schedule, SegmentSeatMap $seatMap): JsonResponse
    {
        $data = $request->validate([
            'origin_stop_id' => ['required', 'integer'],
            'destination_stop_id' => ['required', 'integer'],
        ]);

        $schedule->loadMissing('stops');

        $origin = $schedule->stops->firstWhere('route_stop_id', (int) $data['origin_stop_id']);
        $destination = $schedule->stops->firstWhere('route_stop_id', (int) $data['destination_stop_id']);

        if ($origin === null || $destination === null) {
            return response()->json(['taken' => []]);
        }

        return response()->json([
            'taken' => $seatMap->takenSeats($schedule, (int) $origin->stop_order, (int) $destination->stop_order),
        ]);
    }

    public function store(
        Request $request,
        TravelSchedule $schedule,
        CreateTravelBooking $create,
    ): RedirectResponse {
        $data = $request->validate([
            'origin_stop_id' => ['required', 'integer'],
            'destination_stop_id' => ['required', 'integer', 'different:origin_stop_id'],
            'payment_method' => ['required', 'in:midtrans,cod'],
            'booking_token' => ['required', 'uuid'],
            'seats' => ['required', 'array', 'min:1', 'max:10'],
            'seats.*.seat_number' => ['required', 'string', 'max:12', 'distinct'],
            'seats.*.name' => ['required', 'string', 'min:2', 'max:160'],
            'seats.*.phone' => ['nullable', 'string', 'max:30'],
            'promo_code' => ['nullable', 'string', 'max:40'],
            // Titik jemput di luar titik kumpul (opsional).
            'pickup_point' => ['nullable', 'string', 'max:255'],
            'pickup_address' => ['nullable', 'string', 'max:1000'],
        ]);

        $passengers = array_map(static fn (array $seat): array => [
            'name' => $seat['name'],
            'phone' => $seat['phone'] ?? null,
            'seat_number' => $seat['seat_number'],
        ], $data['seats']);

        try {
            $booking = $create->execute(
                $request->user(),
                $schedule,
                (int) $data['origin_stop_id'],
                (int) $data['destination_stop_id'],
                $passengers,
                $data['payment_method'],
                'web-'.$data['booking_token'],
                $data['promo_code'] ?? null,
                $data['pickup_point'] ?? null,
                $data['pickup_address'] ?? null,
            );
        } catch (\InvalidArgumentException|PaymentConflict|CodCollateralInsufficient $exception) {
            return back()->withErrors(['promo_code' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('mad-trans.bookings.show', ['booking' => $booking->getKey()]);
    }

    /**
     * Estimasi biaya jemput sebelum pelanggan membayar.
     *
     * Angka selalu dihitung server dari kebijakan radius milik agency; klien hanya
     * menampilkan. Dipakai halaman pilih kursi secara langsung (fetch).
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
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        $result = $surcharge->execute(
            BusinessPickupPolicy::query()->where('business_id', $schedule->business_id)->first(),
            $origin?->routeStop?->point(),
            $point,
        );

        return response()->json([
            'data' => [
                'distance_km' => $result->distanceKm,
                'free_radius_km' => $result->freeRadiusKm,
                'billable_km' => $result->billableKm,
                'fee' => $result->fee,
                'fee_formatted' => Rupiah::format($result->fee),
                'explanation' => $result->explanation(),
            ],
        ]);
    }

    public function pay(
        Request $request,
        TravelBooking $booking,
        InitiateTravelBookingPayment $initiate,
    ): RedirectResponse {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 403);
        abort_unless($booking->status === BookingStatus::Pending->value, 409);

        try {
            $payment = $initiate->execute($request->user(), $booking, 'web-travel-pay-'.Str::uuid());
        } catch (\InvalidArgumentException|PaymentConflict|PaymentGatewayException|AuthorizationException $exception) {
            return back()->withErrors(['payment' => $exception->getMessage()]);
        }

        $redirectUrl = $payment->redirect_url;
        if (! is_string($redirectUrl) || $redirectUrl === '') {
            return back()->with('status', 'Pembayaran '.$booking->code.' sedang dibuat.');
        }

        return redirect()->away($redirectUrl);
    }

    public function bookings(Request $request): View
    {
        $bookings = TravelBooking::query()
            ->with([
                'schedule.business:id,name',
                'schedule.route:id,origin_city,destination_city',
                'passengers',
            ])
            ->where('customer_id', $request->user()->getKey())
            ->latest()
            ->paginate(20);

        return view('madtrans.customer.bookings', ['bookings' => $bookings]);
    }

    public function booking(Request $request, TravelBooking $booking): View
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        $booking->load([
            'schedule.business:id,name',
            'schedule.route:id,origin_city,destination_city',
            'schedule.vehicle:id,name',
            'originStop:id,name,city_name',
            'destinationStop:id,name,city_name',
            'passengers',
        ]);

        return view('madtrans.customer.booking', [
            'booking' => $booking,
            'cancellation' => app(TravelBookingCancellationPolicy::class)->decide($booking),
        ]);
    }

    /**
     * Layar konfirmasi pembatalan. Menampilkan akibatnya **sebelum** tombol ditekan:
     * apa yang dilepas, dan apakah ada uang yang sudah ditarik.
     */
    public function cancelForm(Request $request, TravelBooking $booking): View
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        $booking->load([
            'schedule.business:id,name',
            'schedule.route:id,origin_city,destination_city',
            'passengers',
        ]);

        return view('madtrans.customer.cancel', [
            'booking' => $booking,
            'cancellation' => app(TravelBookingCancellationPolicy::class)->decide($booking),
        ]);
    }

    public function cancel(
        Request $request,
        TravelBooking $booking,
        CancelPendingTravelBooking $cancel,
        TravelBookingCancellationPolicy $policy,
    ): RedirectResponse {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        // Pelanggaran izin tetap menjadi 403; penolakan aturan bisnis menjadi pesan
        // yang bisa dibaca — keduanya tidak boleh tampil sebagai halaman galat 500.
        try {
            $cancel->execute($request->user(), $booking);
        } catch (PaymentConflict $exception) {
            return redirect()
                ->route('mad-trans.bookings.show', $booking)
                ->with('error', $exception->getMessage());
        }

        $cancellation = $policy->decide($booking->refresh());

        return redirect()
            ->route('mad-trans.bookings.show', $booking)
            ->with('status', $cancellation->releasesCodHold
                ? 'Pemesanan dibatalkan dan jaminan kursi COD telah dilepas.'
                : 'Pemesanan dibatalkan. Kursi dilepas untuk penumpang lain.');
    }
}

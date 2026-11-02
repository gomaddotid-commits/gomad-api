<?php

namespace App\Http\Controllers\Web\MadTrans\Travel;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\MadTrans\Application\ApproveTravelSchedule;
use App\Core\MadTrans\Application\CreateAgencyVehicle;
use App\Core\MadTrans\Application\CreateTravelRoute;
use App\Core\MadTrans\Application\CreateTravelSchedule;
use App\Core\MadTrans\Application\EnableTravelScheduleCod;
use App\Core\MadTrans\Application\FinishTravelSchedule;
use App\Core\MadTrans\Application\SavePickupPolicy;
use App\Core\MadTrans\Application\StartTravelSchedule;
use App\Core\MadTrans\Application\TransferTravelBooking;
use App\Core\MadTrans\Application\UpdateStopPickupPoint;
use App\Core\MadTrans\Domain\CodCollateralInsufficient;
use App\Core\MadTrans\Domain\GeoPoint;
use App\Core\MadTrans\Domain\SeatLayout;
use App\Core\MadTrans\Domain\TravelCheckoutNotConfigured;
use App\Core\Payment\Application\InitiateCollateralTopup;
use App\Core\Payment\Application\RequestWithdrawal;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Rental\Application\CancelRental;
use App\Core\Rental\Application\ConfirmRentalOtsPayment;
use App\Core\Rental\Application\RentalDocumentAccess;
use App\Core\Rental\Application\ReturnRentalDeposit;
use App\Core\Rental\Domain\RentalStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Rental;
use App\Models\RentalDocument;
use App\Models\RouteStop;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class AgencyTravelController extends Controller
{
    public function index(Request $request): View
    {
        $agencies = Business::query()
            ->where('type', 'agency')
            ->where(fn (Builder $query) => $query
                ->where('owner_user_id', $request->user()->getKey())
                ->orWhereHas('members', fn (Builder $members) => $members
                    ->whereKey($request->user()->getKey())
                    ->where('business_members.status', 'active')))
            ->orderBy('name')
            ->get();

        return view('madtrans.travel.index', ['agencies' => $agencies]);
    }

    /**
     * Halaman ruang kerja agency dipindahkan ke AgencyPortalController (satu
     * halaman per pekerjaan). Controller ini tinggal menangani aksi POST.
     */
    public function showSchedule(
        Request $request,
        Business $business,
        TravelSchedule $schedule,
        ActionAuthorizer $authorizer,
    ): View {
        $this->guard($request, $business, $authorizer);
        abort_unless((int) $schedule->business_id === (int) $business->getKey(), 404);

        $schedule->load([
            'route:id,origin_city,destination_city',
            'vehicle:id,name,plate_number,capacity',
            'driverMembership.user:id,name',
        ]);

        $bookings = TravelBooking::query()
            ->with(['passengers', 'originStop:id,name,city_name', 'destinationStop:id,name,city_name'])
            ->where('schedule_id', $schedule->getKey())
            ->latest()
            ->get();

        return view('madtrans.travel.schedule', [
            'business' => $business,
            'schedule' => $schedule,
            'bookings' => $bookings,
            'transferTargets' => TravelSchedule::query()
                ->where('business_id', $business->getKey())
                ->where('route_id', $schedule->route_id)
                ->where('id', '!=', $schedule->getKey())
                ->where('status', 'published')
                ->orderBy('departure_date')
                ->orderBy('departure_time')
                ->get(['id', 'departure_date', 'departure_time']),
        ]);
    }

    public function transferBooking(
        Request $request,
        Business $business,
        TravelBooking $booking,
        TransferTravelBooking $transfer,
    ): RedirectResponse {
        $this->guardBusiness($request, $business);
        abort_unless((int) $booking->schedule->business_id === (int) $business->getKey(), 404);

        $data = $request->validate([
            'to_schedule_id' => [
                'required',
                'integer',
                Rule::exists('schedules', 'id')->where('business_id', $business->getKey()),
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->runAction(fn () => $transfer->execute(
            $request->user(),
            $booking,
            TravelSchedule::query()->findOrFail($data['to_schedule_id']),
            $data['note'] ?? null,
        ));

        return back()->with('status', 'Pesanan '.$booking->code.' dipindahkan ke jadwal tujuan.');
    }

    public function storeSchedule(
        Request $request,
        Business $business,
        CreateTravelSchedule $create,
    ): RedirectResponse {
        $data = $request->validate([
            'route_id' => ['required', 'integer', Rule::exists('routes', 'id')->where('business_id', $business->getKey())],
            'vehicle_id' => ['required', 'integer', Rule::exists('vehicles', 'id')->where('business_id', $business->getKey())],
            'driver_membership_id' => [
                'nullable',
                'integer',
                Rule::exists('business_members', 'id')->where('business_id', $business->getKey()),
            ],
            'departure_at' => ['required', 'date', 'after:now'],
            'travel_class' => ['required', 'string', 'max:40'],
        ]);

        // Tarif selalu mengikuti tarif rute untuk segmen penuh, supaya angka yang
        // tersimpan sama dengan yang benar-benar ditagihkan ke penumpang.
        $route = TravelRoute::query()->findOrFail($data['route_id']);
        $orderedStops = $route->stops()->orderBy('stop_order')->get();
        $throughPrice = $route->pricing()
            ->where('is_active', true)
            ->where('origin_stop_id', $orderedStops->first()?->getKey())
            ->where('destination_stop_id', $orderedStops->last()?->getKey())
            ->value('price');

        if ($throughPrice === null) {
            throw ValidationException::withMessages([
                'route_id' => 'Rute ini belum memiliki tarif untuk segmen penuh (titik awal ke titik akhir).',
            ]);
        }

        $this->runAction(fn () => $create->execute(
            $request->user(),
            $business,
            $route,
            Vehicle::query()->findOrFail($data['vehicle_id']),
            isset($data['driver_membership_id'])
                ? BusinessMember::query()->findOrFail($data['driver_membership_id'])
                : null,
            Carbon::parse($data['departure_at']),
            $data['travel_class'],
            (string) $throughPrice,
        ));

        return redirect()
            ->route('mad-trans.agency.show', $business)
            ->with('status', 'Jadwal dibuat dan menunggu persetujuan.');
    }

    public function storeVehicle(
        Request $request,
        Business $business,
        CreateAgencyVehicle $create,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'plate_number' => [
                'required',
                'string',
                'max:30',
                Rule::unique('vehicles', 'plate_number')->where('business_id', $business->getKey()),
            ],
            // Formasi kursi dipilih owner per baris (mis. A=2, B=3, C=3 → 2-3-3).
            'seat_rows' => ['required', 'array', 'min:1', 'max:'.SeatLayout::MAX_ROWS],
            'seat_rows.*' => ['required', 'integer', 'min:0', 'max:'.SeatLayout::MAX_SEATS_PER_ROW],
            'has_driver_seat' => ['nullable', 'boolean'],
        ]);

        try {
            $seats = SeatLayout::fromRows($data['seat_rows']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['seat_rows' => $exception->getMessage()]);
        }

        $this->runAction(fn () => $create->execute(
            $request->user(),
            $business,
            $data['name'],
            $data['plate_number'],
            $seats,
            (bool) ($data['has_driver_seat'] ?? false),
        ));

        return redirect()
            ->route('mad-trans.agency.show', $business)
            ->with('status', 'Armada ditambahkan.');
    }

    public function storeRoute(
        Request $request,
        Business $business,
        CreateTravelRoute $create,
    ): RedirectResponse {
        $data = $request->validate([
            'stops' => ['required', 'array', 'min:2', 'max:30'],
            'stops.*.city_name' => ['required', 'string', 'max:120'],
            'stops.*.name' => ['required', 'string', 'max:160'],
            'stops.*.pickup_point' => ['nullable', 'string', 'max:255'],
            'leg_prices' => ['required', 'array', 'min:1', 'max:29'],
            'leg_prices.*' => ['required', 'integer', 'min:1000', 'max:100000000'],
        ]);

        if (count($data['leg_prices']) !== count($data['stops']) - 1) {
            throw ValidationException::withMessages([
                'leg_prices' => 'Jumlah tarif harus sama dengan jumlah titik dikurangi satu.',
            ]);
        }

        $stops = [];

        foreach ($data['stops'] as $stop) {
            $point = null;

            if (! empty($stop['pickup_point'])) {
                try {
                    $point = GeoPoint::fromInput($stop['pickup_point']);
                } catch (InvalidArgumentException $exception) {
                    throw ValidationException::withMessages(['stops' => $exception->getMessage()]);
                }
            }

            $stops[] = [
                'city_name' => $stop['city_name'],
                'name' => $stop['name'],
                'pickup_zone' => null,
                'latitude' => $point?->latitude,
                'longitude' => $point?->longitude,
            ];
        }

        $legPrices = array_map(intval(...), array_values($data['leg_prices']));
        $pricing = [];
        $stopCount = count($stops);

        for ($origin = 1; $origin <= $stopCount; $origin++) {
            $total = 0;

            for ($destination = $origin + 1; $destination <= $stopCount; $destination++) {
                $total += $legPrices[$destination - 2];
                $pricing[] = [
                    'origin_order' => $origin,
                    'destination_order' => $destination,
                    'price' => (string) $total,
                ];
            }
        }

        $this->runAction(fn () => $create->execute($request->user(), $business, $stops, $pricing));

        return redirect()
            ->route('mad-trans.agency.show', $business)
            ->with('status', 'Rute dengan '.$stopCount.' titik ditambahkan.');
    }

    public function topUpCollateral(
        Request $request,
        Business $business,
        InitiateCollateralTopup $initiate,
    ): RedirectResponse {
        abort_unless($business->type === 'agency', 404);

        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:10000', 'max:1000000000'],
        ]);

        try {
            $payment = $initiate->execute(
                $request->user(),
                $business,
                (string) $data['amount'],
                'web-collateral-'.$business->getKey().'-'.$data['amount'].'-'.now()->getTimestamp(),
            );
        } catch (InvalidArgumentException|PaymentConflict|PaymentGatewayException $exception) {
            throw ValidationException::withMessages(['collateral' => $exception->getMessage()]);
        }

        $redirectUrl = $payment->redirect_url;

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            return back()->with('status', 'Permintaan jaminan dibuat. Pembayaran menunggu konfirmasi gateway.');
        }

        return redirect()->away($redirectUrl);
    }

    public function storeRentalRate(Request $request, Business $business, Vehicle $vehicle): RedirectResponse
    {
        $this->guardBusiness($request, $business);

        abort_unless((int) $vehicle->business_id === (int) $business->getKey(), 404);

        $data = $request->validate([
            'rental_daily_price' => ['required', 'integer', 'min:10000', 'max:100000000'],
            'rental_driver_daily_fee' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'rental_deposit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $vehicle->forceFill([
            'rental_daily_price' => (string) $data['rental_daily_price'],
            'rental_driver_daily_fee' => (string) ($data['rental_driver_daily_fee'] ?? 0),
            'rental_deposit' => (string) ($data['rental_deposit'] ?? 0),
        ])->save();

        return back()->with('status', 'Tarif sewa '.$vehicle->name.' disimpan.');
    }

    public function markRentalPaid(
        Request $request,
        Business $business,
        Rental $rental,
        ConfirmRentalOtsPayment $confirmOts,
    ): RedirectResponse {
        $this->guardRental($request, $business, $rental, RentalStatus::Pending);

        if ($rental->payment_mode === 'ots') {
            $this->runAction(fn () => $confirmOts->execute($request->user(), $rental));

            return back()->with('status', 'Pembayaran tunai '.$rental->code.' dicatat; fee platform diambil dari jaminan OTS.');
        }

        $rental->forceFill([
            'status' => RentalStatus::Paid->value,
            'paid_at' => now(),
            'deposit_status' => (float) $rental->deposit_amount > 0 ? 'held' : 'none',
        ])->save();

        return back()->with('status', 'Pembayaran sewa '.$rental->code.' dicatat.');
    }

    public function cancelRental(
        Request $request,
        Business $business,
        Rental $rental,
        CancelRental $cancel,
    ): RedirectResponse {
        $this->guardBusiness($request, $business);
        abort_unless((int) $rental->business_id === (int) $business->getKey(), 404);

        $this->runAction(fn () => $cancel->execute($request->user(), $rental));

        return back()->with('status', 'Sewa '.$rental->code.' dibatalkan.');
    }

    public function startRental(Request $request, Business $business, Rental $rental): RedirectResponse
    {
        $this->guardRental($request, $business, $rental, RentalStatus::Paid);

        $rental->forceFill([
            'status' => RentalStatus::Active->value,
            'started_at' => now(),
            'documents_released_at' => now(),
        ])->save();

        return back()->with('status', 'Kendaraan diserahkan untuk '.$rental->code.'.');
    }

    public function returnRental(Request $request, Business $business, Rental $rental): RedirectResponse
    {
        $this->guardRental($request, $business, $rental, RentalStatus::Active);

        $rental->forceFill([
            'status' => RentalStatus::Returned->value,
            'returned_at' => now(),
        ])->save();

        return back()->with('status', 'Kendaraan '.$rental->code.' sudah dikembalikan.');
    }

    /**
     * Menandai titik kumpul sebuah stop dari tautan peta / koordinat.
     */
    public function updateStopPoint(
        Request $request,
        Business $business,
        RouteStop $stop,
        UpdateStopPickupPoint $update,
    ): RedirectResponse {
        $data = $request->validate([
            'pickup_point' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $update->execute($request->user(), $business, $stop, $data['pickup_point'] ?? null);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['pickup_point' => $exception->getMessage()]);
        }

        return redirect()
            ->route('mad-trans.agency.routes', $business)
            ->with('status', 'Titik kumpul diperbarui.');
    }

    public function savePickupPolicy(
        Request $request,
        Business $business,
        SavePickupPolicy $save,
    ): RedirectResponse {
        $data = $request->validate([
            'free_radius_km' => ['required', 'integer', 'min:0', 'max:100'],
            'fee_per_km' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        try {
            $save->execute($request->user(), $business, (int) $data['free_radius_km'], $data['fee_per_km']);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['free_radius_km' => $exception->getMessage()]);
        }

        return redirect()
            ->route('mad-trans.agency.routes', $business)
            ->with('status', 'Kebijakan radius jemput disimpan.');
    }

    public function completeRental(
        Request $request,
        Business $business,
        Rental $rental,
        ReturnRentalDeposit $returnDeposit,
    ): RedirectResponse {
        $this->guardRental($request, $business, $rental, RentalStatus::Returned);

        $data = $request->validate([
            'deposit_deduction' => ['nullable', 'numeric', 'min:0'],
            'deposit_deduction_reason' => ['nullable', 'string', 'max:200'],
        ]);

        $deposit = (float) $rental->deposit_amount;
        $deduction = round((float) ($data['deposit_deduction'] ?? 0), 2);
        $reason = isset($data['deposit_deduction_reason']) ? trim((string) $data['deposit_deduction_reason']) : null;

        if ($deduction > $deposit) {
            return back()->withErrors([
                'deposit_deduction' => 'Potongan deposit melebihi deposit yang ditahan (Rp '
                    .number_format($deposit, 0, ',', '.').').',
            ]);
        }

        if ($deduction > 0 && ($reason === null || $reason === '')) {
            return back()->withErrors(['deposit_deduction_reason' => 'Alasan potongan deposit wajib diisi.']);
        }

        $rental->forceFill([
            'status' => RentalStatus::Completed->value,
            'completed_at' => now(),
            'documents_revoked_at' => now(),
        ])->save();

        $returnDeposit->execute($rental, $deduction, $reason);

        $fresh = $rental->fresh();
        $refunded = $fresh->deposit_status === 'returned';

        $message = 'Sewa '.$rental->code.' selesai';

        if ($refunded) {
            $message .= (float) $fresh->deposit_deducted_amount > 0
                ? ' dan deposit dikembalikan Rp '
                    .number_format((float) $fresh->deposit_refunded_amount, 0, ',', '.')
                    .' (dipotong Rp '.number_format((float) $fresh->deposit_deducted_amount, 0, ',', '.').').'
                : ' dan deposit dikembalikan.';
        } else {
            $message .= '.';
        }

        return back()->with('status', $message);
    }

    public function rentalDocuments(
        Request $request,
        Business $business,
        Rental $rental,
        RentalDocumentAccess $access,
    ): View {
        $this->guardBusiness($request, $business);
        abort_unless((int) $rental->business_id === (int) $business->getKey(), 404);
        abort_unless($rental->status === RentalStatus::Active->value, 403);

        $documents = RentalDocument::query()
            ->where('user_id', $rental->customer_id)
            ->where('status', 'verified')
            ->get()
            ->filter(fn ($document): bool => $access->canView($request->user(), $document));

        return view('madtrans.rental.documents-agency', [
            'business' => $business,
            'rental' => $rental,
            'documents' => $documents,
        ]);
    }

    private function guardBusiness(Request $request, Business $business): void
    {
        abort_unless($business->type === 'agency', 404);
        app(ActionAuthorizer::class)->authorize($request->user(), 'schedule.manage', $business);
    }

    private function guardRental(Request $request, Business $business, Rental $rental, RentalStatus $expected): void
    {
        $this->guardBusiness($request, $business);

        abort_unless((int) $rental->business_id === (int) $business->getKey(), 404);

        if ($rental->status !== $expected->value) {
            throw ValidationException::withMessages([
                'rental' => 'Status sewa '.$rental->code.' tidak sesuai untuk langkah ini.',
            ]);
        }
    }

    public function storeWithdrawal(
        Request $request,
        Business $business,
        RequestWithdrawal $requestWithdrawal,
    ): RedirectResponse {
        $this->guardBusiness($request, $business);

        $data = $request->validate([
            'bank_code' => ['required', 'string', 'max:30'],
            'bank_account_number' => ['required', 'string', 'max:34'],
            'bank_account_name' => ['required', 'string', 'min:2', 'max:120'],
            'amount' => ['required', 'integer', 'min:100000', 'max:1000000000'],
        ]);

        try {
            $requestWithdrawal->execute(
                $request->user(),
                $business,
                $data['bank_code'],
                $data['bank_account_number'],
                $data['bank_account_name'],
                (string) $data['amount'],
                'web-withdrawal-'.Str::uuid(),
            );
        } catch (InvalidArgumentException|PaymentConflict|LedgerException $exception) {
            throw ValidationException::withMessages(['withdrawal' => $exception->getMessage()]);
        }

        return back()->with('status', 'Permintaan pencairan dikirim untuk ditinjau.');
    }

    public function approve(
        Request $request,
        TravelSchedule $schedule,
        ApproveTravelSchedule $approve,
    ): RedirectResponse {
        $this->runAction(fn () => $approve->execute($request->user(), $schedule));

        return back()->with('status', 'Jadwal disetujui dan sudah tampil untuk penumpang.');
    }

    public function enableCod(
        Request $request,
        TravelSchedule $schedule,
        EnableTravelScheduleCod $enableCod,
    ): RedirectResponse {
        $this->runAction(fn () => $enableCod->execute($request->user(), $schedule));

        return back()->with('status', 'COD diaktifkan untuk jadwal ini.');
    }

    public function start(
        Request $request,
        TravelSchedule $schedule,
        StartTravelSchedule $start,
    ): RedirectResponse {
        $this->runAction(fn () => $start->execute($request->user(), $schedule));

        return back()->with('status', 'Perjalanan dimulai.');
    }

    public function finish(
        Request $request,
        TravelSchedule $schedule,
        FinishTravelSchedule $finish,
    ): RedirectResponse {
        $this->runAction(fn () => $finish->execute($request->user(), $schedule));

        return back()->with('status', 'Perjalanan diselesaikan.');
    }

    private function guard(Request $request, Business $business, ActionAuthorizer $authorizer): void
    {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
    }

    private function runAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException|CodCollateralInsufficient|TravelCheckoutNotConfigured|PaymentConflict $exception) {
            throw ValidationException::withMessages(['agency' => $exception->getMessage()]);
        } catch (AuthorizationException $exception) {
            throw $exception;
        }
    }
}

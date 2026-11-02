<?php

namespace App\Http\Controllers\Web\MadTrans\Customer;

use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Rental\Application\CreateRentalBooking;
use App\Core\Rental\Application\InitiateRentalPayment;
use App\Core\Rental\Domain\RentalCheckoutNotConfigured;
use App\Core\Rental\Domain\RentalType;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessMember;
use App\Models\Rental;
use App\Models\RentalDocument;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class RentalController extends Controller
{
    public function show(Request $request, Business $business): View
    {
        abort_unless($business->type === 'agency', 404);

        return view('madtrans.rental.show', [
            'business' => $business,
            'selfDriveReady' => RentalDocument::query()
                ->where('user_id', $request->user()->getKey())
                ->whereIn('type', RentalDocument::TYPES)
                ->where('status', 'verified')
                ->distinct()
                ->count('type') >= count(RentalDocument::TYPES),
            'vehicles' => $business->vehicles()
                ->where('status', 'active')
                ->whereNotNull('rental_daily_price')
                ->orderBy('name')
                ->get(),
            'drivers' => $business->members()
                ->wherePivot('role', 'driver')
                ->wherePivot('status', 'active')
                ->orderBy('name')
                ->get(),
            'rentals' => Rental::query()
                ->with('vehicle:id,name,plate_number')
                ->where('customer_id', $request->user()->getKey())
                ->latest()
                ->limit(20)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        Business $business,
        CreateRentalBooking $create,
    ): RedirectResponse {
        abort_unless($business->type === 'agency', 404);

        $data = $request->validate([
            'rental_type' => ['nullable', Rule::in(['with_driver', 'charter', 'self_drive'])],
            'payment_mode' => ['nullable', Rule::in(['online', 'ots'])],
            'itinerary' => ['nullable', 'string', 'max:500', 'required_if:rental_type,charter'],
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('business_id', $business->getKey()),
            ],
            'driver_membership_id' => [
                'nullable',
                'required_unless:rental_type,self_drive',
                'integer',
                Rule::exists('business_members', 'id')->where('business_id', $business->getKey()),
            ],
            'start_at' => ['required', 'date', 'after:+2 hours'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'promo_code' => ['nullable', 'string', 'max:40'],
        ]);

        $type = match ($data['rental_type'] ?? 'with_driver') {
            'charter' => RentalType::Charter,
            'self_drive' => RentalType::SelfDrive,
            default => RentalType::WithDriver,
        };

        try {
            $rental = $create->execute(
                $request->user(),
                $business,
                Vehicle::query()->findOrFail($data['vehicle_id']),
                $type,
                Carbon::parse($data['start_at']),
                Carbon::parse($data['end_at']),
                'web-rental-'.Str::uuid(),
                isset($data['driver_membership_id'])
                    ? BusinessMember::query()->findOrFail($data['driver_membership_id'])
                    : null,
                $type === RentalType::Charter ? ($data['itinerary'] ?? null) : null,
                $data['payment_mode'] ?? 'online',
                $data['promo_code'] ?? null,
            );
        } catch (InvalidArgumentException|RentalCheckoutNotConfigured $exception) {
            throw ValidationException::withMessages(['rental' => $exception->getMessage()]);
        }

        return redirect()
            ->route('rental.show', $business)
            ->with('status', ($type === RentalType::Charter ? 'Permintaan charter ' : 'Permintaan sewa ')
                .$rental->code.' dibuat. Total Rp '
                .number_format((float) $rental->total_price, 0, ',', '.')
                .' untuk '.$rental->days.' hari.');
    }

    public function pay(
        Request $request,
        Business $business,
        Rental $rental,
        InitiateRentalPayment $initiate,
    ): RedirectResponse {
        abort_unless($business->type === 'agency', 404);
        abort_unless((int) $rental->business_id === (int) $business->getKey(), 404);

        try {
            $payment = $initiate->execute($request->user(), $rental, 'web-rental-pay-'.Str::uuid());
        } catch (InvalidArgumentException|PaymentConflict|PaymentGatewayException $exception) {
            throw ValidationException::withMessages(['rental' => $exception->getMessage()]);
        }

        $redirectUrl = $payment->redirect_url;

        if (! is_string($redirectUrl) || $redirectUrl === '') {
            return back()->with('status', 'Pembayaran sewa '.$rental->code.' sedang dibuat.');
        }

        return redirect()->away($redirectUrl);
    }
}

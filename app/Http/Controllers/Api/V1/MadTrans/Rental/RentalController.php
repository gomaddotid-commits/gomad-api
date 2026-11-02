<?php

namespace App\Http\Controllers\Api\V1\MadTrans\Rental;

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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Rental untuk klien mobile. Seluruh aturan (tarif, deposit, OTS, dokumen lepas kunci,
 * ketersediaan, promo) dijalankan oleh kelas Core yang sama dengan web — tidak ada
 * logika bisnis yang diduplikasi di sini.
 */
class RentalController extends Controller
{
    public function vehicles(Request $request, Business $business): JsonResponse
    {
        abort_unless($business->type === 'agency', 404);
        abort_unless($business->status === 'active', 404);

        $vehicles = $business->vehicles()
            ->where('status', 'active')
            ->whereNotNull('rental_daily_price')
            ->orderBy('name')
            ->get(['id', 'name', 'plate_number', 'capacity', 'rental_daily_price', 'rental_driver_daily_fee', 'rental_deposit']);

        $selfDriveReady = RentalDocument::query()
            ->where('user_id', $request->user()->getKey())
            ->whereIn('type', RentalDocument::TYPES)
            ->where('status', 'verified')
            ->distinct()
            ->count('type') >= count(RentalDocument::TYPES);

        return response()->json([
            'data' => [
                'business' => ['id' => $business->getKey(), 'name' => $business->name, 'code' => $business->code],
                'self_drive_ready' => $selfDriveReady,
                'vehicles' => $vehicles->map(fn (Vehicle $vehicle): array => [
                    'id' => $vehicle->getKey(),
                    'name' => $vehicle->name,
                    'plate_number' => $vehicle->plate_number,
                    'capacity' => (int) $vehicle->capacity,
                    'daily_price' => (string) $vehicle->rental_daily_price,
                    'driver_daily_fee' => (string) ($vehicle->rental_driver_daily_fee ?? '0.00'),
                    'deposit' => (string) ($vehicle->rental_deposit ?? '0.00'),
                ])->all(),
                'drivers' => BusinessMember::query()
                    ->where('business_id', $business->getKey())
                    ->where('role', 'driver')
                    ->where('status', 'active')
                    ->with('user:id,name')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (BusinessMember $member): array => [
                        'membership_id' => $member->getKey(),
                        'name' => $member->user?->name,
                    ])
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request, Business $business, CreateRentalBooking $create): JsonResponse
    {
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
            'idempotency_key' => ['required', 'string', 'max:120'],
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
                'api-rental-'.$data['idempotency_key'],
                isset($data['driver_membership_id'])
                    ? BusinessMember::query()->findOrFail($data['driver_membership_id'])
                    : null,
                $type === RentalType::Charter ? ($data['itinerary'] ?? null) : null,
                $data['payment_mode'] ?? 'online',
                $data['promo_code'] ?? null,
            );
        } catch (InvalidArgumentException|RentalCheckoutNotConfigured $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'RENTAL_REJECTED',
                'errors' => (object) [],
            ], 422);
        }

        return response()->json(['data' => $this->payload($rental)], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $rentals = Rental::query()
            ->with('vehicle:id,name,plate_number')
            ->where('customer_id', $request->user()->getKey())
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $rentals->map(fn (Rental $rental): array => $this->payload($rental))->all(),
        ]);
    }

    public function show(Request $request, Rental $rental): JsonResponse
    {
        abort_unless((int) $rental->customer_id === (int) $request->user()->getKey(), 404);

        return response()->json(['data' => $this->payload($rental->load('vehicle:id,name,plate_number'))]);
    }

    public function pay(Request $request, Rental $rental, InitiateRentalPayment $initiate): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $payment = $initiate->execute($request->user(), $rental, 'api-rental-pay-'.$data['idempotency_key']);
        } catch (InvalidArgumentException|PaymentConflict|PaymentGatewayException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'RENTAL_PAYMENT_REJECTED',
                'errors' => (object) [],
            ], 422);
        }

        // Status tetap 200 agar bentuk respons bisa diketik di kontrak OpenAPI
        // (klien Dart hasil generasi memakai model, bukan string bebas).
        // Pemanggilan ulang mengembalikan payment intent yang sama (idempoten).
        return response()->json([
            'data' => [
                'payment_code' => $payment->code,
                'rental_code' => $rental->code,
                'amount' => (string) $payment->amount,
                'status' => $payment->status,
                'provider' => 'midtrans_snap',
                'snap_token' => $payment->snap_token,
                'client_key' => config('services.midtrans.client_key'),
                'redirect_url' => $payment->redirect_url,
                'expires_at' => $payment->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Rental $rental): array
    {
        return [
            'id' => $rental->getKey(),
            'code' => $rental->code,
            'status' => $rental->status,
            'rental_type' => $rental->rental_type,
            'payment_mode' => $rental->payment_mode,
            'itinerary' => $rental->itinerary,
            'vehicle' => $rental->relationLoaded('vehicle') && $rental->vehicle !== null
                ? ['id' => $rental->vehicle->getKey(), 'name' => $rental->vehicle->name, 'plate_number' => $rental->vehicle->plate_number]
                : null,
            'start_at' => $rental->start_at?->toIso8601String(),
            'end_at' => $rental->end_at?->toIso8601String(),
            'days' => (int) $rental->days,
            'price' => (string) $rental->price,
            'driver_fee' => (string) $rental->driver_fee,
            'discount_amount' => (string) $rental->discount_amount,
            'promo_code' => $rental->promo_code,
            'deposit_amount' => (string) $rental->deposit_amount,
            'deposit_status' => $rental->deposit_status,
            'total_price' => (string) $rental->total_price,
        ];
    }
}

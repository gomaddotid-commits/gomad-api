<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Payment\Infrastructure\MidtransSnapClient;
use App\Models\PaymentIntent;
use App\Models\TravelBooking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitiateTravelBookingPayment
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly MidtransSnapClient $gateway,
        private readonly ExpireTravelBookingPayment $expirePayment,
    ) {}

    public function execute(User $customer, TravelBooking $booking, string $idempotencyKey): PaymentIntent
    {
        $this->authorizer->authorize($customer, 'booking.create');
        if ((int) $booking->customer_id !== (int) $customer->getKey()) {
            throw new AuthorizationException('Pembayaran hanya dapat dilakukan oleh pemesan.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }

        $requestHash = hash('sha256', json_encode([
            'booking_id' => $booking->getKey(),
            'customer_id' => $customer->getKey(),
            'amount' => (string) $booking->total_price,
        ], JSON_THROW_ON_ERROR));
        $storedKey = 'mt:'.hash('sha256', $booking->getKey().':'.$customer->getKey().':'.$idempotencyKey);

        try {
            $payment = DB::transaction(function () use (
                $booking,
                $customer,
                $requestHash,
                $storedKey,
            ): PaymentIntent {
                $lockedBooking = TravelBooking::query()
                    ->whereKey($booking->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedBooking->status !== BookingStatus::Pending->value) {
                    throw new PaymentConflict('Pemesanan tidak lagi menunggu pembayaran.');
                }
                if ($lockedBooking->payment_intent_id !== null) {
                    $existing = PaymentIntent::query()->findOrFail($lockedBooking->payment_intent_id);
                    if ($existing->idempotency_key !== $storedKey
                        || ! hash_equals($existing->request_hash, $requestHash)) {
                        throw new PaymentConflict('Pemesanan sudah memiliki percobaan pembayaran.');
                    }

                    return $existing;
                }

                $code = 'MT-PY-'.now()->format('ym').'-'.Str::upper(Str::random(8));
                $payment = PaymentIntent::query()->create([
                    'code' => $code,
                    'business_id' => $lockedBooking->schedule->business_id,
                    'booking_id' => $lockedBooking->getKey(),
                    'user_id' => $customer->getKey(),
                    'purpose' => 'travel_booking',
                    'amount' => $lockedBooking->total_price,
                    'currency' => 'IDR',
                    'idempotency_key' => $storedKey,
                    'request_hash' => $requestHash,
                    'provider_order_id' => $code,
                    'status' => 'creating',
                    'expires_at' => now()->addMinutes((int) config('services.midtrans.payment_timeout', 30)),
                ]);
                $lockedBooking->forceFill([
                    'payment_intent_id' => $payment->getKey(),
                    'expires_at' => $payment->expires_at,
                ])->save();
                $payment->wasRecentlyCreated = true;

                return $payment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $payment = PaymentIntent::query()->where('idempotency_key', $storedKey)->first();
            if ($payment === null) {
                throw $exception;
            }
            if (! hash_equals($payment->request_hash, $requestHash)) {
                throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pemesanan berbeda.');
            }
        }

        if ($payment->status === 'pending') {
            return $payment;
        }
        if ($payment->status !== 'creating' || ! $payment->wasRecentlyCreated) {
            throw new PaymentConflict('Percobaan pembayaran tidak dapat dilanjutkan dari status saat ini.');
        }

        try {
            $providerResponse = $this->gateway->createTransaction($payment->load([
                'user',
                'booking.passengers',
                'booking.routePricing',
                'booking.schedule.route',
                'booking.schedule.business',
            ]));
        } catch (PaymentGatewayException $exception) {
            if ($exception->outcomeUnknown) {
                $payment->forceFill(['status' => 'reconciliation_required'])->save();
            } else {
                $payment->forceFill(['status' => 'failed'])->save();
                $this->expirePayment->execute($payment, 'failed');
            }
            throw $exception;
        }

        $createdByRequest = $payment->wasRecentlyCreated;

        return DB::transaction(function () use ($payment, $providerResponse, $createdByRequest): PaymentIntent {
            $locked = PaymentIntent::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'creating') {
                $locked->forceFill([
                    'status' => 'pending',
                    'snap_token' => $providerResponse['token'],
                    'redirect_url' => $providerResponse['redirect_url'],
                ])->save();
            }
            $locked->wasRecentlyCreated = $createdByRequest;

            return $locked;
        }, attempts: 3);
    }
}

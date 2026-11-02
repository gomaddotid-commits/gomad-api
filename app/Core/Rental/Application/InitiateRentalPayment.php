<?php

namespace App\Core\Rental\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Payment\Infrastructure\MidtransSnapClient;
use App\Core\Rental\Domain\RentalStatus;
use App\Models\PaymentIntent;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InitiateRentalPayment
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly MidtransSnapClient $gateway,
    ) {}

    public function execute(User $customer, Rental $rental, string $idempotencyKey): PaymentIntent
    {
        $this->authorizer->authorize($customer, 'booking.create');
        if ((int) $rental->customer_id !== (int) $customer->getKey()) {
            throw new AuthorizationException('Pembayaran hanya dapat dilakukan oleh penyewa.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException('Kunci idempotensi wajib diisi dan maksimal 128 karakter.');
        }

        $requestHash = hash('sha256', json_encode([
            'rental_id' => $rental->getKey(),
            'customer_id' => $customer->getKey(),
            'amount' => (string) $rental->total_price,
        ], JSON_THROW_ON_ERROR));
        $storedKey = 'rn:'.hash('sha256', $rental->getKey().':'.$customer->getKey().':'.$idempotencyKey);

        try {
            $payment = DB::transaction(function () use ($rental, $customer, $requestHash, $storedKey): PaymentIntent {
                $lockedRental = Rental::query()->whereKey($rental->getKey())->lockForUpdate()->firstOrFail();

                if ($lockedRental->status !== RentalStatus::Pending->value) {
                    throw new PaymentConflict('Sewa tidak lagi menunggu pembayaran.');
                }

                if ($lockedRental->payment_intent_id !== null) {
                    $existing = PaymentIntent::query()->findOrFail($lockedRental->payment_intent_id);
                    $reusable = in_array($existing->status, ['creating', 'pending', 'paid'], true);

                    if ($reusable) {
                        if ($existing->idempotency_key !== $storedKey
                            || ! hash_equals((string) $existing->request_hash, $requestHash)) {
                            throw new PaymentConflict('Sewa sudah memiliki percobaan pembayaran yang aktif.');
                        }

                        return $existing;
                    }
                }

                $code = 'MT-PY-'.now()->format('ym').'-'.Str::upper(Str::random(8));
                $payment = PaymentIntent::query()->create([
                    'code' => $code,
                    'business_id' => $lockedRental->business_id,
                    'rental_id' => $lockedRental->getKey(),
                    'user_id' => $customer->getKey(),
                    'purpose' => 'rental',
                    'amount' => $lockedRental->total_price,
                    'currency' => 'IDR',
                    'idempotency_key' => $storedKey,
                    'request_hash' => $requestHash,
                    'provider_order_id' => $code,
                    'status' => 'creating',
                    'expires_at' => now()->addMinutes((int) config('services.midtrans.payment_timeout', 30)),
                ]);
                $lockedRental->forceFill(['payment_intent_id' => $payment->getKey()])->save();
                $payment->wasRecentlyCreated = true;

                return $payment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $payment = PaymentIntent::query()->where('idempotency_key', $storedKey)->first();
            if ($payment === null) {
                throw $exception;
            }
            if (! hash_equals((string) $payment->request_hash, $requestHash)) {
                throw new PaymentConflict('Kunci idempotensi sudah dipakai untuk permintaan berbeda.');
            }
        }

        if ($payment->status === 'pending') {
            return $payment;
        }
        if ($payment->status !== 'creating' || ! $payment->wasRecentlyCreated) {
            throw new PaymentConflict('Percobaan pembayaran tidak dapat dilanjutkan dari status saat ini.');
        }

        try {
            $providerResponse = $this->gateway->createTransaction($payment->load(['user', 'rental.vehicle']));
        } catch (PaymentGatewayException $exception) {
            $payment->forceFill([
                'status' => $exception->outcomeUnknown ? 'reconciliation_required' : 'failed',
            ])->save();

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

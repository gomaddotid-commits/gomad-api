<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Payment\Infrastructure\MidtransSnapClient;
use App\Core\Warung\Application\ExpireWarungOrderPayment;
use App\Core\Warung\Domain\OrderStatus;
use App\Models\Order;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InitiateWarungOrderPayment
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly MidtransSnapClient $gateway,
        private readonly ExpireWarungOrderPayment $expirePayment,
    ) {}

    public function execute(User $user, Order $order, string $idempotencyKey): PaymentIntent
    {
        $this->authorizer->authorize($user, 'order.pay', $order->business);
        if ((int) $order->business->owner_user_id !== (int) $user->getKey()) {
            throw new AuthorizationException('Pembayaran pesanan hanya dapat dilakukan pemilik warung.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }

        $requestHash = hash('sha256', json_encode([
            'order_id' => $order->getKey(),
            'user_id' => $user->getKey(),
            'amount' => (string) $order->total_amount,
        ], JSON_THROW_ON_ERROR));
        $storedKey = 'mw:'.hash('sha256', $order->getKey().':'.$user->getKey().':'.$idempotencyKey);

        try {
            $payment = DB::transaction(function () use ($order, $user, $requestHash, $storedKey): PaymentIntent {
                $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
                if ($lockedOrder->status !== OrderStatus::PendingPayment->value) {
                    throw new PaymentConflict('Pesanan belum disetujui atau tidak lagi menunggu pembayaran.');
                }
                if ($lockedOrder->payment_intent_id !== null) {
                    $existing = PaymentIntent::query()->findOrFail($lockedOrder->payment_intent_id);
                    if ($existing->idempotency_key !== $storedKey || ! hash_equals($existing->request_hash, $requestHash)) {
                        throw new PaymentConflict('Pesanan sudah memiliki percobaan pembayaran; jangan membuat pembayaran ganda.');
                    }

                    return $existing;
                }

                $code = 'MW-PY-'.now()->format('ym').'-'.Str::upper(Str::random(8));
                $payment = PaymentIntent::query()->create([
                    'code' => $code,
                    'business_id' => $lockedOrder->business_id,
                    'order_id' => $lockedOrder->getKey(),
                    'user_id' => $user->getKey(),
                    'purpose' => 'warung_order',
                    'amount' => $lockedOrder->total_amount,
                    'currency' => 'IDR',
                    'idempotency_key' => $storedKey,
                    'request_hash' => $requestHash,
                    'provider_order_id' => $code,
                    'status' => 'creating',
                    'expires_at' => now()->addMinutes((int) config('services.midtrans.payment_timeout', 30)),
                ]);
                $lockedOrder->forceFill([
                    'payment_intent_id' => $payment->getKey(),
                    'expires_at' => $payment->expires_at,
                ])->save();

                $payment->wasRecentlyCreated = true;

                return $payment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $payment = PaymentIntent::query()
                ->where('idempotency_key', $storedKey)
                ->first();
            if ($payment === null) {
                throw $exception;
            }
            if (! hash_equals($payment->request_hash, $requestHash)) {
                throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pembayaran berbeda.');
            }
        }

        if ($payment->status === 'pending') {
            return $payment;
        }
        if ($payment->status !== 'creating') {
            throw new PaymentConflict('Percobaan pembayaran tidak dapat dilanjutkan dari status saat ini.');
        }
        if (! $payment->wasRecentlyCreated) {
            throw new PaymentConflict('Status pembuatan checkout tidak pasti; jangan mengulang permintaan gateway.');
        }
        if ($payment->snap_token !== null && $payment->redirect_url !== null) {
            return $payment;
        }

        try {
            $providerResponse = $this->gateway->createTransaction($payment->load(['user', 'order.items']));
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
            $lockedPayment = PaymentIntent::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedPayment->status === 'creating') {
                $lockedPayment->forceFill([
                    'status' => 'pending',
                    'snap_token' => $providerResponse['token'],
                    'redirect_url' => $providerResponse['redirect_url'],
                ])->save();
            }
            $lockedPayment->wasRecentlyCreated = $createdByRequest;

            return $lockedPayment;
        });
    }
}

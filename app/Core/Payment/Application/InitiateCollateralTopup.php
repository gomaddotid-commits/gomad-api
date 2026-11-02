<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Payment\Domain\PaymentGatewayException;
use App\Core\Payment\Infrastructure\MidtransSnapClient;
use App\Models\Business;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InitiateCollateralTopup
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly MidtransSnapClient $gateway,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string|int $amount,
        string $idempotencyKey,
    ): PaymentIntent {
        $this->authorizer->authorize($user, 'collateral.topup', $business);
        if ($business->type !== 'agency' || $business->owner_user_id !== $user->getKey()) {
            throw new InvalidArgumentException('Top up jaminan hanya dapat dibuat pemilik agency.');
        }
        $money = LedgerMoney::from($amount);
        if (! $money->isPositive() || $money->toCents() % 100 !== 0) {
            throw new InvalidArgumentException('Nominal top up harus berupa rupiah bulat yang lebih besar dari nol.');
        }
        if (strlen($idempotencyKey) > 128 || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }
        $this->accountSeeder->ensureBusinessAccounts($business);
        $requestHash = hash('sha256', json_encode([
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'purpose' => 'collateral_topup',
            'amount' => $money->toDecimal(),
        ], JSON_THROW_ON_ERROR));

        try {
            $payment = DB::transaction(function () use (
                $user,
                $business,
                $money,
                $idempotencyKey,
                $requestHash,
            ): PaymentIntent {
                $existing = PaymentIntent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertSameRequest($existing, $requestHash);

                    return $existing;
                }

                $code = 'PAY-'.Str::upper((string) Str::ulid());

                return PaymentIntent::query()->create([
                    'code' => $code,
                    'business_id' => $business->getKey(),
                    'user_id' => $user->getKey(),
                    'purpose' => 'collateral_topup',
                    'amount' => $money->toDecimal(),
                    'currency' => 'IDR',
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'provider_order_id' => $code,
                    'status' => 'creating',
                    'expires_at' => now()->addMinutes((int) config('services.midtrans.payment_timeout', 30)),
                ]);
            });
        } catch (QueryException $exception) {
            $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameRequest($existing, $requestHash);
            $payment = $existing;
        }

        if ($payment->status !== 'creating') {
            return $payment;
        }
        if ($payment->snap_token !== null && $payment->redirect_url !== null) {
            return $payment;
        }
        if (! $payment->wasRecentlyCreated) {
            throw new PaymentConflict('Status pembuatan checkout tidak pasti; jangan mengulang permintaan gateway.');
        }

        $createdByRequest = $payment->wasRecentlyCreated;
        try {
            $providerResponse = $this->gateway->createTransaction($payment->load('user'));
        } catch (PaymentGatewayException $exception) {
            $payment->forceFill([
                'status' => $exception->outcomeUnknown ? 'reconciliation_required' : 'failed',
            ])->save();
            throw $exception;
        }

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

    private function assertSameRequest(PaymentIntent $payment, string $requestHash): void
    {
        if (! hash_equals($payment->request_hash, $requestHash)) {
            throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pembayaran berbeda.');
        }
    }
}

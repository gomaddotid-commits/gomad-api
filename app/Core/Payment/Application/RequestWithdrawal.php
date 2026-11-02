<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\Business;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RequestWithdrawal
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly PostLedgerTransaction $postTransaction,
    ) {}

    public function execute(
        User $user,
        Business $business,
        string $bankCode,
        string $bankAccountNumber,
        string $bankAccountName,
        string|int $amount,
        string $idempotencyKey,
    ): Withdrawal {
        $this->authorizer->authorize($user, 'withdrawal.request', $business);
        if ($business->type !== 'agency' || (int) $business->owner_user_id !== (int) $user->getKey()) {
            throw new InvalidArgumentException('Pencairan hanya dapat diajukan pemilik agency.');
        }
        if (! preg_match('/^[A-Z0-9_-]{2,30}$/', strtoupper($bankCode))
            || ! preg_match('/^[0-9]{6,34}$/', $bankAccountNumber)
            || mb_strlen(trim($bankAccountName)) < 2
            || mb_strlen(trim($bankAccountName)) > 120) {
            throw new InvalidArgumentException('Data rekening pencairan tidak valid.');
        }
        if (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
        }

        $gross = LedgerMoney::from($amount);
        $minimum = LedgerMoney::from((string) config('services.withdrawal.minimum_amount', 100000));
        $fee = LedgerMoney::from((string) config('services.withdrawal.admin_fee', 5000));
        if ($gross->toCents() % 100 !== 0 || $gross->compare($minimum) < 0 || $gross->compare($fee) <= 0) {
            throw new InvalidArgumentException('Nominal pencairan minimal Rp '.number_format($minimum->toCents() / 100, 0, ',', '.').' dan berupa rupiah bulat.');
        }
        $net = $gross->minus($fee);
        $bankCode = strtoupper($bankCode);
        $bankAccountName = trim($bankAccountName);
        $requestHash = hash('sha256', json_encode([
            'business_id' => $business->getKey(),
            'user_id' => $user->getKey(),
            'bank_code' => $bankCode,
            'bank_account_number' => $bankAccountNumber,
            'bank_account_name' => mb_strtolower($bankAccountName),
            'amount' => $gross->toDecimal(),
        ], JSON_THROW_ON_ERROR));
        $this->accountSeeder->ensureBusinessAccounts($business);

        try {
            return DB::transaction(function () use (
                $user,
                $business,
                $bankCode,
                $bankAccountNumber,
                $bankAccountName,
                $gross,
                $fee,
                $net,
                $idempotencyKey,
                $requestHash,
            ): Withdrawal {
                $existing = Withdrawal::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    $this->assertSameRequest($existing, $requestHash);

                    return $existing;
                }

                $code = 'WD-'.Str::upper((string) Str::ulid());
                $ledgerTransaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                    category: LedgerCategory::WithdrawalRequest,
                    idempotencyKey: 'withdrawal:request:'.hash('sha256', $idempotencyKey),
                    entries: [
                        new LedgerEntryInput(
                            'BUSINESS.AVAILABLE:'.$business->getKey(),
                            LedgerDirection::Debit,
                            $gross->toDecimal(),
                        ),
                        new LedgerEntryInput(
                            'PLATFORM.WITHDRAWAL_PAYABLE',
                            LedgerDirection::Credit,
                            $net->toDecimal(),
                        ),
                        new LedgerEntryInput(
                            'PLATFORM.REVENUE.WITHDRAWAL_FEE',
                            LedgerDirection::Credit,
                            $fee->toDecimal(),
                        ),
                    ],
                    referenceType: 'withdrawal',
                    description: 'Permintaan pencairan '.$code,
                    createdBy: (int) $user->getKey(),
                ));

                return Withdrawal::query()->create([
                    'code' => $code,
                    'business_id' => $business->getKey(),
                    'user_id' => $user->getKey(),
                    'requested_by' => $user->getKey(),
                    'bank_code' => $bankCode,
                    'bank_account_number' => $bankAccountNumber,
                    'bank_account_name' => $bankAccountName,
                    'amount' => $gross->toDecimal(),
                    'fee_amount' => $fee->toDecimal(),
                    'net_amount' => $net->toDecimal(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'ledger_transaction_id' => $ledgerTransaction->getKey(),
                    'status' => 'pending',
                ]);
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = Withdrawal::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertSameRequest($existing, $requestHash);

            return $existing;
        }
    }

    private function assertSameRequest(Withdrawal $withdrawal, string $requestHash): void
    {
        if (! hash_equals($withdrawal->request_hash, $requestHash)) {
            throw new PaymentConflict('Idempotency-Key sudah digunakan untuk pencairan berbeda.');
        }
    }
}

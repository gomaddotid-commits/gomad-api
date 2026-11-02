<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\ReverseLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Notification\Application\WithdrawalStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class FailWithdrawal
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReverseLedgerTransaction $reverseTransaction,
    ) {}

    public function execute(
        User $admin,
        Withdrawal $withdrawal,
        string $failureCode,
        string $failureMessage,
    ): Withdrawal {
        $this->authorizer->authorize($admin, 'withdrawal.review');
        if (! preg_match('/^[A-Z0-9_-]{2,80}$/', strtoupper($failureCode))
            || trim($failureMessage) === ''
            || mb_strlen($failureMessage) > 500) {
            throw new \InvalidArgumentException('Kode dan alasan kegagalan pencairan wajib diisi.');
        }

        return DB::transaction(function () use ($admin, $withdrawal, $failureCode, $failureMessage): Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'failed'
                && $locked->failure_code === strtoupper($failureCode)
                && $locked->failure_message === trim($failureMessage)) {
                return $locked;
            }
            if (! in_array($locked->status, ['pending', 'approved'], true)) {
                throw new PaymentConflict('Pencairan tidak dapat ditandai gagal dari status saat ini.');
            }

            $this->reverseTransaction->execute(
                (int) $locked->ledger_transaction_id,
                'withdrawal:failure:'.$locked->getKey(),
                (int) $admin->getKey(),
                LedgerCategory::WithdrawalReversal,
            );
            $locked->forceFill([
                'status' => 'failed',
                'failure_code' => strtoupper($failureCode),
                'failure_message' => trim($failureMessage),
                'failed_at' => now(),
            ])->save();
            $this->notify($locked, 'failed', trim($failureMessage));

            return $locked;
        }, attempts: 3);
    }

    private function notify(Withdrawal $withdrawal, string $status, ?string $message = null): void
    {
        Notification::send($withdrawal->user, new WithdrawalStatusNotification(
            $withdrawal->code,
            $status,
            (string) $withdrawal->amount,
            $message,
        ));
    }
}

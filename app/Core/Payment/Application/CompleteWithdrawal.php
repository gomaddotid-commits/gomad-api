<?php

namespace App\Core\Payment\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Notification\Application\WithdrawalStatusNotification;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class CompleteWithdrawal
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly PostLedgerTransaction $postTransaction,
    ) {}

    public function execute(User $admin, Withdrawal $withdrawal, string $transferReference): Withdrawal
    {
        $this->authorizer->authorize($admin, 'withdrawal.review');
        $transferReference = trim($transferReference);
        if ($transferReference === '' || mb_strlen($transferReference) > 100) {
            throw new \InvalidArgumentException('Referensi transfer wajib diisi dan maksimal 100 karakter.');
        }

        return DB::transaction(function () use ($admin, $withdrawal, $transferReference): Withdrawal {
            $locked = Withdrawal::query()->whereKey($withdrawal->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === 'paid') {
                if ($locked->provider_reference === $transferReference) {
                    return $locked;
                }
                throw new PaymentConflict('Pencairan sudah tercatat dengan referensi transfer lain.');
            }
            if ($locked->status !== 'approved') {
                throw new PaymentConflict('Pencairan harus disetujui sebelum dicatat telah ditransfer.');
            }

            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::WithdrawalPaid,
                idempotencyKey: 'withdrawal:payout:'.$locked->getKey(),
                entries: [
                    new LedgerEntryInput(
                        'PLATFORM.WITHDRAWAL_PAYABLE',
                        LedgerDirection::Debit,
                        (string) $locked->net_amount,
                    ),
                    new LedgerEntryInput(
                        'PLATFORM.CASH',
                        LedgerDirection::Credit,
                        (string) $locked->net_amount,
                    ),
                ],
                referenceType: 'withdrawal_payout',
                referenceId: (int) $locked->getKey(),
                description: 'Transfer pencairan '.$locked->code.' · '.$transferReference,
                createdBy: (int) $admin->getKey(),
            ));
            $locked->forceFill([
                'status' => 'paid',
                'provider_reference' => $transferReference,
                'payout_transaction_id' => $transaction->getKey(),
                'paid_at' => now(),
            ])->save();
            $this->notify($locked, 'paid');

            return $locked;
        }, attempts: 3);
    }

    private function notify(Withdrawal $withdrawal, string $status): void
    {
        Notification::send($withdrawal->user, new WithdrawalStatusNotification(
            $withdrawal->code,
            $status,
            (string) $withdrawal->amount,
        ));
    }
}

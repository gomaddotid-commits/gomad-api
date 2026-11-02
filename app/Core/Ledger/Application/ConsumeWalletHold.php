<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Models\WalletHold;
use Illuminate\Support\Facades\DB;

final class ConsumeWalletHold
{
    public function __construct(private readonly PostLedgerTransaction $postTransaction) {}

    public function execute(int $holdId, PostLedgerTransactionData $transactionData): WalletHold
    {
        $hold = DB::transaction(function () use ($holdId, $transactionData): ?WalletHold {
            $hold = WalletHold::query()
                ->with('account')
                ->whereKey($holdId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($hold->status !== 'active') {
                throw new LedgerException('Hold sudah diselesaikan dan tidak dapat digunakan kembali.');
            }
            if ($hold->expires_at !== null && $hold->expires_at->isPast()) {
                $hold->forceFill(['status' => 'expired', 'released_at' => now()])->save();

                return null;
            }

            $debitsHold = false;
            foreach ($transactionData->entries as $entry) {
                if ($entry->accountCode === $hold->account->code
                    && $entry->direction === LedgerDirection::Debit
                    && $entry->amount->toDecimal() === (string) $hold->amount) {
                    $debitsHold = true;
                    break;
                }
            }
            if (! $debitsHold) {
                throw new LedgerException('Transaksi harus mendebit akun hold tepat sebesar nominal yang ditahan.');
            }

            $transaction = $this->postTransaction->execute($transactionData);
            $hold->forceFill([
                'status' => 'consumed',
                'released_at' => now(),
                'transaction_id' => $transaction->getKey(),
            ])->save();

            return $hold;
        }, attempts: 3);

        if ($hold === null) {
            throw new LedgerException('Hold sudah kedaluwarsa dan tidak dapat digunakan.');
        }

        return $hold;
    }
}

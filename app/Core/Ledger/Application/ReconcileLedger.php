<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\AccountClass;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;

final class ReconcileLedger
{
    /**
     * @return array{
     *   accounts: list<array{account_code: string, cached_balance: string, reconstructed_balance: string, matches: bool, invalid_entries: list<int>}>,
     *   unbalanced_transactions: list<int>
     * }
     */
    public function execute(): array
    {
        $accounts = LedgerAccount::query()
            ->orderBy('id')
            ->with(['entries' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->map(function (LedgerAccount $account): array {
                $balance = LedgerMoney::zero();
                $invalidEntries = [];
                $normalDirection = AccountClass::from($account->account_class)->normalDirection();

                foreach ($account->entries as $entry) {
                    $amount = LedgerMoney::from((string) $entry->amount);
                    $increases = LedgerDirection::from($entry->direction) === $normalDirection;
                    try {
                        $balance = $increases ? $balance->plus($amount) : $balance->minus($amount);
                    } catch (LedgerException) {
                        $invalidEntries[] = (int) $entry->getKey();

                        continue;
                    }

                    if ($balance->toDecimal() !== (string) $entry->balance_after) {
                        $invalidEntries[] = (int) $entry->getKey();
                    }
                }

                $cached = LedgerMoney::from((string) $account->balance_cached);

                return [
                    'account_code' => $account->code,
                    'cached_balance' => $cached->toDecimal(),
                    'reconstructed_balance' => $balance->toDecimal(),
                    'matches' => $cached->compare($balance) === 0 && $invalidEntries === [],
                    'invalid_entries' => $invalidEntries,
                ];
            })
            ->values()
            ->all();

        $unbalancedTransactions = LedgerTransaction::query()
            ->with('entries')
            ->get()
            ->filter(function ($transaction): bool {
                if ($transaction->entries->count() < 2) {
                    return true;
                }

                $debits = LedgerMoney::zero();
                $credits = LedgerMoney::zero();
                foreach ($transaction->entries as $entry) {
                    $amount = LedgerMoney::from((string) $entry->amount);
                    if (LedgerDirection::from($entry->direction) === LedgerDirection::Debit) {
                        $debits = $debits->plus($amount);
                    } else {
                        $credits = $credits->plus($amount);
                    }
                }

                return $debits->compare($credits) !== 0
                    || (string) $transaction->amount !== $debits->toDecimal();
            })
            ->map(fn ($transaction): int => (int) $transaction->getKey())
            ->values()
            ->all();

        return [
            'accounts' => $accounts,
            'unbalanced_transactions' => $unbalancedTransactions,
        ];
    }
}

<?php

namespace App\Core\Ledger\Domain;

final readonly class LedgerEntryInput
{
    public LedgerMoney $amount;

    public function __construct(
        public string $accountCode,
        public LedgerDirection $direction,
        string|int $amount,
    ) {
        $this->amount = LedgerMoney::from($amount);

        if (! $this->amount->isPositive()) {
            throw new \InvalidArgumentException('Nominal entri ledger harus lebih besar dari nol.');
        }
    }
}

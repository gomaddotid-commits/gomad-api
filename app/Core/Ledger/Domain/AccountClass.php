<?php

namespace App\Core\Ledger\Domain;

enum AccountClass: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Revenue = 'revenue';
    case Expense = 'expense';
    case Equity = 'equity';

    public function normalDirection(): LedgerDirection
    {
        return match ($this) {
            self::Asset, self::Expense => LedgerDirection::Debit,
            self::Liability, self::Revenue, self::Equity => LedgerDirection::Credit,
        };
    }
}

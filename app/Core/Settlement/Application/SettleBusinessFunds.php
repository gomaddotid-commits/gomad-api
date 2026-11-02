<?php

namespace App\Core\Settlement\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Models\Business;
use App\Models\LedgerTransaction;
use App\Models\User;
use InvalidArgumentException;

final class SettleBusinessFunds
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly PostLedgerTransaction $postTransaction,
    ) {}

    public function execute(
        User $actor,
        Business $business,
        string|int $amount,
        string $idempotencyKey,
    ): LedgerTransaction {
        $this->authorizer->authorize($actor, 'settlement.execute');
        if (! in_array($business->type, ['agency', 'grosir'], true)) {
            throw new InvalidArgumentException('Settlement hanya tersedia untuk agency dan grosir.');
        }

        $money = LedgerMoney::from($amount);
        if (! $money->isPositive()) {
            throw new InvalidArgumentException('Nominal settlement harus lebih besar dari nol.');
        }

        $businessAccount = $this->accountSeeder->ensureBusinessAccounts($business);
        $payableAccount = 'BUSINESS.PAYABLE:'.$business->getKey();

        return $this->postTransaction->execute(new PostLedgerTransactionData(
            category: LedgerCategory::SettlementToBusiness,
            idempotencyKey: $idempotencyKey,
            entries: [
                new LedgerEntryInput(
                    $payableAccount,
                    LedgerDirection::Debit,
                    $money->toDecimal(),
                ),
                new LedgerEntryInput(
                    $businessAccount->code,
                    LedgerDirection::Credit,
                    $money->toDecimal(),
                ),
            ],
            referenceType: 'business',
            referenceId: (int) $business->getKey(),
            description: 'Settlement ke '.$business->type.' '.$business->code,
            createdBy: (int) $actor->getKey(),
        ));
    }
}

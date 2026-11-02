<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\IdempotencyConflict;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;

final class ReverseLedgerTransaction
{
    public function __construct(private readonly PostLedgerTransaction $postTransaction) {}

    public function execute(
        int $transactionId,
        string $idempotencyKey,
        ?int $createdBy = null,
        ?LedgerCategory $category = null,
    ): LedgerTransaction {
        return DB::transaction(function () use ($transactionId, $idempotencyKey, $createdBy, $category): LedgerTransaction {
            $original = LedgerTransaction::query()
                ->with('entries.account')
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($original->status !== 'posted') {
                $existingReversal = LedgerTransaction::query()
                    ->where('reversal_of', $original->getKey())
                    ->first();

                if ($existingReversal !== null && $existingReversal->idempotency_key === $idempotencyKey) {
                    return $existingReversal->load('entries.account');
                }

                throw new LedgerException('Transaksi ini sudah dibalik.');
            }

            $entries = $original->entries->map(fn ($entry): LedgerEntryInput => new LedgerEntryInput(
                $entry->account->code,
                LedgerDirection::from($entry->direction)->opposite(),
                (string) $entry->amount,
            ))->all();

            $reversal = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: $category ?? LedgerCategory::from($original->category),
                idempotencyKey: $idempotencyKey,
                entries: $entries,
                referenceType: $original->reference_type,
                referenceId: $original->reference_id,
                description: 'Pembalikan transaksi ledger #'.$original->getKey(),
                createdBy: $createdBy,
                reversalOf: (int) $original->getKey(),
            ));

            if ($reversal->reversal_of !== $original->getKey()) {
                throw new IdempotencyConflict('Kunci idempotensi sudah digunakan untuk pembalikan lain.');
            }

            $original->forceFill(['status' => 'reversed'])->save();

            return $reversal;
        }, attempts: 3);
    }
}

<?php

namespace App\Core\Ledger\Application;

use App\Core\Ledger\Domain\AccountClass;
use App\Core\Ledger\Domain\IdempotencyConflict;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerException;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PostLedgerTransaction
{
    public function execute(PostLedgerTransactionData $data): LedgerTransaction
    {
        $requestHash = $this->requestHash($data);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return DB::transaction(fn (): LedgerTransaction => $this->postWithinTransaction(
                    $data,
                    $requestHash,
                ), attempts: 3);
            } catch (QueryException $exception) {
                if (app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                    if ($attempt === 5) {
                        throw $exception;
                    }

                    usleep(1000 * (2 ** $attempt));

                    continue;
                }

                $existing = LedgerTransaction::query()
                    ->where('idempotency_key', $data->idempotencyKey)
                    ->first();

                if ($existing === null) {
                    throw $exception;
                }

                $this->assertSameRequest($existing, $requestHash);

                return $existing->load('entries.account');
            }
        }

        throw new \LogicException('Ledger transaction retry loop exited unexpectedly.');
    }

    /**
     * @return array{amount: LedgerMoney, account_codes: list<string>, entries: list<LedgerEntryInput>}
     */
    private function validateEntries(PostLedgerTransactionData $data): array
    {
        if (count($data->entries) < 2) {
            throw new InvalidArgumentException('Transaksi ledger minimal memiliki dua entri.');
        }

        $seenAccounts = [];
        $debits = LedgerMoney::zero();
        $credits = LedgerMoney::zero();
        $entries = $data->entries;
        foreach ($entries as $entry) {
            if (! $entry instanceof LedgerEntryInput) {
                throw new InvalidArgumentException('Entri ledger harus menggunakan LedgerEntryInput.');
            }
            if (isset($seenAccounts[$entry->accountCode])) {
                throw new InvalidArgumentException('Satu akun hanya boleh muncul sekali dalam sebuah transaksi.');
            }
            $seenAccounts[$entry->accountCode] = true;
            if ($entry->direction === LedgerDirection::Debit) {
                $debits = $debits->plus($entry->amount);
            } else {
                $credits = $credits->plus($entry->amount);
            }
        }

        if ($debits->compare($credits) !== 0) {
            throw new LedgerException('Total debit dan kredit harus seimbang.');
        }

        return ['amount' => $debits, 'account_codes' => array_keys($seenAccounts), 'entries' => $entries];
    }

    /**
     * @param  array{amount: LedgerMoney, account_codes: list<string>, entries: list<LedgerEntryInput>}  $totals
     */
    private function postWithinTransaction(
        PostLedgerTransactionData $data,
        string $requestHash,
    ): LedgerTransaction {
        $existing = LedgerTransaction::query()
            ->where('idempotency_key', $data->idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertSameRequest($existing, $requestHash);

            return $existing->load('entries.account');
        }

        $totals = $this->validateEntries($data);
        $accounts = LedgerAccount::query()
            ->whereIn('code', $totals['account_codes'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('code')
            ->all();

        if (count($accounts) !== count($totals['account_codes'])) {
            throw new LedgerException('Satu atau beberapa akun ledger tidak ditemukan.');
        }

        foreach ($totals['entries'] as $entry) {
            $account = $accounts[$entry->accountCode];
            if (! $account->is_active) {
                throw new LedgerException('Akun ledger tidak aktif.');
            }
            if (str_starts_with($account->code, 'PLATFORM.TAX_PAYABLE_PPN')
                && $account->account_class !== 'liability') {
                throw new LedgerException('Akun PPN wajib bertipe kewajiban.');
            }
            if ($data->category === LedgerCategory::PpnRemittance
                && $account->account_class === 'revenue') {
                throw new LedgerException('Setoran PPN tidak boleh dicatat sebagai pendapatan.');
            }
        }

        $transaction = LedgerTransaction::query()->create([
            'category' => $data->category->value,
            'reference_type' => $data->referenceType,
            'reference_id' => $data->referenceId,
            'idempotency_key' => $data->idempotencyKey,
            'request_hash' => $requestHash,
            'amount' => $totals['amount']->toDecimal(),
            'status' => 'posted',
            'description' => $data->description,
            'occurred_at' => $data->occurredAt ?? now(),
            'created_by' => $data->createdBy,
            'reversal_of' => $data->reversalOf,
        ]);

        foreach ($totals['entries'] as $entry) {
            /** @var LedgerAccount $account */
            $account = $accounts[$entry->accountCode];
            $balance = LedgerMoney::from((string) $account->balance_cached);
            $normalDirection = AccountClass::from($account->account_class)->normalDirection();
            $increasesBalance = $entry->direction === $normalDirection;
            $newBalance = $increasesBalance
                ? $balance->plus($entry->amount)
                : $balance->minus($entry->amount);

            $account->forceFill(['balance_cached' => $newBalance->toDecimal()])->save();
            $transaction->entries()->create([
                'account_id' => $account->getKey(),
                'direction' => $entry->direction->value,
                'amount' => $entry->amount->toDecimal(),
                'balance_after' => $newBalance->toDecimal(),
            ]);
        }

        return $transaction->load('entries.account');
    }

    private function requestHash(PostLedgerTransactionData $data): string
    {
        $entries = array_map(
            fn (LedgerEntryInput $entry): array => [
                'account_code' => $entry->accountCode,
                'direction' => $entry->direction->value,
                'amount' => $entry->amount->toDecimal(),
            ],
            $data->entries,
        );
        usort($entries, fn (array $left, array $right): int => [
            $left['account_code'], $left['direction'],
        ] <=> [
            $right['account_code'], $right['direction'],
        ]);

        return hash('sha256', json_encode([
            'category' => $data->category->value,
            'reference_type' => $data->referenceType,
            'reference_id' => $data->referenceId,
            'description' => $data->description,
            'occurred_at' => $data->occurredAt?->format(DATE_ATOM),
            'created_by' => $data->createdBy,
            'reversal_of' => $data->reversalOf,
            'entries' => $entries,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertSameRequest(LedgerTransaction $existing, string $requestHash): void
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw new IdempotencyConflict('Kunci idempotensi sudah digunakan untuk transaksi berbeda.');
        }
    }
}

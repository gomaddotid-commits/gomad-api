<?php

namespace App\Core\Rental\Application;

use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Models\LedgerTransaction;
use App\Models\Rental;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReturnRentalDeposit
{
    public function __construct(private readonly PostLedgerTransaction $postTransaction) {}

    /**
     * Mengembalikan deposit penyewa setelah kendaraan kembali.
     *
     * Deposit ditahan sebagai kewajiban, bukan pendapatan. Saat selesai:
     * - bagian yang dikembalikan mengurangi kewajiban dan kas keluar (RefundApproved),
     * - bagian yang dipotong (denda/kerusakan) berpindah dari kewajiban menjadi hak agency
     *   (BUSINESS.PAYABLE) lewat DEPOSIT_DEDUCTED, bukan dianggap pendapatan platform.
     */
    public function execute(Rental $rental, string|int|float $deduction = 0, ?string $reason = null): ?LedgerTransaction
    {
        return DB::transaction(function () use ($rental, $deduction, $reason): ?LedgerTransaction {
            $locked = Rental::query()->whereKey($rental->getKey())->lockForUpdate()->firstOrFail();
            $deposit = LedgerMoney::from((string) $locked->deposit_amount);
            $deductionAmount = (float) ($deduction ?? 0);

            if ($deductionAmount < 0) {
                throw new InvalidArgumentException('Potongan deposit tidak boleh negatif.');
            }

            $deducted = LedgerMoney::from(number_format($deductionAmount, 2, '.', ''));

            if (! $deposit->isPositive() || $locked->deposit_status !== 'held') {
                return null;
            }

            if ($deducted->toCents() > $deposit->toCents()) {
                throw new InvalidArgumentException('Potongan deposit melebihi nominal deposit yang ditahan.');
            }

            $reason = $reason !== null ? trim($reason) : null;

            if ($deducted->isPositive() && ($reason === null || $reason === '')) {
                throw new InvalidArgumentException('Alasan potongan deposit wajib diisi.');
            }

            $refunded = $deposit->minus($deducted);

            // Deposit yang dibayar tunai dipegang agency, bukan platform, sehingga
            // tidak ada kewajiban platform yang perlu dicatat ulang saat dikembalikan.
            $wasPaidOnline = $locked->payment_intent_id !== null
                && LedgerTransaction::query()
                    ->where('idempotency_key', 'midtrans:payment:'.$locked->payment_intent_id)
                    ->exists();

            $transaction = null;

            if ($wasPaidOnline && $deducted->isPositive()) {
                $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                    category: LedgerCategory::DepositDeducted,
                    idempotencyKey: 'rental:deposit-deduction:'.$locked->getKey(),
                    entries: [
                        new LedgerEntryInput(
                            'PLATFORM.DEPOSIT_PAYABLE',
                            LedgerDirection::Debit,
                            $deducted->toDecimal(),
                        ),
                        new LedgerEntryInput(
                            'BUSINESS.PAYABLE:'.$locked->business_id,
                            LedgerDirection::Credit,
                            $deducted->toDecimal(),
                        ),
                    ],
                    referenceType: 'rental',
                    referenceId: (int) $locked->getKey(),
                    description: 'Potongan deposit sewa '.$locked->code.' — '.$reason,
                ));
            }

            if ($wasPaidOnline && $refunded->isPositive()) {
                $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                    category: LedgerCategory::RefundApproved,
                    idempotencyKey: 'rental:deposit-return:'.$locked->getKey(),
                    entries: [
                        new LedgerEntryInput(
                            'PLATFORM.DEPOSIT_PAYABLE',
                            LedgerDirection::Debit,
                            $refunded->toDecimal(),
                        ),
                        new LedgerEntryInput(
                            'PLATFORM.CASH',
                            LedgerDirection::Credit,
                            $refunded->toDecimal(),
                        ),
                    ],
                    referenceType: 'rental',
                    referenceId: (int) $locked->getKey(),
                    description: 'Pengembalian deposit sewa '.$locked->code,
                ));
            }

            $locked->forceFill([
                'deposit_status' => 'returned',
                'deposit_returned_at' => now(),
                'deposit_deducted_amount' => $deducted->toDecimal(),
                'deposit_refunded_amount' => $refunded->toDecimal(),
                'deposit_deduction_reason' => $deducted->isPositive() ? $reason : null,
            ])->save();

            return $transaction;
        }, attempts: 3);
    }
}

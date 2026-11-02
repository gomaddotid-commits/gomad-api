<?php

namespace App\Core\Rental\Application;

use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Loyalty\Application\AwardLoyaltyPoints;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Referral\Application\QualifyReferral;
use App\Core\Rental\Domain\RentalStatus;
use App\Models\LedgerTransaction;
use App\Models\LoyaltyEvent;
use App\Models\PaymentIntent;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PostRentalPayment
{
    public function __construct(
        private readonly PostLedgerTransaction $postTransaction,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly QualifyReferral $qualifyReferral,
        private readonly AwardLoyaltyPoints $awardPoints,
    ) {}

    public function execute(PaymentIntent $payment): LedgerTransaction
    {
        if ($payment->purpose !== 'rental' || $payment->rental_id === null) {
            throw new \LogicException('Payment intent tidak terhubung ke sewa.');
        }

        return DB::transaction(function () use ($payment): LedgerTransaction {
            $rental = Rental::query()->whereKey($payment->rental_id)->lockForUpdate()->firstOrFail();

            if (in_array($rental->status, [
                RentalStatus::Paid->value,
                RentalStatus::Active->value,
                RentalStatus::Returned->value,
                RentalStatus::Completed->value,
            ], true)) {
                return LedgerTransaction::query()
                    ->where('idempotency_key', 'midtrans:payment:'.$payment->getKey())
                    ->firstOrFail();
            }

            if ($rental->status !== RentalStatus::Pending->value) {
                Log::critical('Paid Midtrans callback arrived for a rental that is no longer payable.', [
                    'rental_id' => $rental->getKey(),
                    'payment_intent_id' => $payment->getKey(),
                    'rental_status' => $rental->status,
                ]);
                throw new PaymentConflict('Pembayaran diterima untuk sewa yang tidak lagi dapat dibayar; perlu rekonsiliasi manual.');
            }

            if ((int) $rental->customer_id !== (int) $payment->user_id
                || (int) $rental->business_id !== (int) $payment->business_id
                || LedgerMoney::from((string) $payment->amount)
                    ->compare(LedgerMoney::from((string) $rental->total_price)) !== 0) {
                throw new PaymentConflict('Data penyewa atau nominal sewa tidak cocok.');
            }

            $agency = $rental->business;
            $this->accountSeeder->ensureBusinessAccounts($agency);

            $discount = LedgerMoney::from((string) $rental->discount_amount);
            $sellerShare = LedgerMoney::from((string) $rental->price)
                ->plus(LedgerMoney::from((string) $rental->driver_fee));
            if ($discount->isPositive()) {
                $sellerShare = $sellerShare->minus($discount);
            }

            $entries = [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $rental->total_price),
                new LedgerEntryInput(
                    'BUSINESS.PAYABLE:'.$agency->getKey(),
                    LedgerDirection::Credit,
                    $sellerShare->toDecimal(),
                ),
            ];

            foreach ([
                'service_fee' => 'PLATFORM.REVENUE.SERVICE_FEE',
                'platform_fee' => 'PLATFORM.REVENUE.PLATFORM_FEE',
                'ppn_amount' => 'PLATFORM.TAX_PAYABLE_PPN',
            ] as $amountField => $accountCode) {
                $amount = LedgerMoney::from((string) $rental->{$amountField});
                if ($amount->isPositive()) {
                    $entries[] = new LedgerEntryInput($accountCode, LedgerDirection::Credit, $amount->toDecimal());
                }
            }

            // Deposit bukan pendapatan: ia kewajiban yang harus kembali setelah kendaraan dikembalikan.
            $deposit = LedgerMoney::from((string) $rental->deposit_amount);
            if ($deposit->isPositive()) {
                $entries[] = new LedgerEntryInput(
                    'PLATFORM.DEPOSIT_PAYABLE',
                    LedgerDirection::Credit,
                    $deposit->toDecimal(),
                );
            }

            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::RentalPaid,
                idempotencyKey: 'midtrans:payment:'.$payment->getKey(),
                entries: $entries,
                referenceType: 'rental',
                referenceId: (int) $rental->getKey(),
                description: 'Pembayaran sewa Mad Trans '.$rental->code,
                createdBy: (int) $payment->user_id,
            ));

            // PR2: diskon yang ditanggung platform tidak boleh memotong agency.
            if ($discount->isPositive() && $rental->discount_bearer === 'platform') {
                $this->postTransaction->execute(new PostLedgerTransactionData(
                    category: LedgerCategory::PromoPlatformBorne,
                    idempotencyKey: 'promo:borne:rental:'.$rental->getKey(),
                    entries: [
                        new LedgerEntryInput(
                            'PLATFORM.EXPENSE.PROMO',
                            LedgerDirection::Debit,
                            $discount->toDecimal(),
                        ),
                        new LedgerEntryInput(
                            'BUSINESS.PAYABLE:'.$agency->getKey(),
                            LedgerDirection::Credit,
                            $discount->toDecimal(),
                        ),
                    ],
                    referenceType: 'rental',
                    referenceId: (int) $rental->getKey(),
                    description: 'Beban promo platform '.$rental->promo_code.' untuk '.$rental->code,
                ));
            }

            $rental->forceFill([
                'status' => RentalStatus::Paid->value,
                'paid_at' => now(),
                'deposit_status' => $deposit->isPositive() ? 'held' : 'none',
            ])->save();

            if ($rental->customer instanceof User) {
                $this->awardPoints->execute(
                    $rental->customer,
                    LoyaltyEvent::KIND_RENTAL_PAID,
                    'rental',
                    (int) $rental->getKey(),
                    LedgerMoney::from((string) $rental->price)
                        ->plus(LedgerMoney::from((string) $rental->driver_fee))
                        ->toCents(),
                );
                $this->qualifyReferral->execute($rental->customer);
            }

            return $transaction;
        }, attempts: 3);
    }
}

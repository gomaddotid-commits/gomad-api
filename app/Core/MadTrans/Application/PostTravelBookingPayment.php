<?php

namespace App\Core\MadTrans\Application;

use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\Ledger\Infrastructure\LedgerAccountSeeder;
use App\Core\Loyalty\Application\AwardLoyaltyPoints;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Core\Referral\Application\QualifyReferral;
use App\Models\LedgerTransaction;
use App\Models\LoyaltyEvent;
use App\Models\PaymentIntent;
use App\Models\TravelBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PostTravelBookingPayment
{
    public function __construct(
        private readonly PostLedgerTransaction $postTransaction,
        private readonly LedgerAccountSeeder $accountSeeder,
        private readonly QualifyReferral $qualifyReferral,
        private readonly AwardLoyaltyPoints $awardPoints,
    ) {}

    public function execute(PaymentIntent $payment): LedgerTransaction
    {
        if ($payment->purpose !== 'travel_booking' || $payment->booking_id === null) {
            throw new \LogicException('Payment intent tidak terhubung ke pemesanan perjalanan.');
        }

        return DB::transaction(function () use ($payment): LedgerTransaction {
            $booking = TravelBooking::query()->whereKey($payment->booking_id)->lockForUpdate()->firstOrFail();
            if (in_array($booking->status, [
                BookingStatus::Paid->value,
                BookingStatus::OnGoing->value,
                BookingStatus::Completed->value,
            ], true)) {
                return LedgerTransaction::query()
                    ->where('idempotency_key', 'midtrans:payment:'.$payment->getKey())
                    ->firstOrFail();
            }
            if ($booking->status !== BookingStatus::Pending->value) {
                Log::critical('Paid Midtrans callback arrived for a Mad Trans booking that is no longer payable.', [
                    'booking_id' => $booking->getKey(),
                    'payment_intent_id' => $payment->getKey(),
                    'booking_status' => $booking->status,
                ]);
                throw new PaymentConflict('Pembayaran diterima untuk pemesanan yang tidak lagi dapat dibayar; perlu rekonsiliasi manual.');
            }
            if ((int) $booking->customer_id !== (int) $payment->user_id
                || (int) $booking->schedule->business_id !== (int) $payment->business_id
                || LedgerMoney::from((string) $payment->amount)
                    ->compare(LedgerMoney::from((string) $booking->total_price)) !== 0) {
                throw new PaymentConflict('Data pemesan atau nominal perjalanan tidak cocok.');
            }

            $agency = $booking->schedule->business;
            $this->accountSeeder->ensureBusinessAccounts($agency);
            $discount = LedgerMoney::from((string) $booking->discount_amount);
            $base = LedgerMoney::from((string) $booking->base_price);
            $pickupFee = LedgerMoney::from((string) ($booking->pickup_fee ?? '0'));
            // Hak agency = tarif tiket − diskon yang ditanggung agency + biaya jemput.
            $sellerShare = ($discount->isPositive() ? $base->minus($discount) : $base)->plus($pickupFee);
            $entries = [
                new LedgerEntryInput('PLATFORM.CASH', LedgerDirection::Debit, (string) $booking->total_price),
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
                $amount = LedgerMoney::from((string) $booking->{$amountField});
                if ($amount->isPositive()) {
                    $entries[] = new LedgerEntryInput(
                        $accountCode,
                        LedgerDirection::Credit,
                        $amount->toDecimal(),
                    );
                }
            }
            if (LedgerMoney::from((string) $booking->discount_amount)->isPositive()
                && ! in_array($booking->discount_bearer, ['platform', 'business'], true)) {
                throw new PaymentConflict('Penanggung diskon perjalanan tidak dikenal.');
            }

            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::BookingPaidOnline,
                idempotencyKey: 'midtrans:payment:'.$payment->getKey(),
                entries: $entries,
                referenceType: 'travel_booking',
                referenceId: (int) $booking->getKey(),
                description: 'Pembayaran tiket Mad Trans '.$booking->code,
                createdBy: (int) $payment->user_id,
            ));

            // PR2: diskon yang ditanggung platform tidak boleh memotong agency.
            // Platform menalangi bagian diskon ke rekening penjual lewat beban promo.
            if ($discount->isPositive() && $booking->discount_bearer === 'platform') {
                $this->postTransaction->execute(new PostLedgerTransactionData(
                    category: LedgerCategory::PromoPlatformBorne,
                    idempotencyKey: 'promo:borne:travel_booking:'.$booking->getKey(),
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
                    referenceType: 'travel_booking',
                    referenceId: (int) $booking->getKey(),
                    description: 'Beban promo platform '.$booking->promo_code.' untuk '.$booking->code,
                ));
            }
            $booking->forceFill([
                'status' => BookingStatus::Paid->value,
                'paid_at' => now(),
            ])->save();

            if ($booking->customer instanceof User) {
                $this->awardPoints->execute(
                    $booking->customer,
                    LoyaltyEvent::KIND_TRAVEL_PAID,
                    'travel_booking',
                    (int) $booking->getKey(),
                    LedgerMoney::from((string) $booking->base_price)->toCents(),
                );
                $this->qualifyReferral->execute($booking->customer);
            }

            return $transaction;
        }, attempts: 3);
    }
}

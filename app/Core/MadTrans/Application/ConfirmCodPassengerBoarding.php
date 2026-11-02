<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\PostLedgerTransaction;
use App\Core\Ledger\Application\ReleaseWalletHold;
use App\Core\Ledger\Domain\LedgerCategory;
use App\Core\Ledger\Domain\LedgerDirection;
use App\Core\Ledger\Domain\LedgerEntryInput;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\Ledger\Domain\PostLedgerTransactionData;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\ScheduleStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\BookingPassenger;
use App\Models\BusinessMember;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ConfirmCodPassengerBoarding
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly TravelBookingFare $fareCalculator,
        private readonly ReleaseWalletHold $releaseHold,
        private readonly PostLedgerTransaction $postTransaction,
    ) {}

    public function execute(
        User $driver,
        TravelBooking $booking,
        BookingPassenger $passenger,
    ): BookingPassenger {
        $schedule = $booking->schedule;
        $agency = $schedule->business;
        $this->authorizer->authorize($driver, 'trip.manage', $agency);
        if ($schedule->driver_id === null || ! BusinessMember::query()
            ->whereKey($schedule->driver_id)
            ->where('user_id', $driver->getKey())
            ->where('business_id', $agency->getKey())
            ->where('role', 'driver')
            ->where('status', 'active')
            ->exists()) {
            throw new AuthorizationException('Hanya pengemudi yang ditugaskan dapat mengonfirmasi boarding.');
        }

        return DB::transaction(function () use ($driver, $booking, $passenger, $schedule): BookingPassenger {
            $lockedBooking = TravelBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $lockedSchedule = TravelSchedule::query()
                ->whereKey($schedule->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedPassenger = BookingPassenger::query()
                ->whereKey($passenger->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $lockedPassenger->booking_id !== (int) $lockedBooking->getKey()) {
                throw new AuthorizationException('Penumpang bukan bagian dari pemesanan ini.');
            }
            if ($lockedPassenger->boarded_at !== null) {
                return $lockedPassenger;
            }
            if ($lockedBooking->payment_method !== 'cod'
                || ! in_array($lockedBooking->status, [
                    BookingStatus::Confirmed->value,
                    BookingStatus::OnGoing->value,
                ], true)
                || (int) $lockedBooking->schedule_id !== (int) $lockedSchedule->getKey()
                || $lockedSchedule->status !== ScheduleStatus::InProgress->value
                || $lockedPassenger->seat_number === null
                || $lockedPassenger->no_show_at !== null) {
                throw new PaymentConflict('Penumpang atau jadwal tidak memenuhi syarat boarding COD.');
            }

            $hold = DB::table('wallet_holds')
                ->where('source_type', 'travel_cod_passenger')
                ->where('source_id', $lockedPassenger->getKey())
                ->where('reason', 'cod_collateral')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($hold === null) {
                throw new PaymentConflict('Jaminan COD untuk kursi penumpang tidak tersedia.');
            }

            $fees = $this->fareCalculator->calculate(
                LedgerMoney::from((string) $lockedBooking->routePricing->price),
                1,
            );
            $feeAmount = $fees['service']->plus($fees['platform'])->plus($fees['tax']);
            if (LedgerMoney::from((string) $hold->amount)->compare($feeAmount) < 0) {
                throw new PaymentConflict('Jaminan COD lebih kecil dari fee aktual; perlu rekonsiliasi.');
            }

            $this->releaseHold->execute((int) $hold->id);
            $collateralCode = 'BUSINESS.COLLATERAL:'.$lockedSchedule->business_id;
            $entries = [
                new LedgerEntryInput($collateralCode, LedgerDirection::Debit, $feeAmount->toDecimal()),
            ];
            foreach ([
                'service' => 'PLATFORM.REVENUE.SERVICE_FEE',
                'platform' => 'PLATFORM.REVENUE.PLATFORM_FEE',
                'tax' => 'PLATFORM.TAX_PAYABLE_PPN',
            ] as $field => $accountCode) {
                if ($fees[$field]->isPositive()) {
                    $entries[] = new LedgerEntryInput(
                        $accountCode,
                        LedgerDirection::Credit,
                        $fees[$field]->toDecimal(),
                    );
                }
            }
            $transaction = $this->postTransaction->execute(new PostLedgerTransactionData(
                category: LedgerCategory::CodFeeCollected,
                idempotencyKey: 'madtrans:cod:passenger:'.$lockedPassenger->getKey(),
                entries: $entries,
                referenceType: 'travel_booking_passenger',
                referenceId: (int) $lockedPassenger->getKey(),
                description: 'Fee COD boarding Mad Trans '.$lockedBooking->code,
                createdBy: (int) $driver->getKey(),
            ));

            $lockedPassenger->forceFill([
                'boarded_at' => now(),
                'cod_fee_transaction_id' => $transaction->getKey(),
            ])->save();
            $lockedBooking->forceFill([
                'status' => BookingStatus::OnGoing->value,
                'started_at' => $lockedBooking->started_at ?? now(),
            ])->save();

            return $lockedPassenger;
        }, attempts: 3);
    }
}

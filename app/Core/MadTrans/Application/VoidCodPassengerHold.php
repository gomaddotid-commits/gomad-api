<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\ReleaseWalletHold;
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

final class VoidCodPassengerHold
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReleaseWalletHold $releaseHold,
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
            throw new AuthorizationException('Hanya pengemudi yang ditugaskan dapat mencatat no-show.');
        }

        return DB::transaction(function () use ($booking, $passenger, $schedule): BookingPassenger {
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
            if ($lockedPassenger->no_show_at !== null) {
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
                || $lockedPassenger->boarded_at !== null) {
                throw new PaymentConflict('Penumpang tidak memenuhi syarat untuk ditandai no-show COD.');
            }

            $hold = DB::table('wallet_holds')
                ->where('source_type', 'travel_cod_passenger')
                ->where('source_id', $lockedPassenger->getKey())
                ->where('reason', 'cod_collateral')
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($hold === null) {
                throw new PaymentConflict('Jaminan COD penumpang tidak tersedia untuk pelepasan.');
            }
            $this->releaseHold->execute((int) $hold->id);
            $lockedPassenger->forceFill(['no_show_at' => now()])->save();

            return $lockedPassenger;
        }, attempts: 3);
    }
}

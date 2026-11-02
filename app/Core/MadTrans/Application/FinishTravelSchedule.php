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

final class FinishTravelSchedule
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReleaseWalletHold $releaseHold,
    ) {}

    public function execute(User $actor, TravelSchedule $schedule): TravelSchedule
    {
        $agency = $schedule->business;
        $this->authorizer->authorize($actor, 'trip.manage', $agency);
        $isOwner = (int) $agency->owner_user_id === (int) $actor->getKey();
        $isAssignedDriver = $schedule->driver_id !== null
            && BusinessMember::query()
                ->whereKey($schedule->driver_id)
                ->where('user_id', $actor->getKey())
                ->where('business_id', $agency->getKey())
                ->where('role', 'driver')
                ->where('status', 'active')
                ->exists();
        if (! $isOwner && ! $isAssignedDriver) {
            throw new AuthorizationException('Hanya pemilik atau pengemudi yang ditugaskan dapat menyelesaikan perjalanan.');
        }

        return DB::transaction(function () use ($schedule): TravelSchedule {
            $locked = TravelSchedule::query()->whereKey($schedule->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === ScheduleStatus::Completed->value) {
                return $locked;
            }
            if ($locked->status !== ScheduleStatus::InProgress->value) {
                throw new PaymentConflict('Hanya perjalanan yang sedang berlangsung dapat diselesaikan.');
            }

            $unresolvedPayments = TravelBooking::query()
                ->where('schedule_id', $locked->getKey())
                ->where('status', BookingStatus::Pending->value)
                ->whereNotNull('payment_intent_id')
                ->exists();
            if ($unresolvedPayments) {
                throw new PaymentConflict('Pembayaran online yang masih diproses harus direkonsiliasi sebelum perjalanan ditutup.');
            }
            $unpaidBookings = TravelBooking::query()
                ->where('schedule_id', $locked->getKey())
                ->where('status', BookingStatus::Pending->value)
                ->whereNull('payment_intent_id')
                ->lockForUpdate()
                ->get();
            foreach ($unpaidBookings as $booking) {
                BookingPassenger::query()
                    ->where('booking_id', $booking->getKey())
                    ->whereNotNull('seat_number')
                    ->update([
                        'seat_number' => null,
                        'seat_released_at' => now(),
                        'updated_at' => now(),
                    ]);
                $booking->forceFill([
                    'status' => BookingStatus::Expired->value,
                    'expires_at' => now(),
                ])->save();
            }

            $unboardedCodPassengers = BookingPassenger::query()
                ->where('schedule_id', $locked->getKey())
                ->whereNull('boarded_at')
                ->whereNull('no_show_at')
                ->whereHas('booking', fn ($query) => $query
                    ->where('payment_method', 'cod')
                    ->whereIn('status', [
                        BookingStatus::Confirmed->value,
                        BookingStatus::OnGoing->value,
                    ]))
                ->lockForUpdate()
                ->get();
            foreach ($unboardedCodPassengers as $passenger) {
                $hold = DB::table('wallet_holds')
                    ->where('source_type', 'travel_cod_passenger')
                    ->where('source_id', $passenger->getKey())
                    ->where('reason', 'cod_collateral')
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();
                if ($hold === null) {
                    throw new PaymentConflict('Jaminan COD penumpang tidak ditemukan saat penyelesaian; rekonsiliasi diperlukan.');
                }
                $this->releaseHold->execute((int) $hold->id);
                $passenger->forceFill(['no_show_at' => now()])->save();
            }

            TravelBooking::query()
                ->where('schedule_id', $locked->getKey())
                ->whereIn('status', [
                    BookingStatus::Confirmed->value,
                    BookingStatus::Paid->value,
                    BookingStatus::OnGoing->value,
                ])
                ->update([
                    'status' => BookingStatus::Completed->value,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
            $locked->forceFill([
                'status' => ScheduleStatus::Completed->value,
                'finished_at' => now(),
            ])->save();

            return $locked;
        }, attempts: 3);
    }
}

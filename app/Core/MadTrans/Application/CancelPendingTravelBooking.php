<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\ReleaseWalletHold;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\BookingPassenger;
use App\Models\TravelBooking;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class CancelPendingTravelBooking
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly ReleaseWalletHold $releaseHold,
        private readonly TravelBookingCancellationPolicy $policy,
    ) {}

    public function execute(User $customer, TravelBooking $booking): TravelBooking
    {
        $this->authorizer->authorize($customer, 'booking.cancel');
        if ((int) $booking->customer_id !== (int) $customer->getKey()) {
            throw new AuthorizationException('Pemesanan hanya dapat dibatalkan oleh pemesan.');
        }

        return DB::transaction(function () use ($booking): TravelBooking {
            $locked = TravelBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === BookingStatus::Cancelled->value) {
                return $locked->load('passengers');
            }

            // Aturan yang sama dengan layar konfirmasi — satu sumber keputusan.
            $outcome = $this->policy->decide($locked);
            if (! $outcome->allowed) {
                throw new PaymentConflict($outcome->reason);
            }
            $isUnboardedCod = $outcome->code === 'cod_unboarded';

            $passengers = BookingPassenger::query()
                ->where('booking_id', $locked->getKey())
                ->whereNotNull('seat_number')
                ->lockForUpdate()
                ->get();
            if ($isUnboardedCod) {
                foreach ($passengers as $passenger) {
                    $hold = DB::table('wallet_holds')
                        ->where('source_type', 'travel_cod_passenger')
                        ->where('source_id', $passenger->getKey())
                        ->where('reason', 'cod_collateral')
                        ->where('status', 'active')
                        ->lockForUpdate()
                        ->first();
                    if ($hold === null) {
                        throw new PaymentConflict('Jaminan kursi COD tidak ditemukan; pembatalan perlu rekonsiliasi.');
                    }
                    $this->releaseHold->execute((int) $hold->id);
                }
            }
            foreach ($passengers as $passenger) {
                $passenger->forceFill([
                    'seat_number' => null,
                    'seat_released_at' => now(),
                ])->save();
            }
            $locked->forceFill([
                'status' => BookingStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();

            return $locked->load('passengers');
        }, attempts: 3);
    }
}

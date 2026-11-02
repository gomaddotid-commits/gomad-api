<?php

namespace App\Core\Payment\Application;

use App\Core\MadTrans\Domain\BookingStatus;
use App\Models\BookingPassenger;
use App\Models\PaymentIntent;
use App\Models\TravelBooking;
use Illuminate\Support\Facades\DB;

final class ExpireTravelBookingPayment
{
    public function execute(PaymentIntent $payment, string $paymentStatus): void
    {
        if ($payment->purpose !== 'travel_booking' || $payment->booking_id === null) {
            return;
        }

        DB::transaction(function () use ($payment): void {
            $booking = TravelBooking::query()
                ->whereKey($payment->booking_id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($booking->status !== BookingStatus::Pending->value) {
                return;
            }
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
                'cancelled_at' => now(),
            ])->save();
        }, attempts: 3);
    }
}

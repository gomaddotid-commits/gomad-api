<?php

namespace App\Core\MadTrans\Application;

use App\Core\MadTrans\Domain\BookingStatus;
use App\Models\BookingPassenger;
use App\Models\TravelBooking;
use Illuminate\Support\Facades\DB;

/**
 * Melepas pemesanan yang tidak pernah dibayar setelah batas waktunya lewat.
 *
 * Tanpa ini, kursi "terlihat" bebas tetapi baris pemesanan menggantung selamanya
 * (temuan B1/B2 pada `docs/04-checklist-jangan-diulang.md`).
 * Hanya pemesanan tanpa percobaan pembayaran yang diambil; begitu payment intent
 * dibuat, statusnya ditentukan oleh callback gateway.
 */
final class ExpirePendingTravelBookings
{
    /**
     * @return array{expired: int, released_seats: int}
     */
    public function execute(int $limit = 200): array
    {
        $graceMinutes = max(1, (int) config('services.midtrans.expire_grace_minutes', 15));

        return DB::transaction(function () use ($limit, $graceMinutes): array {
            $bookings = TravelBooking::query()
                ->where('status', BookingStatus::Pending->value)
                ->where('payment_method', 'midtrans')
                ->whereNull('payment_intent_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->subMinutes($graceMinutes))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $releasedSeats = 0;

            foreach ($bookings as $booking) {
                $passengers = BookingPassenger::query()
                    ->where('booking_id', $booking->getKey())
                    ->whereNotNull('seat_number')
                    ->lockForUpdate()
                    ->get();

                foreach ($passengers as $passenger) {
                    $passenger->forceFill([
                        'seat_number' => null,
                        'seat_released_at' => now(),
                    ])->save();
                    $releasedSeats++;
                }

                $booking->forceFill([
                    'status' => BookingStatus::Expired->value,
                    'cancelled_at' => now(),
                ])->save();
            }

            return ['expired' => $bookings->count(), 'released_seats' => $releasedSeats];
        }, attempts: 3);
    }
}

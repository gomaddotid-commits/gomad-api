<?php

namespace App\Core\MadTrans\Application;

use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\MadTrans\Domain\CancellationOutcome;
use App\Models\TravelBooking;
use Illuminate\Support\Carbon;

/**
 * Satu-satunya tempat yang memutuskan apakah sebuah pemesanan masih boleh
 * dibatalkan sendiri oleh pemesan.
 *
 * Aturannya sempit dengan sengaja: hanya pesanan yang **belum menarik uang**
 * (pending online tanpa payment intent) dan pesanan COD yang belum berangkat
 * serta belum ada penumpang naik. Begitu uang sudah masuk, pembatalan menjadi
 * urusan ops (refund), bukan tombol di tangan pengguna — itu yang membuat
 * angka di ledger tidak bisa berubah tanpa jejak.
 */
final class TravelBookingCancellationPolicy
{
    public function decide(TravelBooking $booking): CancellationOutcome
    {
        if ($booking->status === BookingStatus::Cancelled->value) {
            return new CancellationOutcome(true, 'cancelled', 'Pemesanan ini sudah dibatalkan sebelumnya.');
        }

        if (in_array($booking->status, [BookingStatus::Paid->value, BookingStatus::OnGoing->value], true)
            || ($booking->status === BookingStatus::Pending->value && $booking->payment_intent_id !== null)) {
            return CancellationOutcome::blocked(
                'Pembayaran sudah dibuat, jadi pembatalan harus lewat tim operasional agar pengembalian dana tercatat.',
                moneyCaptured: true,
            );
        }

        $schedule = $booking->schedule;
        if ($schedule === null) {
            return CancellationOutcome::blocked('Jadwal pemesanan ini tidak ditemukan.');
        }

        $departureAt = Carbon::parse($schedule->departure_date->format('Y-m-d').' '.$schedule->departure_time);

        $isPendingOnline = $booking->status === BookingStatus::Pending->value
            && $booking->payment_method === 'midtrans'
            && $booking->payment_intent_id === null;

        if ($isPendingOnline) {
            return new CancellationOutcome(
                true,
                'pending_online',
                'Belum ada dana yang ditarik. Kursi akan dilepas untuk penumpang lain.',
            );
        }

        $isUnboardedCod = $booking->status === BookingStatus::Confirmed->value
            && $booking->payment_method === 'cod'
            && $schedule->status === 'published'
            && $departureAt->isFuture()
            && ! $booking->passengers()->whereNotNull('boarded_at')->exists();

        if ($isUnboardedCod) {
            return new CancellationOutcome(
                true,
                'cod_unboarded',
                'Pesanan COD belum dibayar. Jaminan kursi dilepas dan tidak ada tagihan yang tersisa.',
                releasesCodHold: true,
            );
        }

        if ($departureAt->isPast()) {
            return CancellationOutcome::blocked('Jadwal sudah berangkat; pembatalan tidak lagi dapat dilakukan sendiri.');
        }

        return CancellationOutcome::blocked('Pemesanan ini tidak dapat dibatalkan otomatis.');
    }
}

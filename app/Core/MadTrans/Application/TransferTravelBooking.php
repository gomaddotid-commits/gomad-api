<?php

namespace App\Core\MadTrans\Application;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Domain\LedgerMoney;
use App\Core\MadTrans\Domain\BookingStatus;
use App\Core\Payment\Domain\PaymentConflict;
use App\Models\PassengerTransfer;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TransferTravelBooking
{
    public function __construct(
        private readonly ActionAuthorizer $authorizer,
        private readonly SegmentSeatMap $seatMap,
    ) {}

    /**
     * Memindahkan satu pemesanan ke jadwal lain pada rute dan agency yang sama.
     *
     * Keputusan sadar: transfer hanya diizinkan bila tarif segmen tujuan sama
     * dengan tarif yang sudah dibayar, sehingga tidak ada uang yang berpindah
     * dan ledger tidak perlu diubah. Selisih harga belum didukung.
     */
    public function execute(User $actor, TravelBooking $booking, TravelSchedule $target, ?string $note = null): PassengerTransfer
    {
        $booking->loadMissing(['schedule.business', 'originStop', 'destinationStop', 'passengers']);
        $this->authorize($actor, $booking);

        return DB::transaction(function () use ($actor, $booking, $target, $note): PassengerTransfer {
            $lockedBooking = TravelBooking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();
            $current = TravelSchedule::query()->whereKey($lockedBooking->schedule_id)->lockForUpdate()->firstOrFail();
            $lockedTarget = TravelSchedule::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($lockedBooking->status, [BookingStatus::Confirmed->value, BookingStatus::Paid->value], true)) {
                throw new PaymentConflict('Hanya pesanan yang sudah dikonfirmasi atau dibayar dapat dipindahkan.');
            }
            if (in_array($current->status, ['in_progress', 'completed', 'cancelled'], true)) {
                throw new PaymentConflict('Jadwal asal sudah berjalan atau selesai sehingga tidak dapat dipindahkan.');
            }
            if ((int) $lockedTarget->getKey() === (int) $current->getKey()) {
                throw new InvalidArgumentException('Jadwal tujuan sama dengan jadwal asal.');
            }
            if ((int) $lockedTarget->business_id !== (int) $current->business_id) {
                throw new InvalidArgumentException('Transfer hanya dapat dilakukan dalam satu agency.');
            }
            if ((int) $lockedTarget->route_id !== (int) $current->route_id) {
                throw new InvalidArgumentException('Transfer hanya untuk rute yang sama.');
            }
            if ($lockedTarget->status !== 'published') {
                throw new InvalidArgumentException('Jadwal tujuan belum terbit.');
            }

            $targetDeparture = Carbon::parse(
                $lockedTarget->departure_date->format('Y-m-d').' '.$lockedTarget->departure_time,
            );
            if ($targetDeparture->isPast()) {
                throw new InvalidArgumentException('Jadwal tujuan sudah lewat.');
            }

            $lockedTarget->loadMissing(['stops', 'route.pricing']);
            $origin = $lockedTarget->stops->firstWhere('route_stop_id', $lockedBooking->origin_stop_id);
            $destination = $lockedTarget->stops->firstWhere('route_stop_id', $lockedBooking->destination_stop_id);

            if ($origin === null || $destination === null || (int) $origin->stop_order >= (int) $destination->stop_order) {
                throw new InvalidArgumentException('Jadwal tujuan tidak melayani segmen yang dipesan.');
            }

            $pricing = $lockedTarget->route->pricing
                ->where('is_active', true)
                ->first(fn ($price): bool => (int) $price->origin_stop_id === (int) $lockedBooking->origin_stop_id
                    && (int) $price->destination_stop_id === (int) $lockedBooking->destination_stop_id);

            if ($pricing === null) {
                throw new InvalidArgumentException('Tarif segmen pada jadwal tujuan tidak tersedia.');
            }

            $passengerCount = max(1, (int) $lockedBooking->total_passengers);
            $paidCents = LedgerMoney::from((string) $lockedBooking->base_price)->toCents();
            $targetCents = LedgerMoney::from((string) $pricing->price)->toCents();

            if ($paidCents !== $targetCents * $passengerCount) {
                throw new InvalidArgumentException(
                    'Harga jadwal tujuan berbeda dengan yang sudah dibayar; selisih harga belum didukung, perlu rekonsiliasi manual.',
                );
            }

            $seats = $lockedBooking->passengers
                ->pluck('seat_number')
                ->filter()
                ->values()
                ->all();

            if ($seats !== []) {
                $taken = $this->seatMap->takenSeats(
                    $lockedTarget,
                    (int) $origin->stop_order,
                    (int) $destination->stop_order,
                );
                $conflicts = array_values(array_intersect($seats, $taken));

                if ($conflicts !== []) {
                    throw new PaymentConflict('Kursi berikut sudah terisi di jadwal tujuan: '.implode(', ', $conflicts).'.');
                }
            }

            foreach ($lockedBooking->passengers as $passenger) {
                $passenger->forceFill([
                    'schedule_id' => $lockedTarget->getKey(),
                    'seat_released_at' => null,
                ])->save();
            }

            $lockedBooking->forceFill([
                'schedule_id' => $lockedTarget->getKey(),
                'route_pricing_id' => $pricing->getKey(),
                'expires_at' => $lockedBooking->payment_method === 'cod'
                    ? $targetDeparture
                    : $lockedBooking->expires_at,
            ])->save();

            return PassengerTransfer::query()->create([
                'booking_id' => $lockedBooking->getKey(),
                'from_schedule_id' => $current->getKey(),
                'to_schedule_id' => $lockedTarget->getKey(),
                'fee' => '0.00',
                'status' => 'approved',
                'approved_by' => $actor->getKey(),
                'note' => $note,
            ]);
        }, attempts: 3);
    }

    private function authorize(User $actor, TravelBooking $booking): void
    {
        if ((int) $booking->customer_id === (int) $actor->getKey()) {
            return;
        }

        try {
            $this->authorizer->authorize($actor, 'schedule.manage', $booking->schedule->business);
        } catch (AuthorizationException $exception) {
            throw new AuthorizationException('Hanya pemesan atau pengelola agency yang dapat memindahkan pesanan.');
        }
    }
}

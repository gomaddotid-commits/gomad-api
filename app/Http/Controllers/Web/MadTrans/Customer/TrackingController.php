<?php

namespace App\Http\Controllers\Web\MadTrans\Customer;

use App\Core\Access\Application\ActionAuthorizer;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\TravelBooking;
use App\Models\TravelSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pelacakan perjalanan tanpa data lokasi palsu.
 *
 * Aplikasi belum menerima posisi kendaraan, jadi halaman ini hanya menampilkan
 * fakta yang benar-benar tercatat: status jadwal, waktu berangkat/selesai,
 * armada & supir, serta penumpang mana yang sudah naik. Tidak ada peta yang
 * mengarang posisi.
 */
class TrackingController extends Controller
{
    public function booking(Request $request, TravelBooking $booking): View
    {
        abort_unless((int) $booking->customer_id === (int) $request->user()->getKey(), 404);

        $booking->load([
            'schedule.route:id,origin_city,destination_city',
            'schedule.vehicle:id,name,plate_number',
            'schedule.driverMembership.user:id,name,phone',
            'schedule.stops.routeStop:id,city_name,name,stop_order',
            'passengers',
        ]);

        $schedule = $booking->schedule;
        $passengers = $booking->passengers;

        return view('madtrans.customer.tracking', [
            'booking' => $booking,
            'schedule' => $schedule,
            'passengers' => $passengers,
            'boardedCount' => $passengers->whereNotNull('boarded_at')->count(),
            'noShowCount' => $passengers->whereNotNull('no_show_at')->count(),
            'timeline' => $this->bookingTimeline($booking),
        ]);
    }

    public function agency(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'schedule.manage', $business);

        $schedules = TravelSchedule::query()
            ->with([
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number',
                'driverMembership.user:id,name,phone',
            ])
            ->withCount([
                'passengers as boarded_count' => fn (Builder $query) => $query->whereNotNull('boarded_at'),
                'passengers as no_show_count' => fn (Builder $query) => $query->whereNotNull('no_show_at'),
                'passengers as passenger_count',
            ])
            ->where('business_id', $business->getKey())
            ->whereDate('departure_date', '>=', today()->subDay())
            ->whereDate('departure_date', '<=', today()->addDays(2))
            ->orderBy('departure_date')
            ->orderBy('departure_time')
            ->get();

        return view('madtrans.travel.tracking', [
            'business' => $business,
            'schedules' => $schedules,
        ]);
    }

    /**
     * @return list<array{label: string, at: string|null, done: bool}>
     */
    private function bookingTimeline(TravelBooking $booking): array
    {
        return [
            ['label' => 'Pesanan dibuat', 'at' => $booking->created_at?->toDateTimeString(), 'done' => true],
            ['label' => 'Pembayaran diterima', 'at' => $booking->paid_at?->toDateTimeString(), 'done' => $booking->paid_at !== null],
            ['label' => 'Perjalanan dimulai', 'at' => $booking->started_at?->toDateTimeString(), 'done' => $booking->started_at !== null],
            ['label' => 'Perjalanan selesai', 'at' => $booking->completed_at?->toDateTimeString(), 'done' => $booking->completed_at !== null],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\MadTrans\Travel;

use App\Http\Controllers\Controller;
use App\Http\Resources\DriverScheduleResource;
use App\Http\Resources\DriverTripResource;
use App\Models\BusinessMember;
use App\Models\TravelSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Tugas supir — pasangan API dari layar `/mad-trans/driver`.
 *
 * Sampai sekarang API hanya punya aksinya (boarding, no-show, mulai, selesai),
 * tanpa cara membaca tugas mana yang harus dijalankan. Aplikasi native butuh
 * dua bacaan ini sebelum aksi apa pun bisa ditampilkan:
 *
 * - `index` → daftar tugas aktif (published + in_progress) dan yang sudah
 *             selesai 7 hari terakhir
 * - `show`  → satu tugas beserta penumpangnya, siap untuk boarding
 *
 * Cakupan data sama persis dengan controller web supaya supir tidak melihat
 * dua kebenaran berbeda antara web dan ponsel.
 */
class DriverScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $membershipIds = $this->driverMemberships($request);

        $active = TravelSchedule::query()
            ->whereIn('driver_id', $membershipIds)
            ->whereIn('status', ['published', 'in_progress'])
            ->with([
                'business:id,name',
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number,capacity',
            ])
            ->withCount('bookings')
            ->orderBy('departure_date')
            ->orderBy('departure_time')
            ->limit(50)
            ->get();

        /*
        | Tugas selesai dipisah, bukan dicampur: pertanyaan pertama supir saat
        | ada sengketa adalah "perjalanan mana yang sudah saya tutup". Dibatasi
        | 7 hari terakhir supaya daftar tidak tumbuh selamanya — sama seperti web.
        */
        $completed = TravelSchedule::query()
            ->whereIn('driver_id', $membershipIds)
            ->where('status', 'completed')
            ->whereDate('departure_date', '>=', today()->subDays(7))
            ->with([
                'business:id,name',
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number,capacity',
            ])
            ->withCount('bookings')
            ->orderByDesc('departure_date')
            ->orderByDesc('departure_time')
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'active' => DriverScheduleResource::collection($active),
                'completed' => DriverScheduleResource::collection($completed),
            ],
        ]);
    }

    public function show(Request $request, TravelSchedule $schedule): JsonResponse
    {
        abort_unless($this->isAssigned($request, $schedule), 403);

        $schedule->load([
            'business:id,name',
            'route:id,origin_city,destination_city',
            'vehicle:id,name,plate_number,capacity',
            // Pesanan batal/kedaluwarsa sudah melepas kursinya — jangan dihitung
            // sebagai penumpang yang harus dinaikkan.
            'bookings' => fn ($query) => $query->whereNotIn('status', ['cancelled', 'expired'])->orderBy('id'),
            'bookings.passengers',
            'bookings.customer:id,phone',
        ]);

        return response()->json(['data' => new DriverTripResource($schedule)]);
    }

    /**
     * @return Collection<int, int>
     */
    private function driverMemberships(Request $request): Collection
    {
        return BusinessMember::query()
            ->where('user_id', $request->user()->getKey())
            ->where('role', 'driver')
            ->where('status', 'active')
            ->pluck('id');
    }

    private function isAssigned(Request $request, TravelSchedule $schedule): bool
    {
        if ($schedule->driver_id === null) {
            return false;
        }

        return BusinessMember::query()
            ->whereKey($schedule->driver_id)
            ->where('user_id', $request->user()->getKey())
            ->where('role', 'driver')
            ->where('status', 'active')
            ->exists();
    }
}

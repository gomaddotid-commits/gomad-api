<?php

namespace App\Http\Controllers\Web\Shared;

use App\Core\MadTrans\Application\SearchTravelSchedules;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\RoutePricing;
use App\Models\TravelRoute;
use App\Models\TravelSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Permukaan publik: pengunjung tanpa akun harus bisa melihat apa yang dijual
 * dan berapa harganya sebelum diminta mendaftar.
 *
 * Yang tampil di sini hanya data yang memang publik — jadwal terbit, harga segmen,
 * dan daftar mitra aktif. Pemesanan tetap di balik autentikasi, dan pencarian
 * memakai use case yang sama dengan halaman pelanggan (`SearchTravelSchedules`),
 * bukan query kedua yang bisa berbeda aturan.
 */
class PublicController extends Controller
{
    public function home(): View
    {
        $routes = TravelRoute::query()
            ->where('status', 'active')
            ->whereHas('business', fn ($query) => $query->where('status', 'active'))
            ->withMin(['pricing' => fn ($query) => $query->where('is_active', true)], 'price')
            ->orderBy('origin_city')
            ->limit(6)
            ->get(['id', 'business_id', 'origin_city', 'destination_city']);

        return view('public.home', [
            'routes' => $routes,
            'agencyCount' => Business::query()->where('type', 'agency')->where('status', 'active')->count(),
            'warungCount' => Business::query()->whereIn('type', ['warung', 'grosir'])->where('status', 'active')->count(),
            'scheduleCount' => TravelSchedule::query()
                ->whereIn('status', ['published', 'boarding'])
                ->whereDate('departure_date', '>=', today())
                ->count(),
        ]);
    }

    public function search(Request $request, SearchTravelSchedules $search): View
    {
        $filters = $request->validate([
            'origin_city' => ['nullable', 'string', 'max:120'],
            'destination_city' => ['nullable', 'string', 'max:120'],
            'departure_date' => ['nullable', 'date'],
            'passengers' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $schedules = collect();
        $error = null;
        $hasQuery = ! empty($filters['origin_city'])
            && ! empty($filters['destination_city'])
            && ! empty($filters['departure_date']);

        if ($hasQuery) {
            try {
                $schedules = $search->execute(
                    $filters['origin_city'],
                    $filters['destination_city'],
                    Carbon::parse($filters['departure_date'])->startOfDay(),
                    (int) ($filters['passengers'] ?? 1),
                );
            } catch (\Throwable $exception) {
                $error = $exception->getMessage();
            }
        }

        return view('public.search', [
            'schedules' => $schedules,
            'filters' => $filters,
            'error' => $error,
            'hasQuery' => $hasQuery,
        ]);
    }

    public function schedule(TravelSchedule $schedule): View
    {
        // Jadwal draf atau batal tidak boleh bocor ke publik.
        abort_unless(in_array($schedule->status, ['published', 'boarding'], true), 404);
        abort_unless($schedule->business?->status === 'active', 404);

        $schedule->loadMissing([
            'business:id,name,status',
            'route:id,origin_city,destination_city',
            'route.pricing' => fn ($query) => $query->where('is_active', true),
            'vehicle:id,name,seat_layout,capacity',
            'stops.routeStop',
        ]);

        $stops = $schedule->stops->sortBy('stop_order')->values();
        $pricing = $schedule->route?->pricing ?? collect();

        return view('public.schedule', [
            'schedule' => $schedule,
            'stops' => $stops,
            'pricing' => $pricing,
            'seats' => is_array($schedule->vehicle?->seat_layout) ? array_values($schedule->vehicle->seat_layout) : [],
        ]);
    }

    /**
     * Daftar mitra aktif — dipakai pengunjung yang ingin tahu siapa yang berjualan
     * di GoMad, dan calon mitra yang ingin melihat skala jaringan.
     */
    public function partners(): View
    {
        $agencies = Business::query()
            ->where('type', 'agency')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'verified_at']);

        $outlets = Business::query()
            ->whereIn('type', ['warung', 'grosir'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'verified_at']);

        return view('public.partners', [
            'agencies' => $agencies,
            'outlets' => $outlets,
        ]);
    }

    public function download(): View
    {
        return view('public.download');
    }

    /**
     * Katalog warung untuk pengunjung: nama outlet dan grosir yang aktif.
     * Harga & pemesanan tetap di halaman yang butuh akun.
     */
    public function warung(): View
    {
        $outlets = Business::query()
            ->whereIn('type', ['warung', 'grosir'])
            ->where('status', 'active')
            ->orderBy('type')
            ->orderBy('name')
            ->get(['id', 'type', 'name', 'verified_at']);

        $cheapest = RoutePricing::query()
            ->where('is_active', true)
            ->min('price');

        return view('public.warung', [
            'outlets' => $outlets,
            'cheapestFare' => $cheapest,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Web\MadTrans\Travel;

use App\Core\Access\Application\ActionAuthorizer;
use App\Core\Ledger\Application\BusinessWalletSummary;
use App\Core\Settlement\Application\SettlementBreakdown;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessPickupPolicy;
use App\Models\LedgerAccount;
use App\Models\PromoRedemption;
use App\Models\Rental;
use App\Models\TravelBooking;
use App\Models\TravelRoute;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Ruang kerja agency: satu halaman per pekerjaan, bukan satu halaman besar.
 *
 * Dasbor hanya menjawab "apa yang perlu saya lakukan hari ini"; armada, rute,
 * jadwal, pengemudi, pesanan, dompet, pencairan, dan laporan punya halamannya
 * sendiri supaya formulir dan tabel tidak saling berebut perhatian.
 *
 * Hak akses tetap satu pintu: `schedule.manage` pada agency ini. Yang berbeda
 * antar-halaman hanya data yang ditampilkan.
 */
class AgencyPortalController extends Controller
{
    public function dashboard(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        SettlementBreakdown $settlementBreakdown,
        BusinessWalletSummary $wallet,
    ): View {
        $this->guard($request, $business, $authorizer);

        $today = today();

        $schedules = $business->travelSchedules()
            ->with([
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number',
                'driverMembership.user:id,name',
            ])
            ->withCount('bookings')
            ->orderByDesc('departure_date')
            ->orderByDesc('departure_time')
            ->limit(50)
            ->get();

        $bookingsToday = TravelBooking::query()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
            ->whereDate('created_at', $today)
            ->count();

        $revenueToday = (string) TravelBooking::query()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
            ->whereDate('bookings.created_at', $today)
            ->whereIn('bookings.status', ['paid', 'confirmed', 'on_going', 'completed'])
            ->sum('base_price');

        return view('madtrans.travel.dashboard', [
            'business' => $business,
            'schedules' => $schedules,
            'bookingsToday' => $bookingsToday,
            'revenueToday' => $revenueToday,
            'pendingApproval' => $schedules->where('status', 'draft')->count(),
            'departingToday' => $schedules
                ->where('departure_date', '>=', $today)
                ->where('departure_date', '<=', $today->copy()->addDays(2))
                ->count(),
            'rentalsNeedingAction' => Rental::query()
                ->where('business_id', $business->getKey())
                ->whereIn('status', ['pending', 'paid', 'returned'])
                ->count(),
            'availableBalance' => $wallet->balance($business, 'AVAILABLE'),
            'collateralBalance' => $wallet->balance($business, 'COLLATERAL'),
            'payableBalance' => $wallet->balance($business, 'PAYABLE'),
            'settlement' => $settlementBreakdown->forBusiness($business),
        ]);
    }

    public function fleet(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        return view('madtrans.travel.fleet', [
            'business' => $business,
            'vehicles' => $business->vehicles()->orderBy('name')->get(),
            'activeSchedules' => $business->travelSchedules()
                ->whereIn('status', ['published', 'boarding', 'departed'])
                ->count(),
        ]);
    }

    public function routes(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        return view('madtrans.travel.routes', [
            'business' => $business,
            'routes' => $business->travelRoutes()
                ->with([
                    'stops:id,route_id,stop_order,city_name,name,latitude,longitude',
                    'pricing' => fn ($pricing) => $pricing->where('is_active', true),
                ])
                ->orderByDesc('id')
                ->get(),
            'pickupPolicy' => BusinessPickupPolicy::query()->where('business_id', $business->getKey())->first(),
            'stopNames' => $business->travelRoutes()
                ->with('stops:id,route_id,city_name,name')
                ->get()
                ->flatMap(fn (TravelRoute $route) => $route->stops)
                ->keyBy('id'),
        ]);
    }

    public function schedules(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        $schedules = $business->travelSchedules()
            ->with([
                'route:id,origin_city,destination_city',
                'vehicle:id,name,plate_number,seat_layout',
                'driverMembership.user:id,name',
            ])
            ->withCount('bookings')
            ->orderByDesc('departure_date')
            ->orderByDesc('departure_time')
            ->paginate(25);

        return view('madtrans.travel.schedules', [
            'business' => $business,
            'schedules' => $schedules,
            'routes' => $business->travelRoutes()->where('status', 'active')->orderBy('origin_city')->get(['id', 'origin_city', 'destination_city']),
            'vehicles' => $business->vehicles()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'plate_number', 'capacity']),
            'drivers' => $this->driverMembers($business),
        ]);
    }

    public function drivers(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        $drivers = $this->driverMembers($business);

        return view('madtrans.travel.drivers', [
            'business' => $business,
            'drivers' => $drivers,
            'assignments' => $business->travelSchedules()
                ->with(['route:id,origin_city,destination_city', 'driverMembership.user:id,name'])
                ->whereNotNull('driver_id')
                ->whereDate('departure_date', '>=', today())
                ->orderBy('departure_date')
                ->orderBy('departure_time')
                ->limit(30)
                ->get(),
            'members' => $business->members()->orderBy('name')->get(),
        ]);
    }

    public function bookings(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        $status = $request->string('status')->toString();
        $status = in_array($status, ['pending', 'paid', 'confirmed', 'cancelled', 'on_going', 'completed'], true)
            ? $status
            : null;

        $bookings = TravelBooking::query()
            ->with([
                'schedule.route:id,origin_city,destination_city',
                'schedule:id,route_id,departure_date,departure_time,business_id',
                'passengers',
            ])
            ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('madtrans.travel.bookings', [
            'business' => $business,
            'bookings' => $bookings,
            'status' => $status,
            'transferTargets' => $business->travelSchedules()
                ->where('status', 'published')
                ->whereDate('departure_date', '>=', today())
                ->orderBy('departure_date')
                ->orderBy('departure_time')
                ->limit(20)
                ->get(['id', 'departure_date', 'departure_time']),
            'counts' => TravelBooking::query()
                ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
        ]);
    }

    public function wallet(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        BusinessWalletSummary $wallet,
    ): View {
        $this->guard($request, $business, $authorizer);

        $holds = $wallet->activeHolds($business);
        $balances = $wallet->balances($business);

        return view('madtrans.travel.wallet', [
            'business' => $business,
            'availableBalance' => $balances['available'],
            'collateralBalance' => $balances['collateral'],
            'payableBalance' => $balances['payable'],
            'mutations' => $wallet->mutations($business),
            'accounts' => LedgerAccount::query()
                ->whereIn('code', $wallet->accountCodes($business))
                ->pluck('code', 'id'),
            'holds' => $holds,
            'holdsTotal' => $holds->sum(fn ($hold): float => (float) $hold->amount),
        ]);
    }

    public function withdrawals(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        BusinessWalletSummary $wallet,
    ): View {
        $this->guard($request, $business, $authorizer);

        return view('madtrans.travel.withdrawals', [
            'business' => $business,
            'withdrawals' => Withdrawal::query()
                ->where('business_id', $business->getKey())
                ->latest()
                ->paginate(20),
            'availableBalance' => $wallet->balance($business, 'AVAILABLE'),
            'minimum' => (string) config('services.withdrawal.minimum_amount', 100000),
            'fee' => (string) config('services.withdrawal.admin_fee', 5000),
        ]);
    }

    public function reports(
        Request $request,
        Business $business,
        ActionAuthorizer $authorizer,
        SettlementBreakdown $settlementBreakdown,
    ): View {
        $this->guard($request, $business, $authorizer);

        $since = today()->subDays(13);

        $daily = TravelBooking::query()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
            ->whereDate('bookings.created_at', '>=', $since)
            ->whereIn('bookings.status', ['paid', 'confirmed', 'on_going', 'completed'])
            ->select(
                DB::raw('date(bookings.created_at) as day'),
                DB::raw('count(*) as bookings'),
                DB::raw('sum(bookings.base_price) as gross'),
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $perRoute = TravelBooking::query()
            ->whereHas('schedule', fn (Builder $schedule) => $schedule->where('business_id', $business->getKey()))
            ->whereIn('bookings.status', ['paid', 'confirmed', 'on_going', 'completed'])
            ->join('schedules', 'schedules.id', '=', 'bookings.schedule_id')
            ->join('routes', 'routes.id', '=', 'schedules.route_id')
            ->select(
                'routes.origin_city',
                'routes.destination_city',
                DB::raw('count(*) as bookings'),
                DB::raw('sum(bookings.base_price) as gross'),
            )
            ->groupBy('routes.origin_city', 'routes.destination_city')
            ->orderByDesc('gross')
            ->get();

        return view('madtrans.travel.reports', [
            'business' => $business,
            'daily' => $daily,
            'perRoute' => $perRoute,
            'settlement' => $settlementBreakdown->forBusiness($business),
            'promoRedemptions' => PromoRedemption::query()
                ->with('promo:id,code,name')
                ->where('business_id', $business->getKey())
                ->latest()
                ->limit(10)
                ->get(),
            'promoDiscountTotal' => (string) PromoRedemption::query()
                ->where('business_id', $business->getKey())
                ->sum('discount_amount'),
            'promoBorneByPlatform' => (string) PromoRedemption::query()
                ->where('business_id', $business->getKey())
                ->where('cost_bearer', 'platform')
                ->sum('discount_amount'),
        ]);
    }

    public function rental(Request $request, Business $business, ActionAuthorizer $authorizer): View
    {
        $this->guard($request, $business, $authorizer);

        return view('madtrans.rental.agency', [
            'business' => $business,
            'rentals' => Rental::query()
                ->with(['vehicle:id,name,plate_number', 'customer:id,name,phone', 'driverMembership.user:id,name'])
                ->where('business_id', $business->getKey())
                ->latest()
                ->paginate(20),
            'rentableVehicles' => $business->vehicles()
                ->whereNotNull('rental_daily_price')
                ->orderBy('name')
                ->get(),
        ]);
    }

    private function driverMembers(Business $business)
    {
        return $business->members()
            ->wherePivot('role', 'driver')
            ->wherePivot('status', 'active')
            ->orderBy('name')
            ->get();
    }

    private function guard(Request $request, Business $business, ActionAuthorizer $authorizer): void
    {
        abort_unless($business->type === 'agency', 404);
        $authorizer->authorize($request->user(), 'schedule.manage', $business);
    }
}
